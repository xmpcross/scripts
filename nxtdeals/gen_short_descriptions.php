<?php
/**
 * nxt.deals — generate bullet-point short descriptions for products.
 *
 * For every active product whose description_short is EMPTY, derive >=5 concise
 * "key feature" bullets from the product name + long description via Claude, and
 * write them as an <ul> into description_short (all languages).
 *
 * Idempotent / resumable: only ever touches products with an empty short desc,
 * so re-running continues where it left off. Never overwrites existing content.
 *
 * Usage:
 *   php gen_short_descriptions.php --dry-run --limit 3      # preview, write nothing
 *   php gen_short_descriptions.php --limit 50               # write first 50
 *   php gen_short_descriptions.php --id 2163 --dry-run      # single product
 *   php gen_short_descriptions.php                          # all remaining
 * Flags: --limit N  --dry-run  --id X  --overwrite  --model NAME  --sleep MS
 */

require '/var/www/html/nxt.deals/config/config.inc.php';

// ---- config ----
$ENV_FILE = '/opt/strapi-cms-git/backend/ai-writer-cli/.env';
$API_KEY  = '';
foreach (@file($ENV_FILE) ?: [] as $line) {
    if (preg_match('/^ANTHROPIC_API_KEY=(.+)$/', trim($line), $m)) { $API_KEY = trim($m[1], "\"' "); break; }
}
if (!$API_KEY) { fwrite(STDERR, "FATAL: no ANTHROPIC_API_KEY in $ENV_FILE\n"); exit(1); }

// ---- args ----
$args = $argv;
$has  = fn($f) => in_array($f, $args, true);
$val  = function ($f, $d) use ($args) { $i = array_search($f, $args, true); return ($i !== false && isset($args[$i + 1])) ? $args[$i + 1] : $d; };

$DRY       = $has('--dry-run');
$LIMIT     = (int)$val('--limit', '0');
$ONLY_ID   = (int)$val('--id', '0');
$OVERWRITE = $has('--overwrite');
$MODEL     = $val('--model', 'claude-haiku-4-5-20251001');
$SLEEP_MS  = (int)$val('--sleep', '350');
$USE_CLI   = $has('--cli');                 // use local `claude` CLI (Max plan, no API cost)
$CLI_MODEL = $val('--cli-model', 'haiku');  // CLI model alias: haiku|sonnet|opus

$db  = Db::getInstance();
$P   = _DB_PREFIX_;
$langs = array_column($db->executeS("SELECT id_lang FROM {$P}lang WHERE active=1"), 'id_lang');
$idLangDefault = (int)Configuration::get('PS_LANG_DEFAULT');

// ---- select work ----
$CATEGORY = (int)$val('--category', '0');   // restrict to products in this category id
$where = $OVERWRITE ? "1" : "(pl.description_short IS NULL OR pl.description_short='')";
if ($ONLY_ID)  $where .= " AND p.id_product=" . $ONLY_ID;
if ($CATEGORY) $where .= " AND p.id_product IN (SELECT id_product FROM {$P}category_product WHERE id_category=" . $CATEGORY . ")";
$sql = "SELECT p.id_product, pl.name, pl.description
        FROM {$P}product p
        JOIN {$P}product_lang pl ON pl.id_product=p.id_product AND pl.id_lang={$idLangDefault}
        WHERE p.active=1 AND {$where}
        ORDER BY p.id_product DESC";
if ($LIMIT > 0) $sql .= " LIMIT " . $LIMIT;
$rows = $db->executeS($sql);

$total = count($rows);
echo ($DRY ? "DRY-RUN" : "WRITE") . " | model=$MODEL | products to process: $total | langs: " . implode(',', $langs) . "\n\n";
if (!$total) { echo "Nothing to do.\n"; exit(0); }

// ---- Anthropic call ----
function claude_bullets($apiKey, $model, $name, $longText) {
    $sys = "You write concise e-commerce product copy. Given a product name and its description, "
         . "respond with ONLY a JSON object (no markdown, no surrounding text) of the form "
         . "{\"intro\": string, \"bullets\": [{\"label\": string, \"text\": string}, ...]}. "
         . "'intro' is ONE short engaging paragraph (2-3 sentences, 30-55 words) summarising the product, "
         . "factual and benefit-led with no hype. "
         . "'bullets' is an array of 5 to 7 objects extracting the KEY product features. "
         . "'label' is a 2-4 word bold lead-in naming the feature (e.g. 'Advanced Camera', "
         . "'Powerful Battery', 'Large Display', 'High Performance', 'Ample Storage'). "
         . "'text' is ONE concise, factual sentence (10-25 words) describing that feature with specifics "
         . "(specs, materials, capabilities) — no marketing fluff, and do not repeat the label verbatim. "
         . "Always include the intro and at least 5 bullets.";
    $user = "Product name:\n{$name}\n\nDescription:\n" . mb_substr($longText, 0, 1800);
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 650,
        'system' => $sys,
        'messages' => [['role' => 'user', 'content' => $user]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
    ]);
    // retry on 429 / 5xx with backoff
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
    return parse_intro_bullets($text);
}

