<?php
// Offline proof of the binding mode switch (BindingMode): only an exact
// {"mode":"enforce"} turns blocking on; a missing, broken or unexpected file
// always means log-only. Uses a temp file; no network, no secrets.
//
//     php tests/unit/test-binding-mode.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BindingMode.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BindingMode.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BindingMode.php';

use ArchMcp\BindingMode;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "parse()\n";
check('exact enforce', 'enforce' === BindingMode::parse('{"mode": "enforce"}'));
check('explicit log', 'log' === BindingMode::parse('{"mode": "log"}'));
check('null (no file) is log', 'log' === BindingMode::parse(null));
check('empty text is log', 'log' === BindingMode::parse(''));
check('not JSON is log', 'log' === BindingMode::parse('enforce'));
check('wrong case is log', 'log' === BindingMode::parse('{"mode": "ENFORCE"}'));
check('unknown value is log', 'log' === BindingMode::parse('{"mode": "strict"}'));
check('missing key is log', 'log' === BindingMode::parse('{"other": "enforce"}'));
check('a bare JSON string is log', 'log' === BindingMode::parse('"enforce"'));
check('a JSON list is log', 'log' === BindingMode::parse('["enforce"]'));
check('mode of the wrong type is log', 'log' === BindingMode::parse('{"mode": true}'));

echo "\nload()\n";
$dir = sys_get_temp_dir().'/archmode'.getmypid();
mkdir($dir, 0775, true);
check('missing file is log', 'log' === BindingMode::load("$dir/none.json"));
file_put_contents("$dir/on.json", '{"mode":"enforce"}');
check('file saying enforce is enforce', 'enforce' === BindingMode::load("$dir/on.json"));
file_put_contents("$dir/bad.json", '{oops');
check('broken file is log', 'log' === BindingMode::load("$dir/bad.json"));
check('a directory where the file should be is log', 'log' === BindingMode::load($dir));
check('constants are the strings the file uses', 'log' === BindingMode::LOG && 'enforce' === BindingMode::ENFORCE);

exec('rm -rf '.escapeshellarg($dir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
