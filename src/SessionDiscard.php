<?php

namespace ArchMcp;

/**
 * Saves a session's uncommitted work before it is discarded, added 2026-10-06.
 *
 * THE GAP: arch.py's `session discard` checks out main and renames the session
 * branch to discarded/<name> (kept 7 days, "Recover with: arch session
 * recover"). It never touches uncommitted edits. git carries them across the
 * checkout, so they ended up as uncommitted changes on main's working tree
 * (the "no trace" message was untrue) and were NOT on the discarded branch,
 * so `recover` could not bring them back.
 *
 * THE FIX, here rather than in arch.py (every project has its own copy of
 * arch.py, so the one place that serves all of them is this server): before
 * calling discard, commit whatever the session changed inside its lane's
 * stage paths onto the session branch. The branch then holds the work (so
 * recover works), and main's working tree is left clean.
 *
 * Only the lane's own stage paths (arch-gate.json <lane>.stage.paths, or
 * ['metadata'] for the metadata lane with no config, the same defaults
 * arch.py uses for `session commit`) are saved. A session with nothing
 * changed there makes no commit. If the snapshot cannot be made the caller
 * must NOT discard: reporting that is better than leaving unsaved work
 * stranded on main.
 *
 * GIT IDENTITY: a checkout with no user.name or user.email configured cannot
 * make the snapshot commit (found 2026-10-06 on a provisioned test project,
 * where discard refused with "Author identity unknown"). The commit now
 * supplies a fallback for whichever of the two is missing, and never
 * overrides one that is configured.
 *
 * BRANCH-NAME COLLISION: arch.py renames the session branch to
 * discarded/<name> and fails if that already exists (a session name reused
 * after an earlier discard within the 7-day grace window). It also tags the
 * branch discard-marker/discarded/<name>/<epoch>, and its purge deletes the
 * branch NAMED in an old tag once that tag is 7 days old, which would delete
 * the newer branch of the same name. freeDiscardName() moves any existing
 * discarded/<name> branch, and its marker tags, to a unique name first. Done
 * here, not in arch.py, for the same reason as the snapshot.
 */
