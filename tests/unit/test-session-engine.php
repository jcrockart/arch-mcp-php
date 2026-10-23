<?php

/*
 * The built-in session engine against real temporary git repositories:
 * start, commit (gate, staging, merge, versioning), discard, recover, purge.
 *
 *     php tests/unit/test-session-engine.php
 */

require_once __DIR__.'/../../src/Git.php';
require_once __DIR__.'/../../src/GateConfig.php';
require_once __DIR__.'/../../src/GateRunner.php';
require_once __DIR__.'/../../src/SessionFile.php';
require_once __DIR__.'/../../src/SessionResult.php';
require_once __DIR__.'/../../src/SessionStart.php';
require_once __DIR__.'/../../src/SessionVersion.php';
require_once __DIR__.'/../../src/SessionCommit.php';
require_once __DIR__.'/../../src/SessionDiscarder.php';
require_once __DIR__.'/../../src/SessionRecover.php';
require_once __DIR__.'/../../src/CommitOutput.php';

use ArchMcp\CommitOutput;
use ArchMcp\Git;
use ArchMcp\SessionCommit;
use ArchMcp\SessionDiscarder;
use ArchMcp\SessionFile;
use ArchMcp\SessionPurge;
use ArchMcp\SessionRecover;
use ArchMcp\SessionStart;

// No identity from the machine: the engine must supply its own fallback.
putenv('GIT_CONFIG_GLOBAL=/dev/null');
putenv('GIT_CONFIG_NOSYSTEM=1');

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        ++$passed;
        echo "[PASS] $name\n";
    } else {
        ++$failed;
        echo "[FAIL] $name".('' !== $detail ? "  -- $detail" : '')."\n";
    }
}

function sh(string $dir, string ...$args): string
{
    $r = Git::run($dir, $args);

    return rtrim($r['stdout']);
}

