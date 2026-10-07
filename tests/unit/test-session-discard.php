<?php
// Offline proof that discarding a session saves its uncommitted work first
// (SessionDiscard, and ArchTools::archSessionDiscard end to end). Throwaway
// git repos with a stub arch.py that behaves like the real `session discard`
// (checkout main, rename the branch to discarded/<name>, remove the session
// file). No network, no secrets.
//
//     php tests/unit/test-session-discard.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/SessionDiscard.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/SessionDiscard.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (is_file($ROOT.'/src/ArchConfig.php')) {
    require $ROOT.'/src/ArchConfig.php';
} elseif (!class_exists('ArchMcp\\ArchConfig')) {
    eval('namespace ArchMcp; final class ArchConfig { public const REPO_PATH = "/nonexistent"; }');
}
require $ROOT.'/src/ArchTools.php';

use ArchMcp\ArchTools;
use ArchMcp\SessionDiscard;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function git(string $dir, string $args): string {
    exec('git -C '.escapeshellarg($dir).' '.$args.' 2>&1', $o, $rc);
    if (0 !== $rc) {
        fwrite(STDERR, "git $args failed in $dir:\n".implode("\n", $o)."\n");
        exit(2);
    }
    return implode("\n", $o);
}
$STUB = <<<'PY'
import json, subprocess
from pathlib import Path
REPO = Path(__file__).parent
def sh(*a):
    subprocess.run(["git", *a], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
s = json.loads((REPO / ".arch-session.json").read_text())
b = s["branch"]
sh("checkout", "main")
sh("branch", "-m", b, "discarded/" + b)
sh("checkout", "main")
(REPO / ".arch-session.json").unlink()
print("Session '%s' discarded." % b)
PY;

$base = sys_get_temp_dir().'/archdiscard'.getmypid();
$make = static function (string $name, string $lane = 'code') use ($base, $STUB): string {
    $d = "$base/$name";
    mkdir("$d/src", 0775, true);
    mkdir("$d/other", 0775, true);
    git($d, 'init -q -b main');
    git($d, 'config user.email t@example.com');
    git($d, 'config user.name t');
    file_put_contents("$d/arch-gate.json", json_encode(['code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => ['src', 'notes.md']]]]));
    file_put_contents("$d/arch.py", $STUB);
    file_put_contents("$d/src/a.php", "<?php\n\$a = 1;\n");
    file_put_contents("$d/other/o.txt", "other\n");
    file_put_contents("$d/notes.md", "hello\n");
    git($d, 'add -A');
    git($d, 'commit -q -m init');
    git($d, 'checkout -q -b mywork');
    file_put_contents("$d/.arch-session.json", json_encode(['branch' => 'mywork', 'base_commit' => 'x', 'started_at' => '2026-10-06T00:00:00+00:00', 'lane' => $lane]));
    return $d;
};
$tools = static fn (string $d): ArchTools => new ArchTools($logger, ['label' => 't', 'root' => $d, 'lanes' => ['code' => '', 'core' => ''], 'session_tools' => true]);
$realGit = static fn (string $d): callable => static function (array $args) use ($d): array {
    $p = proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $d);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($p), 'stdout' => (string) $out, 'stderr' => (string) $err];
};

echo "stagePaths()\n";
$d = $make('paths');
check('stage paths come from arch-gate.json for the lane', ['src', 'notes.md'] === SessionDiscard::stagePaths($d, 'code'));
check('metadata lane with no config defaults to metadata (if it exists)', [] === SessionDiscard::stagePaths($d, 'metadata'));
mkdir("$d/metadata");
check('...and it exists on disk', ['metadata'] === SessionDiscard::stagePaths($d, 'metadata'));
check('unknown lane with no config: nothing', [] === SessionDiscard::stagePaths($d, 'nope'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'missing-dir', '../escape', '-flag', '']]]]));
check('missing, escaping, flag-shaped and empty paths dropped', ['src'] === SessionDiscard::stagePaths($d, 'code'));
file_put_contents("$d/arch-gate.json", '{bad');
check('invalid arch-gate.json: nothing for code', [] === SessionDiscard::stagePaths($d, 'code'));