// Parse a {"intro":..,"bullets":[{label,text}]} JSON object out of model text.
function parse_intro_bullets($text) {
    if (preg_match('/\{.*\}/s', (string)$text, $mm)) $text = $mm[0];
    $obj = json_decode($text, true);
    if (!is_array($obj) || empty($obj['bullets']) || !is_array($obj['bullets'])) return null;
    $intro = trim(strip_tags((string)($obj['intro'] ?? '')));
    $out = [];
    foreach ($obj['bullets'] as $b) {
        if (!is_array($b)) continue;
        $label = trim(strip_tags((string)($b['label'] ?? '')));
        $body  = trim(strip_tags((string)($b['text'] ?? '')));
        if ($label === '' || $body === '') continue;
        $out[] = ['label' => $label, 'text' => $body];
    }
    return count($out) >= 3 ? ['intro' => $intro, 'bullets' => $out] : null;
}

// ---- same generation, but via the local `claude` CLI (Max plan, $0 API cost) ----
function claude_bullets_cli($cliModel, $name, $longText) {
    $sys = "You write concise e-commerce product copy. Given a product name and its description, "
         . "respond with ONLY a JSON object (no markdown, no surrounding text) of the form "
         . "{\"intro\": string, \"bullets\": [{\"label\": string, \"text\": string}, ...]}. "
         . "'intro' is ONE short engaging paragraph (2-3 sentences, 30-55 words) summarising the product, "
         . "factual and benefit-led with no hype. "
         . "'bullets' is an array of 5 to 7 objects extracting the KEY features. "
         . "'label' is a 2-4 word bold lead-in naming the feature. "
         . "'text' is ONE concise factual sentence (10-25 words) with specifics, no fluff. "
         . "Always include the intro and at least 5 bullets.";
    $user = "Product name:\n{$name}\n\nDescription:\n" . mb_substr($longText, 0, 1800);
    $prompt = $sys . "\n\n" . $user . "\n\nOutput ONLY the JSON object, nothing else.";

    $cmd = 'claude -p --output-format text --model ' . escapeshellarg($cliModel);
    $env = getenv();
    unset($env['ANTHROPIC_API_KEY']);   // force the Max OAuth login (free), not the metered API
    $env['HOME'] = '/root';             // claude reads creds from $HOME/.claude
    $spec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $spec, $pipes, '/root', $env);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], $prompt); fclose($pipes[0]);
    $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errs = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $data = parse_intro_bullets($text);
    if (!$data) { fwrite(STDERR, "  CLI parse fail: " . substr(trim((string)$errs ?: $text), 0, 160) . "\n"); return null; }
    return $data;
}

function bullets_to_html($data) {
    $intro = isset($data['intro']) ? trim((string)$data['intro']) : '';
    $list  = $data['bullets'] ?? [];
    $li = '';
    foreach ($list as $b) {
        $label = htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8');
        $body  = htmlspecialchars($b['text'], ENT_QUOTES, 'UTF-8');
        $li .= '<li><strong>' . $label . '</strong>: ' . $body . '</li>';
    }
    $p = $intro !== '' ? '<p class="nxt-desc-intro">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    return $p . '<ul class="nxt-key-features">' . $li . '</ul>';
}

// ---- main loop ----
$done = 0; $fail = 0;
foreach ($rows as $i => $r) {
    $id   = (int)$r['id_product'];
    $name = $r['name'];
    $long = trim(preg_replace('/\s+/', ' ', strip_tags($r['description'])));
    $tag  = sprintf("[%d/%d] #%d", $i + 1, $total, $id);

    if (mb_strlen($long) < 20) { echo "$tag SKIP (no source text) — {$name}\n"; continue; }

    $list = $USE_CLI
        ? claude_bullets_cli($CLI_MODEL, $name, $long)
        : claude_bullets($API_KEY, $MODEL, $name, $long);
    if (!$list) { echo "$tag FAIL (no bullets) — {$name}\n"; $fail++; continue; }
    $html = bullets_to_html($list);

    echo "$tag " . ($DRY ? "PREVIEW" : "WROTE") . " — " . mb_substr($name, 0, 60) . "\n";
    if (!empty($list['intro'])) echo "      ¶ {$list['intro']}\n";
    foreach ($list['bullets'] as $b) echo "      • {$b['label']}: {$b['text']}\n";

    if (!$DRY) {
        foreach ($langs as $idLang) {
            $db->update('product_lang', ['description_short' => pSQL($html, true)],
                'id_product=' . $id . ' AND id_lang=' . (int)$idLang);
        }
        $done++;
    } else {
        $done++;
    }
    usleep($SLEEP_MS * 1000);
}

echo "\n" . ($DRY ? "DRY-RUN complete" : "DONE") . " — processed: $done, failed: $fail\n";
if (!$DRY && $done) echo "Note: clear PS cache so combined product pages refresh.\n";