/** A repository on main with one commit; $gate is written to arch-gate.json first. */
function repo(?array $gate, array $files = ['src/a.txt' => "one\n"]): string
{
    $d = sys_get_temp_dir().'/engine-test-'.bin2hex(random_bytes(6));
    mkdir($d, 0777, true);
    Git::run($d, ['init', '-q', '-b', 'main']);
    file_put_contents($d.'/.gitignore', ".arch-session.json\n");
    if (null !== $gate) {
        file_put_contents($d.'/arch-gate.json', json_encode($gate));
    }
    foreach ($files as $rel => $content) {
        @mkdir(\dirname($d.'/'.$rel), 0777, true);
        file_put_contents($d.'/'.$rel, $content);
    }
    Git::run($d, ['add', '-A']);
    Git::run($d, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init']);

    return $d;
}

function codeGate(array $steps = [], array $extra = [], array $paths = ['src']): array
{
    return ['code' => ['gate' => $steps, 'stage' => ['label' => 'code', 'paths' => $paths]] + $extra];
}

$pass = ['name' => 'ok', 'cmd' => ['sh', '-c', 'echo fine']];
$fail = ['name' => 'bad', 'cmd' => ['sh', '-c', 'echo nope; exit 1']];

// ---------------------------------------------------------------- start
$d = repo(codeGate([$pass]));
$g = Git::in($d);
$r = SessionStart::run($d, 'work', 'code', $g);
check('start succeeds', 0 === $r['exit_code'], json_encode($r));
check('start puts the checkout on the new branch', 'work' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD'));
$s = SessionFile::read($d);
check('session file has branch, lane and base', $s && 'work' === $s['branch'] && 'code' === $s['lane'] && sh($d, 'rev-parse', 'main') === $s['base_commit']);
$raw = (string) file_get_contents($d.'/.arch-session.json');
check('session file is two-space JSON with the four keys', str_starts_with($raw, "{\n  \"branch\": \"work\",\n  \"base_commit\": ") && str_contains($raw, "\n  \"lane\": \"code\"\n}"));
check('started_at is an ISO UTC timestamp', 1 === preg_match('/"started_at": "\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00"/', $raw));
$r2 = SessionStart::run($d, 'other', 'code', $g);
check('a second start is refused', 0 !== $r2['exit_code'] && str_contains($r2['stderr'], 'already active'));
check('refused start created no branch', '' === sh($d, 'branch', '--list', 'other'));

$d = repo(codeGate([$pass]));
$r = SessionStart::run($d, '', 'code', Git::in($d));
check('unnamed start gets a timestamped branch', 0 === $r['exit_code'] && 1 === preg_match('/^session-\d{8}T\d{6}Z$/', sh($d, 'rev-parse', '--abbrev-ref', 'HEAD')));

$d = repo(codeGate([$pass]));
$r = SessionStart::run($d, 'bad name', 'code', Git::in($d));
check('an invalid branch name fails and leaves no session', 0 !== $r['exit_code'] && !SessionFile::exists($d));

// ---------------------------------------------------------------- commit, unversioned
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'work', 'code', $g);
file_put_contents($d.'/src/a.txt', "two\n");
file_put_contents($d.'/src/new.txt', "new\n");
file_put_contents($d.'/outside.txt', "not staged\n");
$mainBefore = sh($d, 'rev-parse', 'main');
$r = SessionCommit::run($d, 'patch', $g);
check('commit succeeds', 0 === $r['exit_code'], json_encode($r));
check('commit reports COMMITTED with the lane', str_contains($r['stdout'], "COMMITTED. Session 'work' (lane: code) merged to main and retired."));
check('a passing transcript has the step output first, then the heading and gate lines', str_contains($r['stdout'], "fine\nValidating session 'work' (lane: code) before commit...\n\n-- gate: ok\n"));
check('checkout is back on main', 'main' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD'));
check('main has the stage-path changes', 'two' === sh($d, 'show', 'main:src/a.txt') && 'new' === sh($d, 'show', 'main:src/new.txt'));
check('files outside the stage paths are not committed', '' === sh($d, 'ls-files', 'outside.txt'));
check('merge is --no-ff (two parents)', 2 === count(explode(' ', sh($d, 'log', '-1', '--format=%P', 'main'))));
check('merge message names the lane and branch', 'Session commit (code): work' === sh($d, 'log', '-1', '--format=%s', 'main'));
check('session branch is deleted', '' === sh($d, 'branch', '--list', 'work'));
check('session file is gone', !SessionFile::exists($d));
check('unversioned lane: no VERSION.json and no tag', !is_file($d.'/VERSION.json') && '' === sh($d, 'tag', '--list'));
check('main moved', sh($d, 'rev-parse', 'main') !== $mainBefore);
check('the session commit message is on the branch commit', str_contains(sh($d, 'log', '--format=%s', 'main'), 'Session code: work'));

// the shortener reads the passing transcript as before
$d = repo(codeGate([['name' => 'tests', 'cmd' => ['sh', '-c', 'echo "[PASS] a"; echo "[PASS] b"; echo "2 passed, 0 failed"']], $pass]));
$g = Git::in($d);
SessionStart::run($d, 'short', 'code', $g);
$r = SessionCommit::run($d, 'patch', $g);
$c = CommitOutput::compact($r['stdout']);
check('compact() counts the checks and drops the [PASS] lines', str_starts_with($c, "Checks: 2 passed, 0 failed.\n") && !str_contains($c, '[PASS]'), $c);
check('compact() lists the gates on one line', str_contains($c, 'Gates passed (2): tests, ok'), $c);
check('compact() keeps the COMMITTED line', str_contains($c, "COMMITTED. Session 'short'"), $c);

// empty session still commits
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'empty', 'code', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('an empty session commits', 0 === $r['exit_code'] && !SessionFile::exists($d), json_encode($r));

// a stage path that does not exist yet is skipped
$d = repo(codeGate([$pass], [], ['src', 'missing-dir']));
$g = Git::in($d);
SessionStart::run($d, 's', 'code', $g);
file_put_contents($d.'/src/a.txt', "x\n");
$r = SessionCommit::run($d, 'patch', $g);
check('a missing stage path is skipped', 0 === $r['exit_code'], json_encode($r));

// no session / bad bump
$d = repo(codeGate([$pass]));
$r = SessionCommit::run($d, 'patch', Git::in($d));
check('commit with no session is refused', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'No active session'));
$r = SessionCommit::run($d, 'huge', Git::in($d));
check('invalid bump is refused', 0 !== $r['exit_code'] && str_contains($r['stderr'], "invalid bump 'huge'"));

// commit with a lane that has no config
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'w', 'ghost', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('commit on a lane with no config is refused, branch stays', 0 !== $r['exit_code'] && str_contains($r['stderr'], "No configuration found for lane 'ghost'") && 'w' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD'));

// ---------------------------------------------------------------- gate failure
$d = repo(codeGate([$pass, $fail, ['name' => 'after', 'cmd' => ['sh', '-c', 'echo SHOULD-NOT-RUN']]]));
$g = Git::in($d);
SessionStart::run($d, 'work', 'code', $g);
file_put_contents($d.'/src/a.txt', "changed\n");
$mainBefore = sh($d, 'rev-parse', 'main');
$r = SessionCommit::run($d, 'patch', $g);
check('failing gate exits non-zero', 0 !== $r['exit_code']);
check('failing gate output is the transcript', str_contains($r['stdout'], "COMMIT ABORTED at gate 'bad'") && str_contains($r['stdout'], 'nope'));
check('steps after the failure did not run', !str_contains($r['stdout'], 'SHOULD-NOT-RUN'));
check('main is untouched', sh($d, 'rev-parse', 'main') === $mainBefore);
check('branch stays open and checked out', 'work' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD') && SessionFile::exists($d));
check('nothing was committed on the branch', sh($d, 'rev-parse', 'work') === $mainBefore);
check('the edit is still in the working tree', "changed\n" === file_get_contents($d.'/src/a.txt'));

// ---------------------------------------------------------------- versioned
$d = repo(codeGate([$pass], ['versioned' => true]));
$g = Git::in($d);
SessionStart::run($d, 'v1', 'code', $g);
file_put_contents($d.'/src/a.txt', "v\n");
$r = SessionCommit::run($d, 'patch', $g);
check('versioned commit succeeds and names the tag', 0 === $r['exit_code'] && str_contains($r['stdout'], "COMMITTED as v0.1.1. Session 'v1' merged to main and retired."), json_encode($r));
check('no VERSION.json starts from 0.1.0 then bumps', "{\n  \"major\": 0,\n  \"minor\": 1,\n  \"patch\": 1\n}" === file_get_contents($d.'/VERSION.json'));
check('tag exists on main', 'v0.1.1' === sh($d, 'tag', '--list', 'v0.1.1') && sh($d, 'rev-parse', 'v0.1.1') === sh($d, 'rev-parse', 'main'));
check('bump commit message', 'Bump version to v0.1.1' === sh($d, 'log', '-1', '--format=%s', 'main'));
check('merge commit message carries the tag', str_contains(sh($d, 'log', '--format=%s', 'main'), 'Session commit: v1 -> v0.1.1'));
check('result reports the version', true === ($r['version']['ok'] ?? null) && 'v0.1.1' === ($r['version']['tag'] ?? null));

SessionStart::run($d, 'v2', 'code', $g);
file_put_contents($d.'/src/a.txt', "v2\n");
$r = SessionCommit::run($d, 'minor', $g);
check('minor bump resets patch', str_contains($r['stdout'], 'COMMITTED as v0.2.0'), json_encode($r));
SessionStart::run($d, 'v3', 'code', $g);
$r = SessionCommit::run($d, 'major', $g);
check('major bump', str_contains($r['stdout'], 'COMMITTED as v1.0.0'), json_encode($r));

// versioned by a metadata stage path, no explicit key
$d = repo(codeGate([$pass], [], ['src', 'metadata']), ['src/a.txt' => "1\n", 'metadata/x.json' => "{}\n"]);
$g = Git::in($d);
SessionStart::run($d, 'm', 'code', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('a lane staging metadata/ is versioned', str_contains($r['stdout'], 'COMMITTED as v0.1.1'), json_encode($r));

// explicit versioned false beats the metadata path
$d = repo(codeGate([$pass], ['versioned' => false], ['src', 'metadata']), ['src/a.txt' => "1\n", 'metadata/x.json' => "{}\n"]);
$g = Git::in($d);
SessionStart::run($d, 'm', 'code', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('versioned:false is honoured', 0 === $r['exit_code'] && !str_contains($r['stdout'], 'COMMITTED as v') && !is_file($d.'/VERSION.json'));

// metadata lane with no config entry: built-in validate
$d = repo(null, ['metadata/.keep' => '']);
$g = Git::in($d);
SessionStart::run($d, 'meta', 'metadata', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('metadata lane with no config runs the built-in validator (and fails on an empty project)', 0 !== $r['exit_code'] && str_contains($r['stdout'], '-- gate: validate') && str_contains($r['stdout'], 'COMMIT WOULD FAIL'), json_encode($r));

// bad VERSION.json stops the commit before the gates run
$d = repo(codeGate([['name' => 'marker', 'cmd' => ['sh', '-c', 'touch gate-ran']]], ['versioned' => true]), ['src/a.txt' => "1\n", 'VERSION.json' => '{"major": "x"}']);
$g = Git::in($d);
SessionStart::run($d, 'bad', 'code', $g);
$mainBefore = sh($d, 'rev-parse', 'main');
$r = SessionCommit::run($d, 'patch', $g);
check('bad VERSION.json fails early', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'VERSION.json is not'));
check('no gate ran and nothing changed', !is_file($d.'/gate-ran') && sh($d, 'rev-parse', 'main') === $mainBefore && 'bad' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD'));

// an existing tag stops it too
$d = repo(codeGate([$pass], ['versioned' => true]));
$g = Git::in($d);
Git::run($d, ['tag', 'v0.1.1']);
SessionStart::run($d, 'tagged', 'code', $g);
$r = SessionCommit::run($d, 'patch', $g);
check('an existing tag fails early', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'tag v0.1.1 already exists') && SessionFile::exists($d));

// post-merge version failure is a warning, not a failure
$d = repo(codeGate([$pass], ['versioned' => true]));
$g = Git::in($d);
SessionStart::run($d, 'warn', 'code', $g);
file_put_contents($d.'/src/a.txt', "w\n");
$gWrap = static function (array $args) use ($g): array {
    if (['tag', 'v0.1.1'] === $args) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'simulated tag failure'];
    }

    return $g($args);
};
$r = SessionCommit::run($d, 'patch', $gWrap);
check('tag failure after the merge: still exit 0', 0 === $r['exit_code'], json_encode($r));
check('tag failure is a WARNING and says main has no new version', str_contains($r['stdout'], 'WARNING: the session was merged, but the version bump failed') && false === $r['version']['ok']);
check('the merge itself stands', 'main' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD') && 'w' === sh($d, 'show', 'main:src/a.txt') && !SessionFile::exists($d));

// ---------------------------------------------------------------- merge failure
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'clash', 'code', $g);
file_put_contents($d.'/src/a.txt', "branch side\n");
// advance main with a conflicting change, from a second worktree
$wt = $d.'-wt';
Git::run($d, ['worktree', 'add', '-q', $wt, 'main']);
file_put_contents($wt.'/src/a.txt', "main side\n");
Git::run($wt, ['add', '-A']);
Git::run($wt, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'main moves']);
Git::run($d, ['worktree', 'remove', '--force', $wt]);
$mainBefore = sh($d, 'rev-parse', 'main');
$r = SessionCommit::run($d, 'patch', $g);
check('a conflicting merge fails', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'the merge into main failed and was aborted'), json_encode($r));
check('the failed merge was aborted', !is_file($d.'/.git/MERGE_HEAD'));
check('the checkout returned to the session branch', 'clash' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD') && SessionFile::exists($d));
check('main is untouched by the failed merge', sh($d, 'rev-parse', 'main') === $mainBefore);
check('the working tree has no conflict markers', "branch side\n" === file_get_contents($d.'/src/a.txt'));

// the checkout must be on the session branch
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'off', 'code', $g);
Git::run($d, ['checkout', '-q', 'main']);
$r = SessionCommit::run($d, 'patch', $g);
check('commit refuses when the checkout is not on the session branch', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'not on the session branch'));

