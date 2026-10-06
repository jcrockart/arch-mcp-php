<?php

namespace ArchMcp;

use Psr\Log\LoggerInterface;

/**
 * Production-state report for one checkout, added 2026-10-06.
 *
 * WHY THIS EXISTS: the generic git read tools (status/diff/log/fetch) resolve
 * to a project's STAGING profile row since the per-tool profile selection
 * (see PortalProjectResolver::profilePlan). Portal's "Promote to production"
 * page used to rely on those tools to ask "is production behind origin?", so
 * for any project with a staging row it was silently asking about the
 * staging checkout and reporting "Up to date" while production was stale.
 * This report is the production-pinned replacement: ProjectsTools resolves
 * the PRODUCTION profile row and hands this class that row's checkout root
 * and pull_branch. Nothing here chooses an environment.
 *
 * HONESTY RULE: `up_to_date` is true only when the fetch succeeded AND the
 * checkout is not behind origin. If the fetch failed it is null (unknown),
 * never true, so a failed fetch can never read as "Up to date".
 *
 * SAFETY: read-only apart from `git fetch origin`, which only updates
 * refs/remotes/origin/*. Every git argument is hardcoded; the only
 * profile-supplied value is the branch name, validated below and only ever
 * used as the suffix of "origin/". The caller supplies one boolean.
 */
final class ProdState
{
    private const MAX_LIST = 200;
    private const MAX_DIFF_BYTES = 200000;

    /**
     * @return array<string, mixed>
     */
    public static function report(string $root, string $pullBranch, bool $includeDiff, LoggerInterface $logger): array
    {
        if (1 !== preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $pullBranch)) {
            return ['success' => false, 'error' => 'this profile has an invalid pull_branch'];
        }
        if (!is_dir($root)) {
            return ['success' => false, 'error' => 'the checkout directory does not exist'];
        }
        $remoteRef = 'origin/'.$pullBranch;

        $headResult = self::git($root, ['rev-parse', 'HEAD']);
        if (0 !== $headResult['exit_code']) {
            return ['success' => false, 'error' => 'could not read HEAD: '.trim($headResult['stderr'])];
        }
        $head = trim($headResult['stdout']);

        $fetch = self::git($root, ['fetch', 'origin']);
        $fetchOk = 0 === $fetch['exit_code'];

        $branchNow = trim(self::git($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);

        $status = self::git($root, ['status', '--porcelain']);
        $dirty = [];
        if (0 === $status['exit_code']) {
            foreach (explode("\n", $status['stdout']) as $line) {
                if ('' !== $line) {
                    $dirty[] = $line;
                }
            }
        }

        $result = [
            'success' => true,
            'branch' => $branchNow,
            'pull_branch' => $pullBranch,
            'head' => $head,
            'head_short' => substr($head, 0, 7),
            'clean' => 0 === $status['exit_code'] && [] === $dirty,
            'dirty_files' => \array_slice($dirty, 0, self::MAX_LIST),
            'fetch_ok' => $fetchOk,
        ];
        if (!$fetchOk) {
            $result['fetch_error'] = trim($fetch['stderr']);
        }

        $originResult = self::git($root, ['rev-parse', '--verify', '--quiet', $remoteRef]);
        if (0 !== $originResult['exit_code'] || '' === trim($originResult['stdout'])) {
            $result['origin_head'] = null;
            $result['up_to_date'] = null;
            $result['note'] = "{$remoteRef} is not known in this checkout";
            $logger->info('Production state (no origin ref).', ['pull_branch' => $pullBranch, 'fetch_ok' => $fetchOk]);

            return $result;
        }
        $originHead = trim($originResult['stdout']);
        $result['origin_head'] = $originHead;
        $result['origin_head_short'] = substr($originHead, 0, 7);

        $ahead = null;
        $behind = null;
        $counts = self::git($root, ['rev-list', '--left-right', '--count', 'HEAD...'.$remoteRef]);
        if (0 === $counts['exit_code']) {
            $parts = preg_split('/\s+/', trim($counts['stdout']));
            if (\is_array($parts) && 2 === \count($parts)) {
                $ahead = (int) $parts[0];
                $behind = (int) $parts[1];
            }
        }
        $result['ahead'] = $ahead;
        $result['behind'] = $behind;
        $result['up_to_date'] = $fetchOk && null !== $behind ? 0 === $behind : null;
        $result['can_fast_forward'] = null !== $ahead && 0 === $ahead;

        $incoming = [];
        $files = [];
        if (null !== $behind && $behind > 0) {
            $log = self::git($root, ['log', '--oneline', '-'.self::MAX_LIST, 'HEAD..'.$remoteRef]);
            if (0 === $log['exit_code']) {
                $incoming = self::lines($log['stdout']);
            }
            $names = self::git($root, ['diff', '--name-status', 'HEAD...'.$remoteRef]);
            if (0 === $names['exit_code']) {
                $files = self::lines($names['stdout']);
            }
        }
        $result['incoming_commits'] = $incoming;
        $result['changed_files'] = \array_slice($files, 0, self::MAX_LIST);

        if ($includeDiff && null !== $behind && $behind > 0) {
            $diff = self::git($root, ['diff', 'HEAD...'.$remoteRef]);
            if (0 === $diff['exit_code']) {
                $text = $diff['stdout'];
                $result['diff_truncated'] = \strlen($text) > self::MAX_DIFF_BYTES;
                $result['diff'] = $result['diff_truncated'] ? substr($text, 0, self::MAX_DIFF_BYTES) : $text;
            }
        }

        $logger->info('Production state reported.', [
            'pull_branch' => $pullBranch,
            'fetch_ok' => $fetchOk,
            'ahead' => $ahead,
            'behind' => $behind,
        ]);

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if ('' !== $line) {
                $out[] = $line;
            }
        }

        return $out;
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