echo "\nsnapshot()\n";
$d = $make('snap1');
file_put_contents("$d/src/a.php", "<?php\n\$a = 2;\n");
file_put_contents("$d/src/new.php", "<?php\n// new\n");
file_put_contents("$d/other/o.txt", "changed outside the lane\n");
$r = SessionDiscard::snapshot($realGit($d), $d);
check('dirty stage paths are committed', true === $r['ok'] && true === $r['snapshotted'] && 2 === $r['files']);
check('the commit is on the session branch', 'mywork' === git($d, 'rev-parse --abbrev-ref HEAD') && str_contains(git($d, 'log -1 --format=%s'), 'Discarded session snapshot: mywork'));
check('edited and new files are in the snapshot', str_contains(git($d, 'show HEAD:src/a.php'), '$a = 2;') && str_contains(git($d, 'show HEAD:src/new.php'), '// new'));
check('changes outside the stage paths are not swept in', "other\n" === git($d, 'show HEAD:other/o.txt')."\n");
$r = SessionDiscard::snapshot($realGit($d), $d);
check('nothing left to save: no second commit', true === $r['ok'] && false === $r['snapshotted']);

$d = $make('snap2');
$r = SessionDiscard::snapshot($realGit($d), $d);
check('clean session: no snapshot', true === $r['ok'] && false === $r['snapshotted'] && 0 === $r['files']);
unlink("$d/.arch-session.json");
$r = SessionDiscard::snapshot($realGit($d), $d);
check('no session file: no-op', true === $r['ok'] && false === $r['snapshotted']);
file_put_contents("$d/.arch-session.json", '{broken');
$r = SessionDiscard::snapshot($realGit($d), $d);
check('unreadable session file: no-op, not a crash', true === $r['ok'] && false === $r['snapshotted']);

$d = $make('snap3');
file_put_contents("$d/src/a.php", "<?php\n\$a = 3;\n");
git($d, 'checkout -q main');
$r = SessionDiscard::snapshot($realGit($d), $d);
check('checkout not on the session branch: refused, not committed to main', false === $r['ok'] && str_contains($r['error'], "not the session branch 'mywork'") && 1 === (int) git($d, 'rev-list --count main'));

$d = $make('snap4');
file_put_contents("$d/src/a.php", "<?php\n\$a = 4;\n");
$failing = static fn (array $args): array => 'commit' === $args[0] ? ['exit_code' => 1, 'stdout' => '', 'stderr' => 'boom'] : $realGit($d)($args);
$r = SessionDiscard::snapshot($failing, $d);
check('a failing commit is reported, not hidden', false === $r['ok'] && str_contains($r['error'], 'git commit failed') && str_contains($r['error'], 'boom'));

echo "\nsnapshot(): git identity\n";
$noIdentityGit = static fn (string $d): callable => static function (array $args) use ($d): array {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => '/nonexistent', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
    $p = proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $d, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($p), 'stdout' => (string) $out, 'stderr' => (string) $err];
};
$d = $make('noid');
git($d, 'config --unset user.email');
git($d, 'config --unset user.name');
git($d, 'config user.useConfigOnly true');
file_put_contents("$d/src/a.php", "<?php\n\$a = 7;\n");
$probe = $noIdentityGit($d)(['commit', '--allow-empty', '-q', '-m', 'probe']);
check('premise: a plain commit fails in this checkout', 0 !== $probe['exit_code']);
$r = SessionDiscard::snapshot($noIdentityGit($d), $d);
check('no identity configured: the snapshot still commits', true === $r['ok'] && true === $r['snapshotted'] && 1 === $r['files']);
check('...using the fallback identity', 'ARCH session discard' === git($d, 'log -1 --format=%an') && 'arch-session@localhost' === git($d, 'log -1 --format=%ae'));

$d = $make('halfid');
git($d, 'config --unset user.name');
git($d, 'config user.useConfigOnly true');
file_put_contents("$d/src/a.php", "<?php\n\$a = 8;\n");
$r = SessionDiscard::snapshot($noIdentityGit($d), $d);
check('only the name missing: fallback name, configured email kept', true === $r['ok'] && 'ARCH session discard' === git($d, 'log -1 --format=%an') && 't@example.com' === git($d, 'log -1 --format=%ae'));

$d = $make('withid');
file_put_contents("$d/src/a.php", "<?php\n\$a = 9;\n");
$r = SessionDiscard::snapshot($noIdentityGit($d), $d);
check('configured identity is never overridden', true === $r['ok'] && 't' === git($d, 'log -1 --format=%an') && 't@example.com' === git($d, 'log -1 --format=%ae'));