// ---------------------------------------------------------------- identity
$d = repo(codeGate([$pass]));
$g = Git::in($d);
Git::run($d, ['config', 'user.name', 'Configured Person']);
SessionStart::run($d, 'id', 'code', $g);
file_put_contents($d.'/src/a.txt', "id\n");
$r = SessionCommit::run($d, 'patch', $g);
check('a configured user.name is not overridden', 0 === $r['exit_code'] && 'Configured Person' === sh($d, 'log', '-1', '--format=%an', 'main'), json_encode($r));
check('the missing email is supplied', 'arch-session@localhost' === sh($d, 'log', '-1', '--format=%ae', 'main'));

// ---------------------------------------------------------------- discard
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'toss', 'code', $g);
file_put_contents($d.'/src/a.txt', "unsaved\n");
file_put_contents($d.'/src/b.txt', "also\n");
$mainBefore = sh($d, 'rev-parse', 'main');
$r = SessionDiscarder::run($d, $g, 1000000);
check('discard succeeds', 0 === $r['exit_code'], json_encode($r));
check('discard moves the branch under discarded/', '' === sh($d, 'branch', '--list', 'toss') && '' !== sh($d, 'branch', '--list', 'discarded/toss'));
check('checkout is on main with a clean tree', 'main' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD') && '' === sh($d, 'status', '--porcelain'));
check('main is unchanged', sh($d, 'rev-parse', 'main') === $mainBefore);
check('uncommitted changes were saved on the discarded branch', 'unsaved' === sh($d, 'show', 'discarded/toss:src/a.txt') && 'also' === sh($d, 'show', 'discarded/toss:src/b.txt'));
check('discard marker tag exists', 'discard-marker/discarded/toss/1000000' === sh($d, 'tag', '--list', 'discard-marker/*'));
check('session file removed', !SessionFile::exists($d));
check('output names arch_session_recover', str_contains($r['stdout'], 'Recover with: arch_session_recover toss'));
check('the lane is recorded with the branch', 'code' === sh($d, 'config', 'branch.discarded/toss.archlane'));
$r = SessionDiscarder::run($d, $g);
check('discard with no session is refused', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'No active session to discard'));

