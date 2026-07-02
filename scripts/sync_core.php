<?php
/**
 * Copy the generated openapi-generator PHP core (its `lib/`) into this repo's
 * `lib/`. Source: selectwin-sdks/sdks/php/lib (override with SELECTWIN_SDKS).
 * The core is committed (vendored generated layer, namespace `Selectwin\`); the
 * DX shell lives in `src/` (same namespace, separate PSR-4 directory).
 *
 * Run: php scripts/sync_core.php
 */

$repo = dirname(__DIR__);
$sdks = getenv('SELECTWIN_SDKS') ?: dirname($repo) . '/selectwin-sdks';
$src = $sdks . '/sdks/php/lib';
$dest = $repo . '/lib';

if (!is_dir($src)) {
    fwrite(STDERR, "generated core not found: $src (set SELECTWIN_SDKS)\n");
    exit(1);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}

function rcopy(string $src, string $dest): int {
    @mkdir($dest, 0777, true);
    $n = 0;
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        $s = "$src/$f"; $d = "$dest/$f";
        if (is_dir($s)) { $n += rcopy($s, $d); }
        else { copy($s, $d); $n++; }
    }
    return $n;
}

rrmdir($dest);
$n = rcopy($src, $dest);
echo "synced core -> lib/ ($n files)\n";
