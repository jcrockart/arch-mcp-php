<?php
// Offline proof of the pure rules for cross-project reference reads
// (RefGuard, ARCH-80). No files, no git, no network.
//
//     php tests/unit/test-ref-guard.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/RefGuard.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/RefGuard.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require_once $ROOT.'/src/RefGuard.php';

use ArchMcp\RefGuard;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "safePath()\n";
check('plain relative path ok', RefGuard::safePath('src/a.php'));
check('top-level file ok', RefGuard::safePath('README.md'));
check('empty refused', !RefGuard::safePath(''));
check('traversal refused', !RefGuard::safePath('../x') && !RefGuard::safePath('src/../x'));
check('absolute refused', !RefGuard::safePath('/etc/passwd'));
check('empty part refused', !RefGuard::safePath('src//a.php'));
check('dot part refused', !RefGuard::safePath('./a.php'));
check('backslash refused', !RefGuard::safePath('src\\a.php'));
check('NUL refused', !RefGuard::safePath("src/a\0.php"));

echo "\nisExcluded()\n";
check('config.php excluded', RefGuard::isExcluded('config.php') && RefGuard::isExcluded('src/config.php'));
check('config variants excluded', RefGuard::isExcluded('config.local.php') && RefGuard::isExcluded('src/config-sample.php'));
check('ordinary class with Config in its name is not excluded', !RefGuard::isExcluded('src/ArchConfig.php') && !RefGuard::isExcluded('src/ConfigLoader.php'));
check('dot files and dot folders excluded', RefGuard::isExcluded('.env') && RefGuard::isExcluded('src/.hidden/a.php') && RefGuard::isExcluded('src/.htaccess'));
check('vendor and node_modules excluded', RefGuard::isExcluded('vendor/x/a.php') && RefGuard::isExcluded('src/node_modules/a.js'));
check('unlisted types excluded (logs, images, locks)', RefGuard::isExcluded('error_log') && RefGuard::isExcluded('public/logo.png') && RefGuard::isExcluded('composer.lock'));
check('secret-like names excluded', RefGuard::isExcluded('metadata/tokens.json') && RefGuard::isExcluded('profiles.json') && RefGuard::isExcluded('src/PasswordReset.php') && RefGuard::isExcluded('src/secrets.php'));
check('normal code is readable', !RefGuard::isExcluded('src/Login.php') && !RefGuard::isExcluded('README.md') && !RefGuard::isExcluded('arch-gate.json') && !RefGuard::isExcluded('public/app.js'));

echo "\ninStagePaths() and readable()\n";
$stage = ['src', 'README.md', '/tests/'];
check('file under a stage folder', RefGuard::inStagePaths('src/a.php', $stage));
check('a stage file itself', RefGuard::inStagePaths('README.md', $stage));
check('slashes around a stage path are ignored', RefGuard::inStagePaths('tests/x.php', $stage));
check('a folder that only starts with the same letters is not inside', !RefGuard::inStagePaths('srcx/a.php', $stage));
check('outside every stage path', !RefGuard::inStagePaths('other/o.php', $stage));
check('empty stage list allows nothing', !RefGuard::inStagePaths('src/a.php', []));
check('readable needs all three rules', RefGuard::readable('src/a.php', $stage) && !RefGuard::readable('src/config.php', $stage) && !RefGuard::readable('other/o.php', $stage) && !RefGuard::readable('src/../README.md', $stage));

echo "\nlooksSecret(), display() and redactContent()\n";
check('quoted literal on a password variable', RefGuard::looksSecret('$password = \'hunter2hunter2\';'));
check('array key style', RefGuard::looksSecret("'api_key' => 'abcdef123456',"));
check('bearer in a header-like pair', RefGuard::looksSecret("bearer: 'abcdefghij'"));
check('reading a value from the environment is not flagged', !RefGuard::looksSecret('$token = getenv(\'X\');'));
check('a function named token is not flagged', !RefGuard::looksSecret('function token() { return 1; }'));
check('a very short literal is not flagged', !RefGuard::looksSecret('password: "abc"'));
check('display withholds a secret-like line', RefGuard::WITHHELD === RefGuard::display('$secret = "abcdefgh";'));
check('display trims a carriage return', 'line' === RefGuard::display("line\r"));
$long = RefGuard::display(str_repeat('z', 1000));
check('display cuts a long line', 301 === mb_strlen($long) && str_ends_with($long, '…'));
$red = RefGuard::redactContent("a\n\$secret = 'abcdefgh';\nb");
$lines = explode("\n", $red);
check('redactContent keeps the line count and withholds only the secret line', 3 === \count($lines) && 'a' === $lines[0] && RefGuard::WITHHELD === $lines[1] && 'b' === $lines[2]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
