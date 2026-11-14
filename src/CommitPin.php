<?php

namespace ArchMcp;

/**
 * One named commit, used in place of the live origin tip (chat promote-to-production,
 * step 1; Decisions 5, 7 and 9 in the ARCH working doc).
 *
 * WHY: Portal's check, the migration apply and the pull are separate calls. If each of
 * them reads "whatever origin is right now", origin can move between them, so what was
 * judged and what is pulled can differ. With `atCommit`, every call names the same commit
 * and only that commit is judged, applied and pulled.
 *
 * SAFETY (this relaxes the pull tool's old "no caller-supplied argument" rule on purpose):
 *   - The value must be exactly 40 lowercase hex characters, checked before any git
 *     process starts. It can therefore never be a flag, a branch name, a path or a free
 *     ref, and it is only ever passed to git as a commit id.
 *   - The commit must exist here after `git fetch origin`, must be reachable from
 *     origin/<pull_branch> (so it is on origin, never a local-only commit), and, unless a
 *     caller says it does not need it, production's HEAD must be an ancestor of it (a
 *     fast-forward). A commit rewritten away on origin fails the reachability check.
 *   - Every git argument is hardcoded; the only profile-supplied value is the branch
 *     name (validated, used only as the suffix of "origin/").
 *   - Nothing here writes to the working tree except pull(), which runs
 *     `merge --ff-only <that commit>` and nothing else.
 *
 * HONESTY: a refusal always carries a machine `reason` and a plain `error`. A failed fetch
 * is a refusal (fetch_failed), never read as "fine".
 */
final class CommitPin
{
    private const PATTERN = '/^[0-9a-f]{40}\z/';

    public const NOT_VALID = 'atCommit must be the full 40-character lowercase commit id (not a short id, a branch or a tag)';

    public static function isValid(string $sha): bool
    {
        return 1 === preg_match(self::PATTERN, $sha);
    }

    /**
     * Is this commit one that may be judged, applied or pulled in this checkout?
     *
     * @param bool $requireClean       refuse unless the working tree is clean (apply and pull need it)
     * @param bool $requireFastForward refuse unless HEAD is an ancestor of the commit
     * @param bool $fetch              run `git fetch origin` first (false when the caller has just done so)
     *
     * @return array{ok: true, sha: string}|array{ok: false, reason: string, error: string}
     */
    public static function verify(string $root, string $pullBranch, string $sha, bool $requireClean = false, bool $requireFastForward = true, bool $fetch = true): array
    {
        if (!self::isValid($sha)) {
            return self::refuse('invalid_commit', self::NOT_VALID);
        }
        if (1 !== preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $pullBranch) || str_contains($pullBranch, '..')) {
            return self::refuse('invalid_branch', 'this profile has an invalid pull_branch');
        }
        if (!is_dir($root)) {
            return self::refuse('no_checkout', 'the checkout directory does not exist');
        }
        $ref = 'origin/'.$pullBranch;

        if ($fetch) {
            $f = self::git($root, ['fetch', 'origin']);
            if (0 !== $f['exit_code']) {
                return self::refuse('fetch_failed', 'git fetch origin failed, so the commit cannot be checked against origin: '.trim($f['stderr']));
            }
        }

        $known = self::git($root, ['cat-file', '-e', $sha.'^{commit}']);
        if (0 !== $known['exit_code']) {
            return self::refuse('commit_not_on_origin', "commit {$sha} is not known here after fetching origin, so it is not on {$ref}");
        }
        $origin = self::git($root, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}']);
        if (0 !== $origin['exit_code'] || '' === trim($origin['stdout'])) {
            return self::refuse('origin_ref_unknown', "{$ref} is not known in this checkout");
        }
        $onOrigin = self::git($root, ['merge-base', '--is-ancestor', $sha, $ref]);
        if (1 === $onOrigin['exit_code']) {
            return self::refuse('commit_not_on_origin', "commit {$sha} exists here but is not reachable from {$ref}");
        }
        if (0 !== $onOrigin['exit_code']) {
            return self::refuse('git_error', 'could not check the commit against '.$ref.': '.trim($onOrigin['stderr']));
        }

        if ($requireFastForward) {
            $ff = self::git($root, ['merge-base', '--is-ancestor', 'HEAD', $sha]);
            if (1 === $ff['exit_code']) {
                return self::refuse('not_fast_forward', "production's HEAD is not an ancestor of {$sha}, so it cannot be fast-forwarded to it");
            }
            if (0 !== $ff['exit_code']) {
                return self::refuse('git_error', 'could not compare HEAD with the commit: '.trim($ff['stderr']));
            }
        }

        if ($requireClean) {
            $status = self::git($root, ['status', '--porcelain']);
            if (0 !== $status['exit_code']) {
                return self::refuse('git_error', 'could not read the working tree state: '.trim($status['stderr']));
            }
            if ('' !== trim($status['stdout'])) {
                return self::refuse('tree_dirty', 'working tree is not clean -- refusing to go on');
            }
        }

        return ['ok' => true, 'sha' => $sha];
    }

    /**
     * Fast-forward the checkout to exactly this commit, and to nothing else. Same reply
     * shape as the unpinned pull (exit_code, stdout, stderr, before, after, pulled), plus
     * `target` (the commit asked for) and, on a refusal, `reason`. Already at the commit is
     * exit_code 0 with pulled false.
     *
     * @return array<string, mixed>
     */
    public static function pull(string $root, string $pullBranch, string $sha): array
    {
        $v = self::verify($root, $pullBranch, $sha, true, true, true);
        if (!$v['ok']) {
            $out = ['exit_code' => 1, 'stdout' => '', 'stderr' => $v['error'], 'reason' => $v['reason']];
            if (self::isValid($sha)) {
                $out['target'] = $sha;
            }

            return $out;
        }

        $before = trim(self::git($root, ['rev-parse', 'HEAD'])['stdout']);
        $merge = self::git($root, ['merge', '--ff-only', $sha]);
        $after = trim(self::git($root, ['rev-parse', 'HEAD'])['stdout']);

        $merge['before'] = $before;
        $merge['after'] = $after;
        $merge['pulled'] = 0 === $merge['exit_code'] && $before !== $after;
        $merge['target'] = $sha;
        if (0 !== $merge['exit_code']) {
            $merge['reason'] = 'merge_failed';
        }

        return $merge;
    }

    /**
     * @return array{ok: false, reason: string, error: string}
     */
    private static function refuse(string $reason, string $error): array
    {
        return ['ok' => false, 'reason' => $reason, 'error' => $error];
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private static function git(string $root, array $args): array
    {
        $command = array_merge(['git'], $args);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Never let a fetch stop to ask for credentials: fail instead.
        $env = array_merge(getenv() ?: [], ['GIT_TERMINAL_PROMPT' => '0']);
        $process = proc_open($command, $descriptorSpec, $pipes, $root, $env);
        if (!\is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed to spawn git'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
