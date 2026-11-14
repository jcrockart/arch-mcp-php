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
require_once $ROOT.'/src/CommitPin.php';
require $ROOT.'/src/ProdState.php';

use ArchMcp\CommitPin;
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

// ---------------------------------------------------------------------------------------
// atCommit (ARCH job: chat promote-to-production, step 1). One commit id, named by the
// caller, is used instead of the live origin tip, so a check, an apply and a pull can all
// name the same commit. An empty atCommit leaves every check above exactly as it was.
// ---------------------------------------------------------------------------------------
echo "\nCommitPin: pinning to one commit\n";
// Production goes back to the first commit; origin has the second and the third.
sh("$base/prod", 'git checkout -q main');
sh("$base/prod", 'git reset -q --hard HEAD~2');
sh("$base/prod", 'git fetch -q origin');
$c1 = trim(sh("$base/prod", 'git rev-parse HEAD'));
$c2 = trim(sh("$base/prod", 'git rev-parse origin/main~1'));
$c3 = trim(sh("$base/prod", 'git rev-parse origin/main'));
$headNow = static fn (): string => trim(sh("$base/prod", 'git rev-parse HEAD'));

check('isValid: 40 lowercase hex characters and nothing else', CommitPin::isValid($c2) && !CommitPin::isValid(substr($c2, 0, 7)) && !CommitPin::isValid(strtoupper($c2)) && !CommitPin::isValid($c2.' ') && !CommitPin::isValid('--output=/tmp/x') && !CommitPin::isValid('') && !CommitPin::isValid('origin/main'));

$v = CommitPin::verify("$base/prod", 'main', $c2);
check('verify: a commit on origin that fast-forwards HEAD is ok', true === $v['ok'] && $c2 === $v['sha']);
$v = CommitPin::verify("$base/prod", 'main', 'abc');
check('verify: a short id is refused', false === $v['ok'] && 'invalid_commit' === $v['reason']);
$v = CommitPin::verify("$base/prod", 'main', str_repeat('a', 40));
check('verify: an unknown commit is not on origin', false === $v['ok'] && 'commit_not_on_origin' === $v['reason']);
$v = CommitPin::verify("$base/prod", '--output=/tmp/x', $c2);
check('verify: a flag-shaped branch is refused', false === $v['ok'] && 'invalid_branch' === $v['reason']);

commit("$base/prod", 'local.txt', "local\n", 'local only');
$local = $headNow();
$v = CommitPin::verify("$base/prod", 'main', $local);
check('verify: a commit that exists here but not on origin is refused', false === $v['ok'] && 'commit_not_on_origin' === $v['reason']);
$v = CommitPin::verify("$base/prod", 'main', $c2);
check('verify: production ahead of the commit is not a fast-forward', false === $v['ok'] && 'not_fast_forward' === $v['reason']);
check('verify: unless a fast-forward is not required', true === CommitPin::verify("$base/prod", 'main', $c2, false, false)['ok']);
sh("$base/prod", 'git reset -q --hard '.$c1);

file_put_contents("$base/prod/stray.txt", "x\n");
$v = CommitPin::verify("$base/prod", 'main', $c2, true);
check('verify: a dirty tree is refused when a clean tree is required', false === $v['ok'] && 'tree_dirty' === $v['reason']);
check('verify: and fine when it is not required', true === CommitPin::verify("$base/prod", 'main', $c2)['ok']);
unlink("$base/prod/stray.txt");

