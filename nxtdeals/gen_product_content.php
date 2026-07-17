<?php
/**
 * nxt.deals — rich short descriptions for the product-information block.
 *
 * For each active product, write description_short as >=3 prose paragraphs plus a
 * few key-feature bullets, derived from the product name + long description via Claude.
 *
 * Idempotent over EMPTY by default; pass --overwrite to redo all.
 *
 * Usage:
 *   php gen_product_content.php --dry-run --limit 3        # preview, write nothing
 *   php gen_product_content.php --overwrite                # redo all active products
 *   php gen_product_content.php --id 2164 --dry-run        # single product
 * Flags: --limit N  --dry-run  --id X  --overwrite  --model NAME  --sleep MS
 */

require '/var/www/html/nxt.deals/config/config.inc.php';

$ENV_FILE = '/opt/strapi-cms-git/backend/ai-writer-cli/.env';
$API_KEY  = '';
foreach (@file($ENV_FILE) ?: [] as $line) {
    if (preg_match('/^ANTHROPIC_API_KEY=(.+)$/', trim($line), $m)) { $API_KEY = trim($m[1], "\"' "); break; }
}
if (!$API_KEY) { fwrite(STDERR, "FATAL: no ANTHROPIC_API_KEY in $ENV_FILE\n"); exit(1); }

$args = $argv;
$has  = fn($f) => in_array($f, $args, true);
$val  = function ($f, $d) use ($args) { $i = array_search($f, $args, true); return ($i !== false && isset($args[$i + 1])) ? $args[$i + 1] : $d; };

$DRY       = $has('--dry-run');
$LIMIT     = (int)$val('--limit', '0');
$ONLY_ID   = (int)$val('--id', '0');
$OVERWRITE = $has('--overwrite');
$MODEL     = $val('--model', 'claude-haiku-4-5-20251001');
$SLEEP_MS  = (int)$val('--sleep', '350');

$db  = Db::getInstance();
$P   = _DB_PREFIX_;
$langs = array_column($db->executeS("SELECT id_lang FROM {$P}lang WHERE active=1"), 'id_lang');
$idLangDefault = (int)Configuration::get('PS_LANG_DEFAULT');

$where = $OVERWRITE ? "1" : "(pl.description_short IS NULL OR pl.description_short='')";
if ($ONLY_ID) $where .= " AND p.id_product=" . $ONLY_ID;
$sql = "SELECT p.id_product, pl.name, pl.description
        FROM {$P}product p
        JOIN {$P}product_lang pl ON pl.id_product=p.id_product AND pl.id_lang={$idLangDefault}
        WHERE p.active=1 AND {$where}
        ORDER BY p.id_product DESC";
if ($LIMIT > 0) $sql .= " LIMIT " . $LIMIT;
$rows = $db->executeS($sql);

$total = count($rows);
echo ($DRY ? "DRY-RUN" : "WRITE") . " | model=$MODEL | products: $total | langs: " . implode(',', $langs) . "\n\n";
if (!$total) { echo "Nothing to do.\n"; exit(0); }

function claude_content($apiKey, $model, $name, $longText) {
    $sys = "You write concise, factual e-commerce product descriptions. Given a product name and its "
         . "source description, respond with ONLY a JSON object (no markdown, no surrounding text): "
         . "{\"description\": string, \"bullets\": [{\"label\": string, \"text\": string}, ...]}. "
         . "'description' is a short intro of 2 to 3 sentences of flowing prose (what the product is and "
         . "its main benefit) — no bullet points inside it. 'bullets' = 3 to 5 key-feature points; each "
         . "'label' is a 2-4 word bold lead-in (e.g. 'Advanced Camera', 'Long Battery Life') and 'text' is "
         . "one concise factual sentence. Base everything strictly on the source — never invent "
         . "specifications or numbers.";
    $user = "Product name:\n{$name}\n\nSource description:\n" . mb_substr($longText, 0, 2200);
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 700,
        'system' => $sys,
        'messages' => [['role' => 'user', 'content' => $user]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
        CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 90,
    ]);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code == 200) break;
        if ($code == 429 || $code >= 500) { sleep($attempt * 3); continue; }
        fwrite(STDERR, "  API error $code: " . substr((string)$resp, 0, 200) . "\n");
        curl_close($ch); return null;
    }
    curl_close($ch);
    if (!isset($resp) || $code != 200) return null;
    $j = json_decode($resp, true);
    $text = $j['content'][0]['text'] ?? '';
    if (preg_match('/\{.*\}/s', $text, $mm)) $text = $mm[0];
    $data = json_decode($text, true);
    if (!is_array($data)) return null;

    $desc = trim(strip_tags((string)($data['description'] ?? '')));
    if ($desc === '') return null;
    $sentences = preg_match_all('/[.!?](\s|$)/u', $desc);
    if ($sentences < 2) return null;
    $bullets = [];
    foreach (($data['bullets'] ?? []) as $b) {
        if (!is_array($b)) continue;
        $label = trim(strip_tags((string)($b['label'] ?? '')));
        $body  = trim(strip_tags((string)($b['text'] ?? '')));
        if ($label !== '' && $body !== '') $bullets[] = ['label' => $label, 'text' => $body];
    }
    if (count($bullets) < 2) return null;
    return ['description' => $desc, 'bullets' => $bullets];
}

function content_to_html($data) {
    $h = '<p>' . htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8') . '</p>';
    $li = '';
    foreach ($data['bullets'] as $b) {
        $li .= '<li><strong>' . htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') . '</strong>: '
             . htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8') . '</li>';
    }
    if ($li) $h .= '<ul class="nxt-key-features">' . $li . '</ul>';
    return $h;
}

$done = 0; $fail = 0;
foreach ($rows as $i => $r) {
    $id   = (int)$r['id_product'];
    $name = $r['name'];
    $long = trim(preg_replace('/\s+/', ' ', strip_tags($r['description'])));
    $tag  = sprintf("[%d/%d] #%d", $i + 1, $total, $id);

    if (mb_strlen($long) < 20) { echo "$tag SKIP (no source text) — {$name}\n"; continue; }

    $data = claude_content($API_KEY, $MODEL, $name, $long);
    if (!$data) { echo "$tag FAIL (insufficient content) — {$name}\n"; $fail++; continue; }
    $html = content_to_html($data);

    echo "$tag " . ($DRY ? "PREVIEW" : "WROTE") . " — " . mb_substr($name, 0, 55) . "\n";
    if ($DRY) {
        echo "      " . $data['description'] . "\n";
        foreach ($data['bullets'] as $b) echo "      • {$b['label']}: {$b['text']}\n";
    }

    if (!$DRY) {
        foreach ($langs as $idLang) {
            $db->update('product_lang', ['description_short' => pSQL($html, true)],
                'id_product=' . $id . ' AND id_lang=' . (int)$idLang);
        }
    }
    $done++;
    usleep($SLEEP_MS * 1000);
}

echo "\n" . ($DRY ? "DRY-RUN complete" : "DONE") . " — processed: $done, failed: $fail\n";
if (!$DRY && $done) echo "Note: clear PS cache so product pages refresh.\n";
