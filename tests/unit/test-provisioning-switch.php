<?php
// Offline proof of the switch that registers the chat provisioning tools
// (ProvisioningSwitch, ARCH-74): on only when the file sets the environment's
// key to exactly true; a missing, unreadable or odd file means off, so the tools
// can never appear by accident. Uses a temporary file only. No network.
//
//     php tests/unit/test-provisioning-switch.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProvisioningSwitch.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProvisioningSwitch.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProvisioningSwitch.php';

use ArchMcp\ProvisioningSwitch;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "parse()\n";
check('staging on, production off: each environment reads its own key', true === ProvisioningSwitch::parse('{"staging": true, "production": false}', 'staging') && false === ProvisioningSwitch::parse('{"staging": true, "production": false}', 'production'));
check('production on', true === ProvisioningSwitch::parse('{"production": true}', 'production'));
check('a missing key is off', false === ProvisioningSwitch::parse('{"staging": true}', 'production'));
foreach ([
    ['{"production": "true"}', 'the string "true"'],
    ['{"production": 1}', 'the number 1'],
    ['{"production": "yes"}', 'the string "yes"'],
    ['{"production": null}', 'null'],
    ['{"production": [true]}', 'a list'],
    ['{"production": false}', 'false'],
    ['[]', 'an empty list'],
    ['[true]', 'a list of true'],
    ['true', 'a bare true'],
    ['"production"', 'a bare string'],
    ['not json', 'text that is not JSON'],
    ['', 'an empty string'],
    ['{"production": true', 'truncated JSON'],
] as [$json, $label]) {
    check("$label: off", false === ProvisioningSwitch::parse($json, 'production'));
}
check('no file text (null): off', false === ProvisioningSwitch::parse(null, 'production'));
check('an unknown environment name is off', false === ProvisioningSwitch::parse('{"production": true}', 'dev'));

echo "\nenabled(): the file\n";
$dir = sys_get_temp_dir().'/arch-switch-test-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);
$file = $dir.'/provisioning-tools.json';
check('no file: off', false === ProvisioningSwitch::enabled('production', $file) && false === ProvisioningSwitch::enabled('staging', $file));
file_put_contents($file, '{"staging": true, "production": false}');
check('file read: staging on, production off', true === ProvisioningSwitch::enabled('staging', $file) && false === ProvisioningSwitch::enabled('production', $file));
file_put_contents($file, '{"staging": true, "production": true}');
check('both on', true === ProvisioningSwitch::enabled('production', $file));
file_put_contents($file, 'garbage');
check('a broken file turns everything off', false === ProvisioningSwitch::enabled('staging', $file) && false === ProvisioningSwitch::enabled('production', $file));
unlink($file);
mkdir($file);
check('a path that is a directory is off, not an error', false === ProvisioningSwitch::enabled('production', $file));
rmdir($file);
rmdir($dir);
check('the default path is not the temporary one (a developer machine has no such file: off)', \is_bool(ProvisioningSwitch::enabled('production')));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
