<?php
/**
 * Fetch brand logos (Google favicon service) for PrestaShop manufacturers that
 * are missing a logo, using the brand website domain from the BigBuy GPSR data.
 *
 * Input: /tmp/logo_fetch.tsv  (id_manufacturer \t domain \t name)
 * Output: img/m/{id}.jpg + {id}-{type}.jpg thumbnails (manufacturer logo).
 *
 * Usage:
 *   php fetch_brand_logos.php --dry --limit 5     # show what it would do
 *   php fetch_brand_logos.php --limit 10          # do the first 10
 *   php fetch_brand_logos.php                      # all
 * Flags: --limit N  --dry  --overwrite (refetch even if a logo already exists)
 */
require '/var/www/html/nxt.deals/config/config.inc.php';

$args = $argv;
$has = fn($f) => in_array($f, $args, true);
$val = function ($f, $d) use ($args) { $i = array_search($f, $args, true); return ($i !== false && isset($args[$i + 1])) ? $args[$i + 1] : $d; };
$DRY = $has('--dry');
$LIMIT = (int) $val('--limit', '0');
$OVERWRITE = $has('--overwrite');

$rows = @file('/tmp/logo_fetch.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$types = ImageType::getImagesTypes('manufacturers');
$dir = rtrim(_PS_MANU_IMG_DIR_, '/') . '/';

function favicon($domain) {
    $ch = curl_init("https://www.google.com/s2/favicons?domain=" . urlencode($domain) . "&sz=256");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code == 200 && $data) ? $data : null;
}

$ok = 0; $fail = 0; $skip = 0; $done = 0;
foreach ($rows as $line) {
    if ($LIMIT && $done >= $LIMIT) break;
    $p = explode("\t", $line);
    if (count($p) < 3) continue;
    [$id, $domain, $name] = [(int) $p[0], trim($p[1]), trim($p[2])];
    $orig = $dir . $id . '.jpg';
    if (!$OVERWRITE && file_exists($orig)) { $skip++; continue; }
    $done++;

    $data = favicon($domain);
    if (!$data) { echo "FAIL fetch  $name ($domain)\n"; $fail++; continue; }
    $src = @imagecreatefromstring($data);
    if (!$src) { echo "FAIL decode $name ($domain)\n"; $fail++; continue; }
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 24 || $h < 24) { echo "SKIP tiny  $name ($domain) {$w}x{$h}\n"; imagedestroy($src); $fail++; continue; }

    echo ($DRY ? "DRY  " : "OK   ") . "$name ($domain) {$w}x{$h}\n";
    if ($DRY) { imagedestroy($src); $ok++; continue; }

    // composite onto white (favicons may have alpha) and save as JPEG
    $canvas = imagecreatetruecolor($w, $h);
    imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
    imagejpeg($canvas, $orig, 90);
    imagedestroy($src); imagedestroy($canvas);
    @chmod($orig, 0644);

    // PS thumbnails
    foreach ($types as $t) {
        ImageManager::resize($orig, $dir . $id . '-' . $t['name'] . '.jpg', (int) $t['width'], (int) $t['height']);
    }
    $ok++;
}
echo "\n" . ($DRY ? '[DRY] ' : '') . "ok=$ok fail=$fail skipped(existing)=$skip\n";
