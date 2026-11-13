<?php
// Offline proof of the edit-and-commit-in-one-turn guard (ARCH-86):
// SessionCommit::recentWrite() finds a stage-path file that changed a moment ago.
//
//     php tests/unit/test-recent-write.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/SessionCommit.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/SessionCommit.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/SessionCommit.php';

use ArchMcp\Git;
use ArchMcp\SessionCommit;
use ArchMcp\SessionFile;

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

$d = sys_get_temp_dir().'/recentwrite'.getmypid();
mkdir("$d/src", 0775, true);
sh('git -C '.escapeshellarg($d).' init -q -b main');
sh('git -C '.escapeshellarg($d).' config user.email t@example.com');
sh('git -C '.escapeshellarg($d).' config user.name t');
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => ['src', 'arch-gate.json']]]]));
file_put_contents("$d/src/a.php", "<?php\n");
sh('git -C '.escapeshellarg($d).' add -A');
sh('git -C '.escapeshellarg($d).' commit -q -m init');
$git = Git::in($d);

check('no session: nothing to report', null === SessionCommit::recentWrite($d, $git, time()));

SessionFile::write($d, 'work', 'abc', 'code');
check('session open, no changes: nothing to report', null === SessionCommit::recentWrite($d, $git, time()));

file_put_contents("$d/src/a.php", "<?php\n// edit\n");
$now = time();
$r = SessionCommit::recentWrite($d, $git, $now);
check('a file changed this second is reported', null !== $r && 'src/a.php' === $r['path'] && $r['age'] <= 1);
check('the same file a few seconds later is not', null === SessionCommit::recentWrite($d, $git, $now + 5));

touch("$d/src/a.php", time() - 30);
file_put_contents("$d/src/new.php", "<?php\n");
$r = SessionCommit::recentWrite($d, $git, time());
check('a new untracked file counts', null !== $r && 'src/new.php' === $r['path']);

touch("$d/src/new.php", time() - 30);
touch("$d/src/a.php", time() - 30);
check('older changes do not count', null === SessionCommit::recentWrite($d, $git, time()));

file_put_contents("$d/outside.txt", "x\n");
check('a file outside the stage paths does not count', null === SessionCommit::recentWrite($d, $git, time()));

exec('rm -rf '.escapeshellarg($d));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
