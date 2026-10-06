<?php
// Offline proof of ProdState::report() against throwaway local git repos.
// No network, no secrets, no live checkout touched.
//
//     php tests/unit/test-prod-state.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProdState.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProdState.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
require $ROOT.'/src/ProdState.php';

use ArchMcp\ProdState;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function sh(string $cwd, string $cmd): string {
    $out = [];
    exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
    return implode("\n", $out);
}
function commit(string $repo, string $file, string $text, string $msg): void {
    file_put_contents("$repo/$file", $text);
    sh($repo, 'git add -A && git -c user.name=t -c user.email=t@t commit -q -m '.escapeshellarg($msg));
}

$base = sys_get_temp_dir().'/archprod'.getmypid();
mkdir($base, 0775, true);
sh($base, 'git init -q --bare origin.git');
sh($base, 'git clone -q origin.git seed');
sh("$base/seed", 'git checkout -q -b main');
commit("$base/seed", 'a.txt', "one\n", 'first');
sh("$base/seed", 'git push -q origin main');
sh($base, 'git --git-dir=origin.git symbolic-ref HEAD refs/heads/main');
sh($base, 'git clone -q origin.git prod');
sh("$base/prod", 'git checkout -q main');

echo "Up to date\n";
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('success', true === $r['success']);
check('up_to_date is true', true === $r['up_to_date']);
check('behind 0, ahead 0', 0 === $r['behind'] && 0 === $r['ahead']);
check('clean', true === $r['clean']);
check('no incoming commits', [] === $r['incoming_commits']);
check('fetch_ok', true === $r['fetch_ok']);

echo "\nOrigin moves on (the case Portal missed)\n";
commit("$base/seed", 'b.txt', "two\n", 'second');
commit("$base/seed", 'a.txt', "one\nmore\n", 'third');
sh("$base/seed", 'git push -q origin main');
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('not up to date, found by its own fetch', false === $r['up_to_date']);
check('behind 2', 2 === $r['behind']);
check('lists both incoming commits', 2 === count($r['incoming_commits']));
check('lists changed files', 2 === count($r['changed_files']));
check('can fast-forward', true === $r['can_fast_forward']);
check('no diff unless asked', !isset($r['diff']));
$r = ProdState::report("$base/prod", 'main', true, $logger);
check('diff included when asked', isset($r['diff']) && str_contains($r['diff'], '+two'));
check('diff not truncated', false === $r['diff_truncated']);
check('head unchanged by the report', trim(sh("$base/prod", 'git rev-parse HEAD')) === $r['head']);

echo "\nDirty working tree\n";
file_put_contents("$base/prod/stray.txt", "x\n");
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('reported not clean', false === $r['clean']);
check('names the dirty file', 1 === count($r['dirty_files']) && str_contains($r['dirty_files'][0], 'stray.txt'));
unlink("$base/prod/stray.txt");

echo "\nDiverged checkout\n";
commit("$base/prod", 'local.txt', "local\n", 'local only');
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('ahead 1, behind 2', 1 === $r['ahead'] && 2 === $r['behind']);
check('cannot fast-forward', false === $r['can_fast_forward']);
sh("$base/prod", 'git reset -q --hard HEAD~1');

echo "\nFetch failure must never read as up to date\n";
sh("$base/prod", 'git merge -q --ff-only origin/main');
sh($base, 'mv origin.git origin.gone');
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('success still true (local facts known)', true === $r['success']);
check('fetch_ok false', false === $r['fetch_ok']);
check('up_to_date is null, not true', null === $r['up_to_date']);
check('fetch_error explained', isset($r['fetch_error']) && '' !== $r['fetch_error']);
sh($base, 'mv origin.gone origin.git');

echo "\nBad input\n";
$r = ProdState::report("$base/prod", '--output=/tmp/x', false, $logger);
check('flag-shaped pull_branch refused', false === $r['success']);
$r = ProdState::report("$base/prod", 'nosuch', false, $logger);
check('unknown branch: up_to_date null with a note', true === $r['success'] && null === $r['up_to_date'] && isset($r['note']));
$r = ProdState::report("$base/does-not-exist", 'main', false, $logger);
check('missing directory refused', false === $r['success']);
sh($base, 'mkdir plain');
$r = ProdState::report("$base/plain", 'main', false, $logger);
check('not a git checkout refused', false === $r['success']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