echo "\narchSessionDiscard(): end to end\n";
$d = $make('e2e');
file_put_contents("$d/src/a.php", "<?php\n\$a = 5;\n");
file_put_contents("$d/notes.md", "hello from the session\n");
$r = $tools($d)->archSessionDiscard();
check('discard succeeds', 0 === $r['exit_code']);
check('reply says the changes were saved', str_contains($r['stdout'], '2 file(s)') && str_contains($r['stdout'], 'recover'));
check('main working tree is clean (no stranded edits)', '' === git($d, 'status --porcelain'));
check('on main, edits are not present', str_contains((string) file_get_contents("$d/src/a.php"), '$a = 1;') && "hello\n" === file_get_contents("$d/notes.md"));
check('the discarded branch holds the work', str_contains(git($d, 'show discarded/mywork:src/a.php'), '$a = 5;') && str_contains(git($d, 'show discarded/mywork:notes.md'), 'from the session'));
check('main has no snapshot commit', 1 === (int) git($d, 'rev-list --count main'));
check('session file removed', !is_file("$d/.arch-session.json"));

$d = $make('e2e-clean');
$r = $tools($d)->archSessionDiscard();
check('clean session: plain discard, no snapshot line', 0 === $r['exit_code'] && !str_contains($r['stdout'], 'file(s)') && '' === git($d, 'status --porcelain'));
check('...and no extra commit on the discarded branch', 1 === (int) git($d, 'rev-list --count discarded/mywork'));

$d = $make('e2e-refused');
file_put_contents("$d/src/a.php", "<?php\n\$a = 6;\n");
git($d, 'checkout -q main');
$r = $tools($d)->archSessionDiscard();
check('wrong branch: discard refused with the reason', 1 === $r['exit_code'] && str_contains($r['stderr'], 'discard refused'));
check('...session file kept, nothing renamed', is_file("$d/.arch-session.json") && '' === git($d, 'branch --list discarded/mywork'));
check('...edits still in the tree', str_contains((string) file_get_contents("$d/src/a.php"), '$a = 6;'));

$d = $make('e2e-nosession');
unlink("$d/.arch-session.json");
$r = $tools($d)->archSessionDiscard();
check('no session: nothing to save, the underlying discard refuses as before', 0 !== $r['exit_code'] && !str_contains($r['stdout'], 'file(s)'));

echo "\nfreeDiscardName(): older discarded branch of the same name\n";
$d = $make('collide');
git($d, 'branch discarded/mywork main');
git($d, 'tag discard-marker/discarded/mywork/1700000000 discarded/mywork');
file_put_contents("$d/src/a.php", "<?php\n\$a = 11;\n");
$r = $tools($d)->archSessionDiscard();
check('discard succeeds although discarded/mywork already existed', 0 === $r['exit_code']);
check('reply names the kept older branch', 1 === preg_match("/kept as 'discarded\\/mywork-\\d+'/", $r['stdout']));
check('new work is on discarded/mywork', str_contains(git($d, 'show discarded/mywork:src/a.php'), '$a = 11;'));
$old = trim(git($d, "for-each-ref --format='%(refname:short)' 'refs/heads/discarded/mywork-*'"));
check('older branch still exists under a unique name', 1 === preg_match('#^discarded/mywork-\d+$#', $old));
check('older marker tag moved to the older branch, same time', '' !== trim(git($d, "tag --list 'discard-marker/$old/1700000000'")));
check('no marker tag is left naming discarded/mywork', '' === trim(git($d, "tag --list 'discard-marker/discarded/mywork/*'")));

$d = $make('collide-twice');
git($d, 'branch discarded/mywork main');
$stamp = (string) time();
git($d, 'branch discarded/mywork-'.$stamp.' main');
$r = SessionDiscard::freeDiscardName($realGit($d), $d);
check('suffix already taken: another unique name', true === $r['ok'] && null !== $r['moved'] && 'discarded/mywork-'.$stamp !== $r['moved'] && '' !== trim(git($d, 'branch --list '.escapeshellarg($r['moved']))));

$d = $make('nocollide');
$r = SessionDiscard::freeDiscardName($realGit($d), $d);
check('no older branch: nothing moved', true === $r['ok'] && null === $r['moved']);
unlink("$d/.arch-session.json");
$r = SessionDiscard::freeDiscardName($realGit($d), $d);
check('no session file: no-op', true === $r['ok'] && null === $r['moved']);

$d = $make('collide-fail');
git($d, 'branch discarded/mywork main');
$failRename = static fn (array $args): array => 'branch' === $args[0] && '-m' === ($args[1] ?? '') ? ['exit_code' => 1, 'stdout' => '', 'stderr' => 'nope'] : $realGit($d)($args);
$r = SessionDiscard::freeDiscardName($failRename, $d);
check('a failing rename is reported', false === $r['ok'] && str_contains($r['error'], 'could not be renamed'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
