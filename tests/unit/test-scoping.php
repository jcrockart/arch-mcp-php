<?php
// Local proof for the token-scoping draft. Does NOT touch the live server.
// Locate the app root by walking up until src/ArchTools.php appears, so
// this file works whether it sits at the repo root or in tests/unit/.
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ArchTools.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ArchTools.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}

require $ROOT.'/src/ArchProfiles.php';

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
// Minimal PSR-3 stub, inlined so this suite needs no vendor tree and no
// sibling helper file — it must run from a bare checkout.
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
// ArchConfig only supplies the legacy single-repo fallback constant, which
// every test here bypasses by passing an explicit profile. Require it when
// present; stub it otherwise, so this suite also runs from a payload dir
// that ships only the changed files.
if (is_file($ROOT.'/src/ArchConfig.php')) {
    require $ROOT.'/src/ArchConfig.php';
} elseif (!class_exists('ArchMcp\\ArchConfig')) {
    eval('namespace ArchMcp; final class ArchConfig { public const REPO_PATH = "/nonexistent"; }');
}
require $ROOT.'/src/ArchTools.php';
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


// --- header authentication (round 4) ----------------------------------
echo "\nHeader authentication (X-Api-Key)\n";
$H = str_repeat('c', 64);
$P = str_repeat('d', 64);

function fromReq(array $server) { return ArchMcp\ArchProfiles::fromRequest($server); }

list($tok, $via) = fromReq(['HTTP_X_API_KEY' => $H, 'REQUEST_URI' => '/']);
check('header token accepted',              $tok === $H && $via === 'header');

list($tok, $via) = fromReq(['HTTP_X_API_KEY' => "  $H  ", 'REQUEST_URI' => '/']);
check('header whitespace trimmed',          $tok === $H);

list($tok, $via) = fromReq(['REQUEST_URI' => "/t/$P/"]);
check('path still works in transition',     $tok === $P && $via === 'path');

// The important one: a present-but-broken header must NOT fall through to
// a path token. Otherwise a mistyped header silently keeps working off a
// stale URL and the misconfiguration never surfaces.
list($tok, $via) = fromReq(['HTTP_X_API_KEY' => 'short', 'REQUEST_URI' => "/t/$P/"]);
check('bad header does NOT fall back',      $tok === null && $via === 'header');

list($tok, $via) = fromReq(['HTTP_X_API_KEY' => '../../etc/passwd', 'REQUEST_URI' => "/t/$P/"]);
check('injection header refused',           $tok === null);

list($tok, $via) = fromReq(['REQUEST_URI' => '/']);
check('no credentials at all -> none',      $tok === null && $via === 'none');


// --- /project/ and /group/ addressing (round 7) -----------------------
// claude.ai refuses a second connector on the same URL, so every endpoint
// needs a distinct one. The name is an identifier, not a credential.
echo "\nAddressing\n";
$adr = 'ArchMcp\\ArchProfiles::extractAddress';

check('/project/rcp-dev',                  $adr("/project/rcp-dev/")   === ['kind'=>'project','name'=>'rcp-dev','sub'=>null]);
check('/project without trailing slash',   $adr("/project/rcp-dev")    === ['kind'=>'project','name'=>'rcp-dev','sub'=>null]);
check('/group/family/calendar',            $adr("/group/family/calendar/") === ['kind'=>'group','name'=>'family','sub'=>'calendar']);
check('/group nested sub',                 $adr("/group/family/calendar/2027") === ['kind'=>'group','name'=>'family','sub'=>'calendar/2027']);
check('query string ignored',              $adr("/project/rcp-dev/?x=1")['name'] === 'rcp-dev');
check('/group with NO sub rejected',       $adr("/group/family/") === null);
check('bare / has no address',             $adr("/") === null);
check('/t/ route has no address',          $adr("/t/".str_repeat('a',64)."/") === null);
check('old /p/ route now rejected',        $adr("/p/rcp-dev/") === null);
check('REJECTS traversal',                 $adr("/group/family/../../etc") === null);
check('REJECTS dot segment',               $adr("/group/family/.git") === null);
check('REJECTS empty segment',             $adr("/group/family//x") === null);
check('REJECTS 6 levels deep',             $adr("/group/family/a/b/c/d/e") === null);

// --- group sub-project resolution -------------------------------------
echo "\nGroup sub-projects\n";
mkdir("$base/groups/family/existing", 0775, true);

$rr = new ReflectionMethod('ArchMcp\\ArchProfiles', 'resolveGroupSubProject');
$rr->setAccessible(true);
function grp($rr, array $profile, $sub) { return $rr->invoke(null, $profile, $sub, 'header'); }

$seeding = ['label'=>'family','kind'=>'group','seed'=>true,'root'=>"$base/groups/family",'lanes'=>['site'=>'']];
$locked  = ['label'=>'family','kind'=>'group','seed'=>false,'root'=>"$base/groups/family",'lanes'=>['site'=>'']];

list($p1,) = grp($rr, $seeding, 'existing');
check('existing sub resolves',             is_array($p1) && $p1['address'] === 'family/existing');
check('existing sub root is correct',      is_array($p1) && basename($p1['root']) === 'existing');

list($p2,$v2) = grp($rr, $seeding, 'calendar');
check('SEEDS a new sub-project',           is_array($p2) && is_dir("$base/groups/family/calendar"));
check('seeded address is group/sub',       is_array($p2) && $p2['address'] === 'family/calendar');

list($p3,$v3) = grp($rr, $locked, 'recipes');
check('seed:false REFUSES new sub',        $p3 === null && $v3 === 'header/unseeded');
check('  ...and creates nothing',          !is_dir("$base/groups/family/recipes"));

list($p4,) = grp($rr, $seeding, 'nested/deep');
check('nested sub seeds too',              is_array($p4) && is_dir("$base/groups/family/nested/deep"));

list($p5,$v5) = grp($rr, $seeding, '');
check('empty sub refused',                 $p5 === null && $v5 === 'header/no-subproject');

// A symlink inside the group root is not a traversal, so the charset
// rules do not catch it — the post-resolution containment check must.
mkdir("$base/outside", 0775, true);
@symlink("$base/outside", "$base/groups/family/escape");
list($p6,$v6) = grp($rr, $seeding, 'escape');
check('symlink out of group REFUSED',      $p6 === null && $v6 === 'header/escape');

// --- kind and label matching ------------------------------------------
echo "\nKind and label matching\n";
$K = str_repeat('f', 64);
list($pr, $vr) = ArchMcp\ArchProfiles::resolveRequest(
    ['HTTP_X_API_KEY' => $K, 'REQUEST_URI' => '/project/rcp-dev/']);
check('unknown token refused',             $pr === null);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
