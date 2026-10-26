<?php
// Offline proof of the mail entry in arch_project_info (ProjectMail): whether
// MAIL_FROM is set and valid, read from config.php as text and never run, the
// address itself never returned, and an honest null (never "true") whenever it
// cannot tell. Temp directories only, no network, no git.
//
//     php tests/unit/test-project-mail.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectMail.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectMail.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectMail.php';
require $ROOT.'/src/ProjectInfo.php';

use ArchMcp\ProjectInfo;
use ArchMcp\ProjectMail;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$base = realpath(sys_get_temp_dir()) . '/archmail' . getmypid();
mkdir($base, 0775, true);
$base = realpath($base);
$n = 0;
/** A checkout directory whose config.php has the given body (after the opening tag). */
function checkout(string $body): string {
    global $base, $n;
    $d = $base.'/c'.(++$n);
    mkdir($d, 0775, true);
    file_put_contents($d.'/config.php', "<?php\n".$body);

    return $d;
}

echo "MAIL_FROM set and valid\n";
$d = checkout("define('DB_ENGINE', 'sqlite');\ndefine('MAIL_FROM', 'noreply@crockart.com.au');\n");
$r = ProjectMail::forCheckout($d);
check('from_configured is true', true === $r['from_configured'] && !isset($r['note']));
check('the address is never returned', !str_contains(json_encode($r), 'noreply') && !str_contains(json_encode($r), 'crockart'));
check('fixed guidance mentions mail(), MAIL_FROM and config.php', str_contains($r['how_app_uses'], 'mail()') && str_contains($r['how_app_uses'], 'MAIL_FROM') && str_contains($r['how_app_uses'], 'config.php'));
check('guidance holds no path', !str_contains(json_encode($r, JSON_UNESCAPED_SLASHES), $base));
$d = checkout("define(\"MAIL_FROM\", \"hello@example.com.au\");\n");
check('double quotes, no spaces', true === ProjectMail::forCheckout($d)['from_configured']);
$d = checkout("    define('MAIL_FROM','x@example.org');\n");
check('indented define', true === ProjectMail::forCheckout($d)['from_configured']);
$d = checkout("# define('MAIL_FROM', 'bad');\n// define('MAIL_FROM', 'bad2');\ndefine('MAIL_FROM', 'ok@example.com.au');\n");
check('commented-out defines are skipped, the real one is read', true === ProjectMail::forCheckout($d)['from_configured']);

echo "\nMAIL_FROM absent or invalid: false, with a note\n";
$d = checkout("define('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', __DIR__ . '/data/m.sqlite');\n");
$r = ProjectMail::forCheckout($d);
check('not set: false and a note', false === $r['from_configured'] && str_contains($r['note'], 'not set'));
check('guidance is still given', isset($r['how_app_uses']));
$d = checkout("define('MAIL_FROM', 'not-an-email');\n");
$r = ProjectMail::forCheckout($d);
check('not an address: false, a note, and the value is not echoed', false === $r['from_configured'] && str_contains($r['note'], 'not a valid') && !str_contains(json_encode($r), 'not-an-email'));
$d = checkout("define('MAIL_FROM', '');\n");
check('empty string: false', false === ProjectMail::forCheckout($d)['from_configured']);
$d = checkout("// MAIL_FROM goes here once Portal sets it\ndefine('DB_ENGINE', 'sqlite');\n");
check('a comment that only mentions MAIL_FROM is not "cannot read": still false', false === ProjectMail::forCheckout($d)['from_configured']);
$d = checkout("// define('MAIL_FROM', 'only-in-a-comment@example.com');\n");
check('only a commented-out define: false, never true', false === ProjectMail::forCheckout($d)['from_configured']);

echo "\ncannot tell: null, never true\n";
foreach ([
    "const MAIL_FROM = 'a@example.com';",
    "define('MAIL_FROM', getenv('MAIL_FROM'));",
    "define('MAIL_FROM', 'a@' . 'example.com');",
    "define('MAIL_FROM', \$sender);",
    "define('MAIL_FROM', \"a@example.com\nBcc: x@example.com\");",
] as $line) {
    $d = checkout($line."\n");
    $r = ProjectMail::forCheckout($d);
    check('not a plain string: '.substr(str_replace("\n", ' ', $line), 0, 44), null === $r['from_configured'] && str_contains($r['note'], 'cannot be read safely'));
}

echo "\nmissing config.php\n";
mkdir($base.'/none', 0775, true);
$r = ProjectMail::forCheckout($base.'/none');
check('no config.php: null and a note, no path in it', null === $r['from_configured'] && str_contains($r['note'], 'no config.php') && str_contains($r['note'], 'Portal creates') && !str_contains(json_encode($r, JSON_UNESCAPED_SLASHES), $base));
check('empty directory name: null', null === ProjectMail::forCheckout('')['from_configured']);

echo "\nconfig.php is read, never run\n";
$marker = $base.'/ran-marker';
$d = checkout("file_put_contents('".$marker."', 'ran');\ndefine('MAIL_FROM', 'run@example.com');\n");
$r = ProjectMail::forCheckout($d);
check('its code did not execute', !is_file($marker));
check('and the entry is still right', true === $r['from_configured']);
check('the process has no MAIL_FROM constant defined by this read', !\defined('MAIL_FROM'));

echo "\nProjectInfo::describe(): one mail entry per environment\n";
$stg = checkout("define('MAIL_FROM', 'stg@example.com');\n");
$prd = checkout("define('DB_ENGINE', 'sqlite');\n");
$p = static fn (string $root): array => [
    'label' => 'p', 'root' => $root, 'lanes' => ['code' => '', 'core' => ''], 'session_tools' => true, 'write_extensions' => ['php'],
    'pull_allowed' => false, 'pull_branch' => 'main', 'push_allowed' => false, 'push_branch' => 'main',
    'db_apply_allowed' => false, 'dependency_manager' => 'none', 'profile_environment' => 'staging',
];
$r = ProjectInfo::describe('demo', 'production', $p($stg), $p($prd));
check('staging view says MAIL_FROM is configured', true === $r['staging']['mail']['from_configured']);
check('production view says it is not', false === $r['production']['mail']['from_configured']);
check('no address and no absolute path anywhere in the whole reply', !str_contains(json_encode($r), 'stg@example') && !str_contains(json_encode($r, JSON_UNESCAPED_SLASHES), $base));
$r = ProjectInfo::describe('demo', 'production', $p($base.'/none'), null);
check('a checkout with no config.php still answers', true === $r['success'] && null === $r['staging']['mail']['from_configured']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
