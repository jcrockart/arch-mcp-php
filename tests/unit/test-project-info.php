<?php
// Offline proof of the project summary (ProjectInfo): flags, stage paths,
// git state, session, same-checkout detection, and that no server path is
// ever reported. Throwaway git repos, no network, no secrets.
//
//     php tests/unit/test-project-info.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectInfo.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectInfo.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectInfo.php';

use ArchMcp\ProjectInfo;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function sh(string $cmd): void {
    exec($cmd.' 2>&1', $o, $rc);
    if (0 !== $rc) {
        fwrite(STDERR, "setup failed: $cmd\n".implode("\n", $o)."\n");
        exit(2);
    }
}

$base = sys_get_temp_dir().'/archinfo'.getmypid();
$stg = "$base/staging";
$prd = "$base/production";
foreach ([$stg, $prd] as $d) {
    mkdir($d, 0775, true);
    sh('git -C '.escapeshellarg($d).' init -q -b main');
    sh('git -C '.escapeshellarg($d).' config user.email t@example.com');
    sh('git -C '.escapeshellarg($d).' config user.name t');
    file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'arch-gate.json']]]]));
    sh('git -C '.escapeshellarg($d).' add -A');
    sh('git -C '.escapeshellarg($d).' commit -q -m init');
}
file_put_contents("$stg/dirty.txt", "x\n");

$profile = static fn (string $root, array $extra = []): array => $extra + [
    'label' => 'p', 'root' => $root, 'lanes' => ['core' => '', 'code' => ''], 'session_tools' => true,
    'write_extensions' => ['php', 'md'], 'pull_allowed' => false, 'pull_branch' => 'main',
    'push_allowed' => true, 'push_branch' => 'main', 'db_apply_allowed' => false, 'dependency_manager' => 'composer',
];

echo "describe(): two profiles\n";
$r = ProjectInfo::describe('demo', 'production', $profile($stg), $profile($prd, ['pull_allowed' => true]));
check('success with slug and portal environment', true === $r['success'] && 'demo' === $r['slug'] && 'production' === $r['portal_environment']);
check('different directories: same_checkout false', false === $r['same_checkout']);
check('lanes listed by name', ['core', 'code'] === $r['staging']['lanes']);
check('flags reported per profile', true === $r['staging']['push_allowed'] && false === $r['staging']['pull_allowed'] && true === $r['production']['pull_allowed']);
check('write types and dependency manager reported', ['php', 'md'] === $r['staging']['write_extensions'] && 'composer' === $r['staging']['dependency_manager']);
check('code stage paths read from arch-gate.json', ['src', 'arch-gate.json'] === $r['staging']['code_stage_paths']);
check('staging git: branch main, dirty, one file', 'main' === $r['staging']['git']['branch'] && false === $r['staging']['git']['clean'] && ['dirty.txt'] === $r['staging']['git']['dirty_files'] && 1 === $r['staging']['git']['dirty_count']);
check('production git: clean, short HEAD', true === $r['production']['git']['clean'] && 1 === preg_match('/^[0-9a-f]{7,}$/', $r['production']['git']['head']));
check('no active session reports null', null === $r['staging']['session']);
check('no server path appears anywhere in the reply', !str_contains(json_encode($r), $base));

echo "\nprofile row labelling\n";
$r = ProjectInfo::describe('demo', 'production', $profile($stg, ['profile_environment' => 'production']), $profile($stg, ['profile_environment' => 'production']));
check('staging view that is the production row is flagged', true === ($r['staging_view_is_production'] ?? false) && isset($r['staging_note']));
check('each view reports its row', 'production' === $r['staging']['profile_environment'] && 'production' === $r['production']['profile_environment']);
$r = ProjectInfo::describe('demo', 'production', $profile($stg, ['profile_environment' => 'staging']), $profile($prd, ['profile_environment' => 'production']));
check('a real staging row: no flag', !isset($r['staging_view_is_production']) && 'staging' === $r['staging']['profile_environment']);
$r = ProjectInfo::describe('demo', 'production', $profile($stg), $profile($prd));
check('profile without the field: null, no flag', null === $r['staging']['profile_environment'] && !isset($r['staging_view_is_production']));

echo "\nsame checkout\n";
$r = ProjectInfo::describe('demo', 'production', $profile($stg), $profile($stg));
check('same directory: same_checkout true', true === $r['same_checkout']);
$r = ProjectInfo::describe('demo', 'production', $profile($stg), null);
check('no production profile: null and a note', null === $r['production'] && false === $r['same_checkout'] && isset($r['note']));
$sub = "$base/mono";
mkdir("$sub/core", 0775, true);
sh('git -C '.escapeshellarg("$sub/core").' init -q -b main');
sh('git -C '.escapeshellarg("$sub/core").' config user.email t@example.com');
sh('git -C '.escapeshellarg("$sub/core").' config user.name t');
file_put_contents("$sub/core/f.txt", "x\n");
sh('git -C '.escapeshellarg("$sub/core").' add -A');
sh('git -C '.escapeshellarg("$sub/core").' commit -q -m init');
$r = ProjectInfo::view($profile($sub, ['lanes' => ['core' => 'core']]));
check('core-lane subdirectory is where git runs', true === $r['git']['available'] && 'main' === $r['git']['branch']);

echo "\nsession\n";
file_put_contents("$stg/.arch-session.json", json_encode(['lane' => 'code', 'branch' => 'my-work', 'base_commit' => 'abc', 'started_at' => '2026-10-06T10:00:00+00:00']));
$v = ProjectInfo::view($profile($stg));
check('active session: lane, branch, start time', true === $v['session']['active'] && 'code' === $v['session']['lane'] && 'my-work' === $v['session']['branch'] && '2026-10-06T10:00:00+00:00' === $v['session']['started_at']);
check('base commit not exposed', !isset($v['session']['base_commit']));
file_put_contents("$stg/.arch-session.json", '{broken');
$v = ProjectInfo::view($profile($stg));
check('unreadable session file is flagged, not fatal', true === $v['session']['active'] && isset($v['session']['note']));

echo "\nfail-soft cases\n";
$v = ProjectInfo::view($profile("$base/nowhere"));
check('missing directory: git unavailable, no crash', false === $v['git']['available'] && null === $v['code_stage_paths'] && null === $v['session']);
mkdir("$base/plain", 0775, true);
$v = ProjectInfo::view($profile("$base/plain"));
check('not a git checkout: unavailable', false === $v['git']['available']);
file_put_contents("$base/plain/arch-gate.json", '{nope');
check('invalid arch-gate.json: stage paths null', null === ProjectInfo::stagePaths("$base/plain"));
file_put_contents("$base/plain/arch-gate.json", json_encode(['code' => ['gate' => []]]));
check('arch-gate.json without stage paths: null', null === ProjectInfo::stagePaths("$base/plain"));
check('empty root: no stage paths, no session', null === ProjectInfo::stagePaths('') && null === ProjectInfo::session(''));
for ($i = 0; $i < 25; ++$i) {
    file_put_contents("$stg/many$i.txt", "x\n");
}
$v = ProjectInfo::view($profile($stg, ['lanes' => ['core' => '']]));
check('dirty file list is capped at 20 but counted in full', 20 === \count($v['git']['dirty_files']) && 27 === $v['git']['dirty_count']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
