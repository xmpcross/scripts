<?php
/**
 * nxt.deals — generate a ONE-paragraph introduction for product categories &
 * sub-categories, via the local `claude` CLI (Claude Max plan, $0 API cost).
 *
 * Writes the paragraph into category_lang.description for every active language.
 * Only touches categories with an EMPTY description unless --overwrite.
 *
 * Usage:
 *   php gen_category_intros.php --dry-run            # preview, write nothing
 *   php gen_category_intros.php                      # generate + write
 *   php gen_category_intros.php --overwrite          # regenerate all
 *   php gen_category_intros.php --id 884             # single category
 * Flags: --limit N  --dry-run  --id X  --overwrite  --cli-model haiku|sonnet|opus
 */
require '/var/www/html/nxt.deals/config/config.inc.php';

$args = $argv;
$has = fn($f) => in_array($f, $args, true);
$val = function ($f, $d) use ($args) { $i = array_search($f, $args, true); return ($i !== false && isset($args[$i+1])) ? $args[$i+1] : $d; };
$DRY = $has('--dry-run'); $OVER = $has('--overwrite');
$ONLY = (int)$val('--id', '0'); $LIMIT = (int)$val('--limit', '0');
$CLI_MODEL = $val('--cli-model', 'sonnet');

$db = Db::getInstance(); $P = _DB_PREFIX_;
$langs = array_column($db->executeS("SELECT id_lang FROM {$P}lang WHERE active=1"), 'id_lang');
$idLangDef = (int)Configuration::get('PS_LANG_DEFAULT');

$where = $OVER ? "1" : "(cl.description IS NULL OR cl.description='')";
if ($ONLY) $where .= " AND c.id_category=" . $ONLY;
$sql = "SELECT c.id_category, cl.name, c.id_parent
        FROM {$P}category c
        JOIN {$P}category_lang cl ON cl.id_category=c.id_category AND cl.id_lang={$idLangDef}
        WHERE c.active=1 AND c.level_depth>=2 AND {$where}
        ORDER BY c.level_depth, c.id_category";
if ($LIMIT > 0) $sql .= " LIMIT " . $LIMIT;
$rows = $db->executeS($sql);
$total = count($rows);
echo ($DRY ? "DRY-RUN" : "WRITE") . " | model=$CLI_MODEL | categories: $total\n\n";
if (!$total) { echo "Nothing to do.\n"; exit(0); }

function cat_name($db, $P, $id, $lang) {
    return (string)$db->getValue("SELECT name FROM {$P}category_lang WHERE id_category=" . (int)$id . " AND id_lang=" . (int)$lang);
}
function child_names($db, $P, $id, $lang) {
    $r = $db->executeS("SELECT cl.name FROM {$P}category c JOIN {$P}category_lang cl ON cl.id_category=c.id_category AND cl.id_lang=" . (int)$lang . " WHERE c.id_parent=" . (int)$id . " AND c.active=1");
    return array_column($r ?: [], 'name');
}

function claude_intro($cliModel, $name, $parent, $children) {
    $sys = "You write informative e-commerce category-page introductions. Respond with EXACTLY 2 "
         . "paragraphs (about 110-150 words total) separated by a blank line — no markdown, no quotes, "
         . "no headings, no bullet lists. Factual and benefit-led, no hype or superlatives, British English. "
         . "First paragraph: what this category offers and who it suits. Second paragraph: the range and "
         . "types available (and sub-categories/brands if given) plus a key thing to consider when choosing. "
         . "Write naturally for a shopper landing on the page.";
    $ctx = "Category: {$name}";
    if ($parent) $ctx .= "\nParent category: {$parent}";
    if ($children) $ctx .= "\nSub-categories it contains: " . implode(', ', array_slice($children, 0, 12));
    $prompt = $sys . "\n\n" . $ctx . "\n\nOutput ONLY the paragraph.";

    $cmd = 'claude -p --output-format text --model ' . escapeshellarg($cliModel);
    $env = getenv(); unset($env['ANTHROPIC_API_KEY']); $env['HOME'] = '/root';
    $spec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $spec, $pipes, '/root', $env);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], $prompt); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $out = trim(strip_tags($out));
    $out = trim($out, "\"' \n\r\t");
    if (mb_strlen($out) < 30) { fwrite(STDERR, "  CLI weak/empty: " . substr(trim((string)($err ?: $out)),0,150) . "\n"); return null; }
    return $out;
}

$done = 0; $fail = 0;
foreach ($rows as $i => $r) {
    $id = (int)$r['id_category']; $name = $r['name'];
    $parent = cat_name($db, $P, (int)$r['id_parent'], $idLangDef);
    if (in_array(strtolower($parent), ['home','root',''])) $parent = '';
    $children = child_names($db, $P, $id, $idLangDef);
    $tag = sprintf("[%d/%d] #%d %s", $i+1, $total, $id, $name);

    $intro = claude_intro($CLI_MODEL, $name, $parent, $children);
    if (!$intro) { echo "$tag — FAIL\n"; $fail++; continue; }
    // split into paragraphs on blank lines, wrap each in <p>
    $paras = preg_split('/\n\s*\n/', trim($intro));
    $html = '';
    foreach ($paras as $p) {
        $p = trim(preg_replace('/\s+/', ' ', $p));
        if ($p !== '') $html .= '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    echo "$tag\n   $intro\n";
    if (!$DRY) {
        foreach ($langs as $l) {
            $db->update('category_lang', ['description' => pSQL($html, true)],
                'id_category=' . $id . ' AND id_lang=' . (int)$l);
        }
        $done++;
    } else { $done++; }
    usleep(200000);
}
echo "\n" . ($DRY ? "DRY-RUN complete" : "DONE") . " — processed: $done, failed: $fail\n";
if (!$DRY && $done) echo "Note: clear PS cache (redis + var/cache) so category pages refresh.\n";
