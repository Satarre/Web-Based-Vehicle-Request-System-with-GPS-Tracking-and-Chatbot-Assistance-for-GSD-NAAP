<?php
declare(strict_types=1);
/**
 * scripts/class_audit.php — flags CSS classes written directly in PHP HTML
 * that are neither compiled Tailwind utilities nor defined in app.css.
 *
 * Usage: php scripts/class_audit.php   (exit 1 = problems found)
 *
 * Only literal class="..." tokens OUTSIDE <?php ... ?> blocks are checked,
 * so PHP-built dynamic classes (badge-{status}, $btnPrimary, etc.) are
 * intentionally out of scope — review those manually when adding statuses.
 * Arbitrary Tailwind values (containing '[', e.g. px-[18px]) are allowed.
 */

$root = dirname(__DIR__);

$defined = [];
foreach (file($root . '/assets/css/app.css') as $line) {
    if (preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $line, $m)) {
        foreach ($m[1] as $cls) $defined[$cls] = true;
    }
}

// Compiled Tailwind utilities use escaped names like .gap-1\.5 and .hover\:bg-x.
$tw = file_get_contents($root . '/assets/css/tailwind.css');
if (preg_match_all('/\.((?:[a-zA-Z0-9_-]|\\\\[.:])+)/', $tw, $m)) {
    foreach ($m[1] as $cls) $defined[str_replace(['\\.', '\\:'], ['.', ':'], $cls)] = true;
}

foreach (['sm','md','lg','xl','2xl','max-lg','max-md','max-sm','max-xl','dark','in-progress'] as $c) $defined[$c] = true;
foreach (['html','body','a','h1','h2','h3','h4','p','input','select','textarea','label','button','table','thead','tbody','tr','th','td','span','div','img','svg','form','aside','nav','main','ul','li','section','figure','figcaption','iframe','option'] as $c) $defined[$c] = true;

// JS hook classes (no CSS of their own — queried by scripts).
foreach (['hero-slide', 'logo-main', 'logo-dark'] as $hook) $defined[$hook] = true;

$problems = [];
$rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rit as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $parts = explode('/', $rel);
    if (in_array('node_modules', $parts, true) || in_array('vendor', $parts, true)
        || in_array('.git', $parts, true) || in_array('tests', $parts, true)) continue;
    if (($file->getExtension() ?? '') !== 'php') continue;

    $src = file_get_contents($file->getPathname());

    // Strip PHP blocks so only literal HTML is scanned.
    $html = preg_replace('/<\?(?:php|=).*?\?>/s', '', $src);
    if (!preg_match_all('/class="([^"]+)"/', $html, $matches)) continue;
    foreach ($matches[1] as $list) {
        foreach (preg_split('/\s+/', $list) as $cls) {
            if ($cls === '') continue;
            if (str_contains($cls, '[')) continue;   // arbitrary Tailwind value
            if (str_ends_with($cls, '-')) continue;  // dynamic prefix like badge-STATUS
            if (isset($defined[$cls])) continue;
            $problems[] = $rel . ': .' . $cls;
        }
    }
}

$problems = array_values(array_unique($problems));
sort($problems);
foreach ($problems as $p) echo $p, "\n";
echo count($problems) ? 'FAIL: ' . count($problems) . " undefined class(es)\n" : "OK: all classes defined\n";
exit(count($problems) ? 1 : 0);