echo "\nProdState: atCommit\n";
$r = ProdState::report("$base/prod", 'main', false, $logger, $c2);
check('success, and it names the commit', true === $r['success'] && $c2 === $r['at_commit']);
check('behind counts to that commit, not to origin', 1 === $r['behind'] && 0 === $r['ahead'] && 1 === count($r['incoming_commits']));
check('changed_files is the diff to that commit only', 1 === count($r['changed_files']) && str_contains($r['changed_files'][0], 'b.txt'));
check('origin_head is still the real tip, and the pin is not it', $c3 === $r['origin_head'] && false === $r['at_commit_is_origin_head']);
check('can fast-forward, and is not up to date', true === $r['can_fast_forward'] && false === $r['up_to_date']);
$r = ProdState::report("$base/prod", 'main', true, $logger, $c2);
check('the diff is to that commit: it has the second change, not the third', str_contains($r['diff'], '+two') && !str_contains($r['diff'], '+more'));
$r = ProdState::report("$base/prod", 'main', false, $logger, $c3);
check('pinned to the tip: behind 2, and the pin is origin head', 2 === $r['behind'] && true === $r['at_commit_is_origin_head'] && 2 === count($r['changed_files']));
$r = ProdState::report("$base/prod", 'main', false, $logger, 'abc');
check('a short id is refused', false === $r['success'] && 'invalid_commit' === $r['reason']);
$r = ProdState::report("$base/prod", 'main', false, $logger, str_repeat('b', 40));
check('an unknown commit is refused, never read as up to date', false === $r['success'] && 'commit_not_on_origin' === $r['reason'] && !isset($r['up_to_date']));
$r = ProdState::report("$base/prod", 'main', false, $logger);
check('no atCommit: no pin keys, behind 2, as before', !isset($r['at_commit']) && !isset($r['at_commit_is_origin_head']) && 2 === $r['behind']);
sh($base, 'mv origin.git origin.gone');
$r = ProdState::report("$base/prod", 'main', false, $logger, $c2);
check('a failed fetch with atCommit is refused, not guessed', false === $r['success'] && 'fetch_failed' === $r['reason']);
sh($base, 'mv origin.gone origin.git');

echo "\nCommitPin::pull: lands exactly the named commit\n";
$r = CommitPin::pull("$base/prod", 'main', $c2);
check('exit 0, pulled, target named, before and after', 0 === $r['exit_code'] && true === $r['pulled'] && $c2 === $r['target'] && $c1 === $r['before'] && $c2 === $r['after']);
check('HEAD is the named commit, not the origin tip', $c2 === $headNow() && $c3 !== $headNow());
$r = CommitPin::pull("$base/prod", 'main', $c2);
check('already there: exit 0, not pulled, nothing moved', 0 === $r['exit_code'] && false === $r['pulled'] && $r['before'] === $r['after']);
$r = CommitPin::pull("$base/prod", 'main', $c3);
check('and on to the tip when that is named', 0 === $r['exit_code'] && true === $r['pulled'] && $c3 === $headNow());

sh("$base/prod", 'git reset -q --hard '.$c1);
file_put_contents("$base/prod/stray.txt", "x\n");
$r = CommitPin::pull("$base/prod", 'main', $c2);
check('dirty tree: refused, HEAD unchanged', 1 === $r['exit_code'] && 'tree_dirty' === $r['reason'] && $c1 === $headNow());
unlink("$base/prod/stray.txt");
$r = CommitPin::pull("$base/prod", 'main', str_repeat('c', 40));
check('unknown commit: refused, HEAD unchanged', 1 === $r['exit_code'] && 'commit_not_on_origin' === $r['reason'] && $c1 === $headNow());
$r = CommitPin::pull("$base/prod", 'main', '--output=/tmp/archpin-x');
check('a hostile value is refused and nothing is created', 1 === $r['exit_code'] && 'invalid_commit' === $r['reason'] && !file_exists('/tmp/archpin-x') && $c1 === $headNow());
commit("$base/prod", 'local.txt', "local\n", 'local only');
$local = $headNow();
$r = CommitPin::pull("$base/prod", 'main', $c2);
check('diverged: refused, HEAD unchanged', 1 === $r['exit_code'] && 'not_fast_forward' === $r['reason'] && $local === $headNow());
sh("$base/prod", 'git reset -q --hard '.$c1);
sh($base, 'mv origin.git origin.gone');
$r = CommitPin::pull("$base/prod", 'main', $c2);
check('origin unreachable: refused, HEAD unchanged', 1 === $r['exit_code'] && 'fetch_failed' === $r['reason'] && $c1 === $headNow());
sh($base, 'mv origin.gone origin.git');

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