// recover
$r = SessionRecover::run($d, 'toss', $g);
check('recover succeeds', 0 === $r['exit_code'], json_encode($r));
$s = SessionFile::read($d);
check('recover restores the session with its lane and the recovered flag', $s && 'toss' === $s['branch'] && 'code' === $s['lane'] && true === ($s['recovered'] ?? null));
check('recover checks the branch out with the saved work', 'toss' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD') && "unsaved\n" === file_get_contents($d.'/src/a.txt'));
check('recover removes the marker tags', '' === sh($d, 'tag', '--list', 'discard-marker/*'));
check('recover clears the lane record', '' === sh($d, 'config', '--get', 'branch.toss.archlane'));
check('base commit is the merge base', $s && sh($d, 'rev-parse', 'main') === $s['base_commit']);
$r = SessionRecover::run($d, 'toss', $g);
check('recover is refused while a session is active', 0 !== $r['exit_code'] && str_contains($r['stderr'], 'already active'));

// the recovered session commits under its own lane
$r = SessionCommit::run($d, 'patch', $g);
check('a recovered code session commits through the code gate', 0 === $r['exit_code'] && str_contains($r['stdout'], '-- gate: ok'), json_encode($r));
check('the recovered work reached main', 'unsaved' === sh($d, 'show', 'main:src/a.txt'));

$r = SessionRecover::run($d, 'nothing-here', $g);
check('recover of an unknown name is refused', 0 !== $r['exit_code'] && str_contains($r['stderr'], "No discarded branch found matching 'nothing-here'"));
$r = SessionRecover::run($d, '--evil', $g);
check('recover refuses a flag-shaped name', 0 !== $r['exit_code']);

// recover with an explicit lane, and a branch with no recorded lane
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'old', 'code', $g);
SessionDiscarder::run($d, $g);
Git::run($d, ['config', '--unset', 'branch.discarded/old.archlane']);
$r = SessionRecover::run($d, 'old', $g);
check('no recorded lane: recover falls back to metadata and says so', 0 === $r['exit_code'] && 'metadata' === SessionFile::read($d)['lane'] && str_contains($r['stdout'], 'was not recorded'));
SessionDiscarder::run($d, $g);
Git::run($d, ['config', '--unset', 'branch.discarded/old.archlane']);
$r = SessionRecover::run($d, 'old', $g, 'code');
check('an explicit lane wins', 0 === $r['exit_code'] && 'code' === SessionFile::read($d)['lane'] && !str_contains($r['stdout'], 'was not recorded'));

