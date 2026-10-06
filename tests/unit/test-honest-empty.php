<?php
// Offline proof that empty lane listings say WHY they are empty, and that a
// genuinely empty-but-valid lane stays a plain empty success.
//
//     php tests/unit/test-honest-empty.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ArchTools.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ArchTools.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}

if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (is_file($ROOT.'/src/ArchConfig.php')) {
    require $ROOT.'/src/ArchConfig.php';
} elseif (!class_exists('ArchMcp\\ArchConfig')) {
    eval('namespace ArchMcp; final class ArchConfig { public const REPO_PATH = "/nonexistent"; }');
}
require $ROOT.'/src/ArchTools.php';
$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function tools(object $logger, string $root, array $lanes): ArchMcp\ArchTools {
    return new ArchMcp\ArchTools($logger, ['label' => 't', 'root' => $root, 'lanes' => $lanes, 'session_tools' => true]);
}
function explained(array $r): bool {
    return true === $r['success'] && [] === $r['files'] && isset($r['note']) && '' !== $r['note'];
}

$base = sys_get_temp_dir().'/archempty'.getmypid();
mkdir("$base/plain", 0775, true);
mkdir("$base/gated/src", 0775, true);
file_put_contents("$base/gated/src/a.php", '<?php');
file_put_contents("$base/gated/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src']]]]));

echo "Missing lane\n";
$noLanes = tools($logger, "$base/plain", ['metadata' => 'metadata']);
$r = $noLanes->archCodeListFiles();
check('code list: no code lane is explained', explained($r) && str_contains($r['note'], "no 'code' lane"));
$r = $noLanes->archSiteListFiles();
check('site list: no site lane is explained', explained($r) && str_contains($r['note'], "no 'site' lane"));
$r = $noLanes->archAssetsListFiles();
check('assets list: no assets lane is explained', explained($r) && str_contains($r['note'], "no 'assets' lane"));
$r = tools($logger, "$base/plain", ['site' => 'site'])->archSessionListFiles();
check('session list: no metadata lane is explained', explained($r) && str_contains($r['note'], "no 'metadata' lane"));

echo "\nLane present but directory missing\n";
$r = tools($logger, "$base/plain", ['site' => 'site'])->archSiteListFiles();
check('site list: missing directory is explained', explained($r) && str_contains($r['note'], 'does not exist'));

echo "\nCode lane config problems\n";
$r = tools($logger, "$base/plain", ['code' => ''])->archCodeListFiles();
check('code list: missing arch-gate.json is explained', explained($r) && str_contains($r['note'], 'arch-gate.json not found'));
file_put_contents("$base/plain/arch-gate.json", '{not json');
$r = tools($logger, "$base/plain", ['code' => ''])->archCodeListFiles();
check('code list: invalid arch-gate.json is explained', explained($r) && str_contains($r['note'], 'not valid JSON'));
file_put_contents("$base/plain/arch-gate.json", '{"code":{}}');
$r = tools($logger, "$base/plain", ['code' => ''])->archCodeListFiles();
check('code list: no stage.paths is explained', explained($r) && str_contains($r['note'], 'no code.stage.paths'));
file_put_contents("$base/plain/arch-gate.json", '{"code":{"stage":{"paths":["src"]}}}');
$r = tools($logger, "$base/plain", ['code' => ''])->archCodeListFiles();
check('code list: stage paths with no files is explained', explained($r) && str_contains($r['note'], 'contain any files'));

echo "\nHealthy listings stay plain\n";
$r = tools($logger, "$base/gated", ['code' => ''])->archCodeListFiles();
check('code list: files returned', true === $r['success'] && ['src/a.php'] === $r['files']);
check('code list: no note when files exist', !isset($r['note']));
mkdir("$base/plain/site", 0775, true);
$r = tools($logger, "$base/plain", ['site' => 'site'])->archSiteListFiles();
check('site list: genuinely empty lane has no note', true === $r['success'] && [] === $r['files'] && !isset($r['note']));

echo "\nCode path errors\n";
$r = $noLanes->archCodeReadFile('src/a.php');
check('read without code lane names the missing lane', false === $r['success'] && str_contains($r['error'], "no 'code' lane"));
$r = $noLanes->archCodeWriteFile('src/a.php', 'x');
check('write without code lane: still refused', false === $r['success']);
$r = tools($logger, "$base/gated", ['code' => ''])->archCodeReadFile('../x.php');
check('read outside lane keeps the generic wording', false === $r['success'] && str_contains($r['error'], 'must stay inside'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