final class SessionDiscard
{
    /**
     * Stage paths for a lane that exist on disk right now.
     *
     * @return list<string>
     */
    public static function stagePaths(string $repoPath, string $lane): array
    {
        $paths = null;
        $gate = $repoPath.'/arch-gate.json';
        if (is_file($gate)) {
            $cfg = json_decode((string) file_get_contents($gate), true);
            if (\is_array($cfg) && \is_array($cfg[$lane]['stage']['paths'] ?? null)) {
                $paths = array_values(array_filter($cfg[$lane]['stage']['paths'], 'is_string'));
            }
        }
        if (null === $paths && 'metadata' === $lane) {
            $paths = ['metadata'];
        }

        return array_values(array_filter($paths ?? [], static fn (string $p): bool => '' !== $p && !str_starts_with($p, '-') && !str_contains($p, '..') && file_exists($repoPath.'/'.$p)));
    }

    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git runs `git <args>` in the checkout
     *
     * @return array{ok: true, snapshotted: bool, files: int}|array{ok: false, error: string}
     */
    public static function snapshot(callable $git, string $repoPath): array
    {
        $sessionFile = $repoPath.'/.arch-session.json';
        if (!is_file($sessionFile)) {
            return ['ok' => true, 'snapshotted' => false, 'files' => 0];
        }
        $session = json_decode((string) file_get_contents($sessionFile), true);
        if (!\is_array($session) || !\is_string($session['branch'] ?? null) || '' === $session['branch']) {
            return ['ok' => true, 'snapshotted' => false, 'files' => 0];
        }
        $branch = $session['branch'];
        $lane = \is_string($session['lane'] ?? null) ? $session['lane'] : 'metadata';

        $paths = self::stagePaths($repoPath, $lane);
        if ([] === $paths) {
            return ['ok' => true, 'snapshotted' => false, 'files' => 0];
        }

        $current = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
        if (0 !== $current['exit_code']) {
            return ['ok' => false, 'error' => 'could not read the current branch: '.trim($current['stderr'])];
        }
        if (trim($current['stdout']) !== $branch) {
            return ['ok' => false, 'error' => "the checkout is on '".trim($current['stdout'])."', not the session branch '{$branch}', so its changes cannot be saved safely"];
        }

        $add = $git(array_merge(['add', '-A', '--'], $paths));
        if (0 !== $add['exit_code']) {
            return ['ok' => false, 'error' => 'git add failed: '.trim($add['stderr'])];
        }
        $status = $git(array_merge(['status', '--porcelain', '--'], $paths));
        if (0 !== $status['exit_code']) {
            return ['ok' => false, 'error' => 'git status failed: '.trim($status['stderr'])];
        }
        $changed = array_filter(explode("\n", $status['stdout']), static fn (string $l): bool => '' !== trim($l));
        if ([] === $changed) {
            return ['ok' => true, 'snapshotted' => false, 'files' => 0];
        }

        // A checkout with no git identity (some provisioned projects) cannot commit at all. Supply a
        // fallback for the missing half only; a configured name or email is never overridden.
        $identity = [];
        if ('' === trim($git(['config', 'user.name'])['stdout'])) {
            array_push($identity, '-c', 'user.name=ARCH session discard');
        }
        if ('' === trim($git(['config', 'user.email'])['stdout'])) {
            array_push($identity, '-c', 'user.email=arch-session@localhost');
        }
        $commit = $git(array_merge($identity, ['commit', '-q', '-m', "Discarded session snapshot: {$branch}"]));
        if (0 !== $commit['exit_code']) {
            return ['ok' => false, 'error' => 'git commit failed: '.trim($commit['stderr'].' '.$commit['stdout'])];
        }

        return ['ok' => true, 'snapshotted' => true, 'files' => \count($changed)];
    }

    /**
     * Make discarded/<session branch> free for arch.py. An older safety branch
     * of that name is renamed to discarded/<name>-<epoch>[-n] and its marker
     * tags are re-pointed to the new name (keeping their original time, so the
     * 7-day purge still applies to the old work and never to the new).
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{ok: true, moved: ?string}|array{ok: false, error: string}
     */
    public static function freeDiscardName(callable $git, string $repoPath): array
    {
        $sessionFile = $repoPath.'/.arch-session.json';
        if (!is_file($sessionFile)) {
            return ['ok' => true, 'moved' => null];
        }
        $session = json_decode((string) file_get_contents($sessionFile), true);
        if (!\is_array($session) || !\is_string($session['branch'] ?? null) || '' === $session['branch']) {
            return ['ok' => true, 'moved' => null];
        }
        $target = 'discarded/'.$session['branch'];
        if (0 !== $git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$target])['exit_code']) {
            return ['ok' => true, 'moved' => null];
        }

        $stamp = (string) time();
        $new = $target.'-'.$stamp;
        for ($n = 2; 0 === $git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$new])['exit_code'] && $n < 100; ++$n) {
            $new = $target.'-'.$stamp.'-'.$n;
        }
        $rename = $git(['branch', '-m', $target, $new]);
        if (0 !== $rename['exit_code']) {
            return ['ok' => false, 'error' => "an older discarded branch '{$target}' is in the way and could not be renamed: ".trim($rename['stderr'])];
        }
        $tags = $git(['tag', '--list', 'discard-marker/'.$target.'/*']);
        foreach (array_filter(explode("\n", $tags['stdout']), static fn (string $t): bool => '' !== trim($t)) as $tag) {
            $tag = trim($tag);
            $epoch = substr($tag, (int) strrpos($tag, '/') + 1);
            $git(['tag', 'discard-marker/'.$new.'/'.$epoch, $new]);
            $git(['tag', '-d', $tag]);
        }

        return ['ok' => true, 'moved' => $new];
    }
}
