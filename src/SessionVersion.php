<?php

namespace ArchMcp;

require_once __DIR__.'/SessionLaneGuard.php';

/**
 * Versioning and the default lane for one-lane projects, added 2026-10-16.
 *
 * THE GAP: arch.py (every project has its own copy) bumps the version, writes
 * VERSION.json and tags vX.Y.Z only when the session's lane is literally
 * "metadata". A project built from Portal's Bootstrap template has ONE `code`
 * lane that stages metadata/ as well as app code, so its commits carried
 * metadata but were never versioned. And arch_session_start still defaulted
 * to lane "metadata", which cannot write app code there.
 *
 * THE RULE (decided by James): a commit that carries metadata is versioned,
 * whichever lane it ran in. "Carries metadata" means the lane's stage paths in
 * arch-gate.json include metadata/. So:
 *   - the metadata lane: arch.py versions it, exactly as before;
 *   - a `code` lane that stages metadata/ (one-lane projects): versioned here;
 *   - a `code` lane without metadata/ (two-lane and framework projects): not.
 * The default lane follows the same fact: `code` when the project's code lane
 * stages metadata/, otherwise `metadata`.
 *
 * WHY HERE AND NOT IN arch.py: the copies in existing projects are not in any
 * lane's stage paths and Portal's Bootstrap never overwrites them, so a change
 * to arch.py would not reach them. This server serves every project, the same
 * reason SessionDiscard lives here. The bump runs right after arch.py's merge,
 * on main, and is skipped when arch.py already versioned the commit (a newer
 * arch.py printing "COMMITTED as vX.Y.Z"), so the two never double-bump.
 */
final class SessionVersion
{
    /**
     * Lane for a session start with no lane given. "Stages metadata/" is
     * SessionLaneGuard::stagesMetadata, the one definition of the rule.
     *
     * @param array<string, string> $lanes the caller's lane map (name => subdir)
     */
    public static function defaultLane(string $repoPath, array $lanes): string
    {
        return isset($lanes['code']) && SessionLaneGuard::stagesMetadata($repoPath, 'code') ? 'code' : 'metadata';
    }

    /** Should this server version a commit made in $lane? (The metadata lane is arch.py's own.) */
    public static function needsBump(string $repoPath, string $lane): bool
    {
        return '' !== $lane && 'metadata' !== $lane && SessionLaneGuard::stagesMetadata($repoPath, $lane);
    }

    /**
     * Bump VERSION.json, commit it on main and tag it, the same steps arch.py
     * takes for the metadata lane (missing VERSION.json starts from 0.1.0).
     * On any failure nothing is left half done in the working tree.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git runs `git <args>` in the checkout
     *
     * @return array{ok: true, tag: string}|array{ok: false, error: string}
     */
    public static function apply(callable $git, string $repoPath, string $bump): array
    {
        if (!\in_array($bump, ['major', 'minor', 'patch'], true)) {
            return ['ok' => false, 'error' => "invalid bump '{$bump}'"];
        }
        $branch = $git(['rev-parse', '--abbrev-ref', 'HEAD']);
        if (0 !== $branch['exit_code'] || 'main' !== trim($branch['stdout'])) {
            return ['ok' => false, 'error' => 'the checkout is not on main after the merge'];
        }

        $file = $repoPath.'/VERSION.json';
        $had = is_file($file);
        $prior = $had ? (string) file_get_contents($file) : '';
        $v = ['major' => 0, 'minor' => 1, 'patch' => 0];
        if ($had) {
            $d = json_decode($prior, true);
            if (!\is_array($d) || !\is_int($d['major'] ?? null) || !\is_int($d['minor'] ?? null) || !\is_int($d['patch'] ?? null)) {
                return ['ok' => false, 'error' => 'VERSION.json is not {"major","minor","patch"} integers'];
            }
            $v = ['major' => $d['major'], 'minor' => $d['minor'], 'patch' => $d['patch']];
        }
        if ('major' === $bump) {
            $v = ['major' => $v['major'] + 1, 'minor' => 0, 'patch' => 0];
        } elseif ('minor' === $bump) {
            $v = ['major' => $v['major'], 'minor' => $v['minor'] + 1, 'patch' => 0];
        } else {
            $v['patch']++;
        }
        $tag = "v{$v['major']}.{$v['minor']}.{$v['patch']}";

        if (0 === $git(['rev-parse', '-q', '--verify', "refs/tags/{$tag}"])['exit_code']) {
            return ['ok' => false, 'error' => "tag {$tag} already exists"];
        }

        // Same bytes as arch.py's json.dumps(version, indent=2).
        $json = "{\n  \"major\": {$v['major']},\n  \"minor\": {$v['minor']},\n  \"patch\": {$v['patch']}\n}";
        $undo = static function () use ($git, $file, $had, $prior): void {
            $git(['reset', '-q', '--', 'VERSION.json']);
            if ($had) {
                file_put_contents($file, $prior);
            } elseif (is_file($file)) {
                unlink($file);
            }
        };
        if (false === file_put_contents($file, $json)) {
            return ['ok' => false, 'error' => 'could not write VERSION.json'];
        }

        $add = $git(['add', '--', 'VERSION.json']);
        if (0 !== $add['exit_code']) {
            $undo();

            return ['ok' => false, 'error' => 'git add failed: '.trim($add['stderr'])];
        }
        // A checkout with no git identity cannot commit: supply the missing half only.
        $identity = [];
        if ('' === trim($git(['config', 'user.name'])['stdout'])) {
            array_push($identity, '-c', 'user.name=ARCH session commit');
        }
        if ('' === trim($git(['config', 'user.email'])['stdout'])) {
            array_push($identity, '-c', 'user.email=arch-session@localhost');
        }
        $commit = $git(array_merge($identity, ['commit', '-q', '-m', "Bump version to {$tag}", '--', 'VERSION.json']));
        if (0 !== $commit['exit_code']) {
            $undo();

            return ['ok' => false, 'error' => 'git commit failed: '.trim($commit['stderr'].' '.$commit['stdout'])];
        }
        $tagged = $git(['tag', $tag]);
        if (0 !== $tagged['exit_code']) {
            return ['ok' => false, 'error' => "the bump was committed but tagging {$tag} failed: ".trim($tagged['stderr'])];
        }

        return ['ok' => true, 'tag' => $tag];
    }
}
