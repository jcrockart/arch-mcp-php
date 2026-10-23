<?php

namespace ArchMcp;

require_once __DIR__.'/Git.php';
require_once __DIR__.'/SessionLaneGuard.php';

/**
 * Versioning for committed sessions, and the default lane for one-lane
 * projects.
 *
 * THE RULE (one rule, in GateConfig): a lane's commits are versioned when its
 * arch-gate.json entry says "versioned": true, or, with no such key, when it
 * is the metadata lane or its stage paths include metadata/.
 *
 * THE STEPS, same as arch.py took for the metadata lane: bump VERSION.json
 * (a missing file starts from 0.1.0), commit it on main as "Bump version to
 * vX.Y.Z" and tag vX.Y.Z. plan() works out the next version before the merge
 * so a bad VERSION.json or an existing tag stops the commit early; write()
 * does the steps after the merge. A failure in write() cannot undo the merge,
 * so the caller reports it as a warning.
 *
 * The default lane follows the same fact: `code` when the project's code lane
 * stages metadata/, otherwise `metadata`.
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

    /** Retired with the switch to the built-in session engine (GateConfig decides now). */
    public static function needsBump(string $repoPath, string $lane): bool
    {
        return '' !== $lane && 'metadata' !== $lane && SessionLaneGuard::stagesMetadata($repoPath, $lane);
    }

    /**
     * Retired with the switch to the built-in session engine: plan() then write().
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
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
        $plan = self::plan($git, $repoPath, $bump);
        if (true !== $plan['ok']) {
            return $plan;
        }

        return self::write($git, $repoPath, $plan['version'], $plan['tag']);
    }

    /**
     * The next version for a bump, checked but not written.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{ok: true, version: array{major: int, minor: int, patch: int}, tag: string}|array{ok: false, error: string}
     */
    public static function plan(callable $git, string $repoPath, string $bump): array
    {
        if (!\in_array($bump, ['major', 'minor', 'patch'], true)) {
            return ['ok' => false, 'error' => "invalid bump '{$bump}'"];
        }

        $file = $repoPath.'/VERSION.json';
        $v = ['major' => 0, 'minor' => 1, 'patch' => 0];
        if (is_file($file)) {
            $d = json_decode((string) file_get_contents($file), true);
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
            ++$v['patch'];
        }
        $tag = "v{$v['major']}.{$v['minor']}.{$v['patch']}";

        if (0 === $git(['rev-parse', '-q', '--verify', "refs/tags/{$tag}"])['exit_code']) {
            return ['ok' => false, 'error' => "tag {$tag} already exists"];
        }

        return ['ok' => true, 'version' => $v, 'tag' => $tag];
    }

    /**
     * Write VERSION.json, commit it on the checked-out branch (main, after
     * the merge) and tag it. On a failure before the commit nothing is left
     * half done in the working tree.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     * @param array{major: int, minor: int, patch: int}                                     $v
     *
     * @return array{ok: true, tag: string}|array{ok: false, error: string}
     */
    public static function write(callable $git, string $repoPath, array $v, string $tag): array
    {
        $file = $repoPath.'/VERSION.json';
        $had = is_file($file);
        $prior = $had ? (string) file_get_contents($file) : '';

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
        $commit = $git(array_merge(Git::identity($git, 'ARCH session commit'), ['commit', '-q', '-m', "Bump version to {$tag}", '--', 'VERSION.json']));
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
