<?php
// Local proof for the token-scoping draft. Does NOT touch the live server.
require __DIR__.'/src/ArchProfiles.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

// --- token extraction -------------------------------------------------
echo "Token extraction\n";
$T = str_repeat('a', 64);
check('valid /t/<token>/ extracts',        ArchMcp\ArchProfiles::extractToken("/t/$T/") === $T);
check('valid /t/<token> (no slash)',       ArchMcp\ArchProfiles::extractToken("/t/$T") === $T);
check('query string ignored',              ArchMcp\ArchProfiles::extractToken("/t/$T/?x=1") === $T);
check('bare / rejected',                   ArchMcp\ArchProfiles::extractToken('/') === null);
check('short token rejected',              ArchMcp\ArchProfiles::extractToken('/t/abc/') === null);
check('traversal in token rejected',       ArchMcp\ArchProfiles::extractToken('/t/../../etc/passwd') === null);
check('wrong prefix rejected',             ArchMcp\ArchProfiles::extractToken("/x/$T/") === null);

// --- path scoping (the control that actually matters) -----------------
echo "\nPath scoping\n";
$base = sys_get_temp_dir().'/archscope'.getmypid();
mkdir("$base/rcp/sub", 0775, true);
mkdir("$base/rcp-backup", 0775, true);          // the sibling that used to pass
mkdir("$base/arch-collab-core/site", 0775, true);
file_put_contents("$base/arch-collab-core/arch.py", '#');

// Minimal PSR-3 stub so this runs without the vendor tree.
require __DIR__.'/psr-stub.php';
require __DIR__.'/src/ArchConfig.php';
require __DIR__.'/src/ArchTools.php';
$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

function resolve(object $tools, string $rel, string $lane) {
    $m = new ReflectionMethod($tools, 'resolveScopedPath');
    $m->setAccessible(true);
    return $m->invoke($tools, $rel, $lane);
}

$rcp = new ArchMcp\ArchTools($logger, [
    'label' => 'rcp-dev', 'root' => "$base/rcp",
    'lanes' => ['site' => ''], 'session_tools' => false,
]);
check('rcp writes inside its root',        is_string(resolve($rcp, 'index.html', 'site')));
check('rcp writes into a subdir',          is_string(resolve($rcp, 'sub/page.html', 'site')));
check('rcp REJECTS ../ escape',            null === resolve($rcp, '../arch-collab-core/site/x.html', 'site'));
check('rcp REJECTS ../../ escape',         null === resolve($rcp, '../../etc/passwd', 'site'));
check('rcp REJECTS sibling rcp-backup',    null === resolve($rcp, '../rcp-backup/x.html', 'site'));
check('rcp has NO metadata lane',          null === resolve($rcp, 'todo.json', 'metadata'));
check('rcp cannot name an unknown lane',   null === resolve($rcp, 'x', 'vendor'));

$james = new ArchMcp\ArchTools($logger, [
    'label' => 'james', 'root' => "$base/arch-collab-core",
    'lanes' => ['metadata' => 'metadata', 'site' => 'site'], 'session_tools' => true,
]);
check('james site lane works',             is_string(resolve($james, 'index.html', 'site')));
check('james metadata lane works',         is_string(resolve($james, 'todo.json', 'metadata')));
check('james REJECTS arch.py at root',     null === resolve($james, '../arch.py', 'site'));
check('james REJECTS reaching into rcp',   null === resolve($james, '../../rcp/index.html', 'site'));


// --- regression tests added 2026-09-01 after the live run -------------
echo "\nDot-components and listing roots\n";
mkdir("$base/rcp/.git/hooks", 0775, true);
file_put_contents("$base/rcp/.git/config", "[core]\n");
file_put_contents("$base/rcp/index.html", "<html>rcp</html>");

check('REJECTS .git/config read',          null === resolve($rcp, '.git/config', 'site'));
check('REJECTS .git/hooks/post-merge',     null === resolve($rcp, '.git/hooks/post-merge', 'site'));
check('REJECTS .htaccess',                 null === resolve($rcp, '.htaccess', 'site'));
check('REJECTS nested dot dir',            null === resolve($rcp, 'assets/.env', 'site'));
check('still allows normal path',          is_string(resolve($rcp, 'assets/style.css', 'site')));

$listed = $rcp->archSiteListFiles();
check('list finds files at lane root',     in_array('index.html', $listed['files'], true));
check('list excludes .git internals',      !in_array('.git/config', $listed['files'], true));

$restricted = new ArchMcp\ArchTools($logger, [
    'label' => 'rcp-dev', 'root' => "$base/rcp",
    'lanes' => ['site' => ''], 'session_tools' => false,
    'write_extensions' => ['html', 'css', 'js', 'png', 'svg', 'txt'],
]);
$php = $restricted->archSiteWriteFile('shell.php', '<?php system($_GET["c"]);');
check('REJECTS shell.php write',           false === $php['success']);
$ok = $restricted->archSiteWriteFile('about.html', '<html></html>');
check('allows about.html write',           true === $ok['success']);
$noext = $restricted->archSiteWriteFile('Makefile', 'all:');
check('REJECTS extensionless write',       false === $noext['success']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
