<?php

namespace ArchMcp;

require_once __DIR__.'/ClassFileCheck.php';
require_once __DIR__.'/Git.php';
require_once __DIR__.'/SessionDiscard.php';
require_once __DIR__.'/GateConfig.php';
require_once __DIR__.'/GateRunner.php';
require_once __DIR__.'/SessionFile.php';
require_once __DIR__.'/SessionResult.php';
require_once __DIR__.'/SessionVersion.php';

/**
 * Commits the active session: the one hard gate.
 *
 * Order: read the lane's gate config; for a versioned lane, work out the next
 * version (a bad VERSION.json or an existing tag stops the commit before
 * anything runs); run the gate steps, stopping at the first failure; commit
 * the lane's stage paths on the session branch; merge it into main with
 * --no-ff; delete the branch and the session file; then, for a versioned
 * lane, write VERSION.json, commit and tag.
 *
 * A failure before the merge leaves the session branch open and main
 * untouched. A merge that fails is aborted and the checkout returns to the
 * session branch. A failure after the merge (the version bump) cannot undo
 * it, so it is reported as a WARNING and the result carries 'version'.
 */
final class SessionCommit
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{exit_code: int, stdout: string, stderr: string, version?: array<string, mixed>}
     */
    public static function run(string $repoPath, string $bump, callable $git): array
    {
        if (!\in_array($bump, ['major', 'minor', 'patch'], true)) {
            return SessionResult::fail("invalid bump '{$bump}'");
        }
        try {
            $session = SessionFile::read($repoPath);
        } catch (\RuntimeException $e) {
            return SessionResult::fail($e->getMessage());
        }
        if (null === $session) {
            return SessionResult::fail('No active session. Run arch_session_start first.');
        }
        $branch = $session['branch'];
        $lane = $session['lane'];

        try {
            $config = GateConfig::load($repoPath, $lane);
        } catch (GateConfigException $e) {
            return SessionResult::fail($e->getMessage());
        }

        $current = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
        if (0 !== $current['exit_code'] || trim($current['stdout']) !== $branch) {
            return SessionResult::fail("the checkout is not on the session branch '{$branch}', so the commit was not attempted. Nothing has changed.");
        }

        $plan = null;
        if ($config['versioned']) {
            $plan = SessionVersion::plan($git, $repoPath, $bump);
            if (true !== $plan['ok']) {
                return SessionResult::fail("COMMIT ABORTED before any gate ran: the next version cannot be worked out ({$plan['error']}). Branch '{$branch}' remains open and unchanged. main is untouched.");
            }
        }

        // A project that loads its src/ classes from src/bootstrap.php gets one more step: every
        // class file must be required from it (ARCH-85). Added here, not in arch-gate.json, so it
        // covers every project without anyone editing their gate file.
        $steps = $config['gate'];
        if (ClassFileCheck::applies($repoPath) && self::touchesSrc($config['stage']['paths'])) {
            $steps[] = ['name' => ClassFileCheck::STEP, 'builtin' => ClassFileCheck::STEP];
        }

        $gate = GateRunner::run($repoPath, $steps, $branch, $lane);
        if (!$gate['ok']) {
            return SessionResult::fail('', $gate['output']);
        }
        $out = GateRunner::passTranscript($gate['steps'], $branch, $lane);

        // Commit the session's own changes onto its branch first, so the
        // merge brings them to main. An empty session is valid.
        $stage = $config['stage'];
        $paths = array_values(array_filter($stage['paths'], static fn (string $p): bool => file_exists($repoPath.'/'.$p)));
        if ([] !== $paths) {
            $add = $git(array_merge(['add', '-A', '--'], $paths));
            if (0 !== $add['exit_code']) {
                return SessionResult::fail('git add failed: '.trim($add['stderr'])." Branch '{$branch}' remains open.", $out);
            }
            $status = $git(array_merge(['status', '--porcelain', '--'], $paths));
            if (0 !== $status['exit_code']) {
                return SessionResult::fail('git status failed: '.trim($status['stderr'])." Branch '{$branch}' remains open.", $out);
            }
            if ('' !== trim($status['stdout'])) {
                $commit = $git(array_merge(Git::identity($git, 'ARCH session commit'), ['commit', '-q', '-m', "Session {$stage['label']}: {$branch}"]));
                if (0 !== $commit['exit_code']) {
                    return SessionResult::fail('git commit failed: '.trim($commit['stderr'].' '.$commit['stdout'])." Branch '{$branch}' remains open.", $out);
                }
            }
        }

        $checkout = $git(['checkout', '-q', 'main']);
        if (0 !== $checkout['exit_code']) {
            return SessionResult::fail('could not switch to main: '.trim($checkout['stderr'])." Branch '{$branch}' remains open and main is untouched.", $out);
        }
        $message = null !== $plan ? "Session commit: {$branch} -> {$plan['tag']}" : "Session commit ({$lane}): {$branch}";
        $merge = $git(array_merge(Git::identity($git, 'ARCH session commit'), ['merge', '--no-ff', '-q', $branch, '-m', $message]));
        if (0 !== $merge['exit_code']) {
            $git(['merge', '--abort']);
            $back = $git(['checkout', '-q', $branch]);

            return SessionResult::fail('the merge into main failed and was aborted: '.trim($merge['stderr'].' '.$merge['stdout'])
                .(0 === $back['exit_code'] ? " The checkout is back on '{$branch}', which remains open. main is untouched." : " The checkout could not return to '{$branch}': ".trim($back['stderr']).' Check the repository by hand.'), $out);
        }

        $deleted = $git(['branch', '-d', $branch]);
        SessionFile::delete($repoPath);

        $result = SessionResult::ok('');
        $tail = null !== $plan ? ". Session '{$branch}' merged to main and retired.\n" : ". Session '{$branch}' (lane: {$lane}) merged to main and retired.\n";
        $note = 0 !== $deleted['exit_code'] ? "WARNING: the branch '{$branch}' could not be deleted: ".trim($deleted['stderr'])."\n" : '';

        if (null !== $plan) {
            $version = SessionVersion::write($git, $repoPath, $plan['version'], $plan['tag']);
            $result['version'] = $version;
            if ($version['ok']) {
                $result['stdout'] = $out."\nCOMMITTED as {$plan['tag']}".$tail.$note;

                return $result;
            }
            $result['stdout'] = $out."\nCOMMITTED".$tail.$note."WARNING: the session was merged, but the version bump failed ({$version['error']}); main has no new version.\n";

            return $result;
        }

        $result['stdout'] = $out."\nCOMMITTED".$tail.$note;

        return $result;
    }

    /**
     * The most recently changed file in the session's stage paths, if it changed
     * within $window second(s) before $now (ARCH-86: an edit and a commit sent in
     * the same turn). Calls sent together can run in any order, so a commit that
     * finds a file changed a moment ago cannot tell whether the caller has seen
     * that edit land. The arch_session_commit tool refuses once in that case; a
     * second call a few seconds later goes through.
     *
     * Only files git already reports as changed are looked at, so the checkout
     * of a branch (which touches files that differ) does not count.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{path: string, age: int}|null
     */
    public static function recentWrite(string $repoPath, callable $git, int $now, int $window = 1): ?array
    {
        try {
            $session = SessionFile::read($repoPath);
        } catch (\RuntimeException) {
            return null;
        }
        if (null === $session) {
            return null;
        }
        $paths = SessionDiscard::stagePaths($repoPath, $session['lane']);
        if ([] === $paths) {
            return null;
        }
        $status = $git(array_merge(['status', '--porcelain', '-uall', '--'], $paths));
        if (0 !== $status['exit_code']) {
            return null;
        }
        $newest = null;
        foreach (explode("\n", $status['stdout']) as $line) {
            if (\strlen($line) < 4) {
                continue;
            }
            $path = trim(substr($line, 3), '"');
            if (str_contains($path, ' -> ')) {
                $path = substr($path, (int) strrpos($path, ' -> ') + 4);
            }
            $mtime = @filemtime($repoPath.'/'.$path);
            if (false !== $mtime && (null === $newest || $mtime > $newest['mtime'])) {
                $newest = ['path' => $path, 'mtime' => $mtime];
            }
        }
        if (null === $newest || $now - $newest['mtime'] > $window) {
            return null;
        }

        return ['path' => $newest['path'], 'age' => max(0, $now - $newest['mtime'])];
    }

    /**
     * @param list<string> $paths the lane's stage paths
     */
    private static function touchesSrc(array $paths): bool
    {
        foreach ($paths as $p) {
            $p = trim(str_replace('\\', '/', $p), '/');
            if ('src' === $p || str_starts_with($p, 'src/')) {
                return true;
            }
        }

        return false;
    }
}