// reused name: the older safety branch is kept
$d = repo(codeGate([$pass]));
$g = Git::in($d);
SessionStart::run($d, 'same', 'code', $g);
file_put_contents($d.'/src/a.txt', "first\n");
$recent = time() - 3600; // inside the 7-day window, so the purge after the second discard leaves it alone
SessionDiscarder::run($d, $g, $recent);
SessionStart::run($d, 'same', 'code', $g);
file_put_contents($d.'/src/a.txt', "second\n");
$r = SessionDiscarder::run($d, $g, $recent + 60);
check('a reused name discards without error', 0 === $r['exit_code'] && str_contains($r['stdout'], 'An older discarded branch with the same name was kept as'), json_encode($r));
check('both safety branches exist', 2 === count(array_filter(explode("\n", sh($d, 'branch', '--list', 'discarded/same*')))));
check('the newer work is on discarded/same', 'second' === sh($d, 'show', 'discarded/same:src/a.txt'));

// ---------------------------------------------------------------- purge
$d = repo(codeGate([$pass]));
$g = Git::in($d);
foreach (['aged', 'fresh'] as $n) {
    Git::run($d, ['branch', 'discarded/'.$n]);
}
Git::run($d, ['tag', 'discard-marker/discarded/aged/1000']);
Git::run($d, ['tag', 'discard-marker/discarded/fresh/'.(1000 + 6 * 86400)]);
Git::run($d, ['tag', 'discard-marker/discarded/weird/notanumber']);
$lines = SessionPurge::run($g, 1000 + 7 * 86400);
check('a branch 7 days old is purged', '' === sh($d, 'branch', '--list', 'discarded/aged') && '' === sh($d, 'tag', '--list', 'discard-marker/discarded/aged/*'));
check('a branch under 7 days old is kept', '' !== sh($d, 'branch', '--list', 'discarded/fresh'));
check('a marker with no epoch is ignored', 'discard-marker/discarded/weird/notanumber' === sh($d, 'tag', '--list', 'discard-marker/discarded/weird/*'));
check('purge reports what it removed', 1 === count($lines) && str_contains($lines[0], "Purged expired discarded branch 'discarded/aged'"));

// discard purges expired branches
$d = repo(codeGate([$pass]));
$g = Git::in($d);
Git::run($d, ['branch', 'discarded/ancient']);
Git::run($d, ['tag', 'discard-marker/discarded/ancient/1000']);
SessionStart::run($d, 'now', 'code', $g);
$r = SessionDiscarder::run($d, $g);
check('discard purges expired safety branches', '' === sh($d, 'branch', '--list', 'discarded/ancient') && str_contains($r['stdout'], 'Purged expired'));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
