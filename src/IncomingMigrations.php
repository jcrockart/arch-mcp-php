<?php

namespace ArchMcp;

/**
 * The migrations a pull would bring in, listed BEFORE the pull (added 2026-10-29
 * for Portal's Review & pull page).
 *
 * WHY: db_pending_prod reads db/migrations in the PRODUCTION CHECKOUT, so a
 * migration that is only on origin does not exist there yet and the Database
 * changes card cannot show it until after the owner has pulled. This reads the
 * same folder from origin/<pull_branch> instead, without touching the working
 * tree, and keeps the ones that are not applied to the production database
 * and not already in the checkout (those are listed as ordinary pending).
 *
 * SAFETY: read-only apart from `git fetch origin`, which only updates
 * refs/remotes/origin/*. Every git argument is hardcoded; the only
 * profile-supplied values are the branch name (validated, used only as the
 * suffix of "origin/") and the core lane sub-folder (validated, used only as a
 * path prefix). File names come from `git ls-tree` and are checked against a
 * strict pattern before being passed to `git show`. Nothing is written, and
 * no SQL is run.
 *
 * HONESTY: a failed fetch does not hide the answer (the last fetched origin is
 * still listed) but is reported as fetch_ok false. A missing ref, an
 * unreadable tree or a file that cannot be parsed is reported, never skipped
 * silently and never read as "nothing incoming".
 */
final class IncomingMigrations
{
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 262144;

    /**
     * @param list<string> $appliedIds  ids recorded in the production database
     * @param list<string> $checkoutIds ids already declared in the production checkout
     *
     * @return array<string, mixed>
     */
    public static function forPull(string $root, string $coreSub, string $pullBranch, array $appliedIds, array $checkoutIds): array
    {
        if (1 !== preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $pullBranch) || str_contains($pullBranch, '..')) {
            return ['available' => false, 'error' => 'this profile has an invalid pull_branch'];
        }
        $sub = trim($coreSub, '/');
        if ('' !== $sub && (1 !== preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $sub) || str_contains($sub, '..'))) {
            return ['available' => false, 'error' => 'this profile has an invalid core lane folder'];
        }
        if (!is_dir($root)) {
            return ['available' => false, 'error' => 'the checkout directory does not exist'];
        }
        $ref = 'origin/'.$pullBranch;
        $dir = ('' === $sub ? '' : $sub.'/').'db/migrations';

        $fetch = self::git($root, ['fetch', 'origin']);
        $fetchOk = 0 === $fetch['exit_code'];
        $base = ['available' => true, 'ref' => $ref, 'fetch_ok' => $fetchOk];
        if (!$fetchOk) {
            $base['fetch_error'] = trim($fetch['stderr']);
        }

        $verify = self::git($root, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}']);
        if (0 !== $verify['exit_code'] || '' === trim($verify['stdout'])) {
            return ['available' => false, 'ref' => $ref, 'fetch_ok' => $fetchOk, 'error' => "{$ref} is not known in this checkout"] + ($fetchOk ? [] : ['fetch_error' => trim($fetch['stderr'])]);
        }

        $tree = self::git($root, ['ls-tree', '--name-only', $ref, $dir.'/']);
        if (0 !== $tree['exit_code']) {
            return ['available' => false, 'ref' => $ref, 'fetch_ok' => $fetchOk, 'error' => 'could not list '.$dir.' on '.$ref.': '.trim($tree['stderr'])];
        }
        $names = [];
        foreach (explode("\n", $tree['stdout']) as $line) {
            $line = trim($line);
            if ('' !== $line && 1 === preg_match('#^[A-Za-z0-9._/-]+\.sql$#', $line) && str_starts_with($line, $dir.'/')) {
                $names[] = $line;
            }
        }
        sort($names);

        $problems = [];
        if (\count($names) > self::MAX_FILES) {
            $problems[] = 'more than '.self::MAX_FILES.' migration files on '.$ref.'; only the first '.self::MAX_FILES.' were read';
            $names = \array_slice($names, 0, self::MAX_FILES);
        }
        $files = [];
        foreach ($names as $path) {
            $show = self::git($root, ['show', $ref.':'.$path]);
            if (0 !== $show['exit_code']) {
                $problems[] = basename($path).': could not be read from '.$ref;
                continue;
            }
            if (\strlen($show['stdout']) > self::MAX_FILE_BYTES) {
                $problems[] = basename($path).': larger than '.self::MAX_FILE_BYTES.' bytes, not read';
                continue;
            }
            $files[basename($path)] = $show['stdout'];
        }

        $built = self::fromContents($files, $appliedIds, $checkoutIds);

        return $base + [
            'migrations' => $built['migrations'],
            'count' => \count($built['migrations']),
            'problems' => array_merge($problems, $built['problems']),
        ];
    }

    /**
     * The pure part: which of these migration files are new. A file with no valid
     * id/type header is reported under `problems`, as is an id declared twice.
     *
     * @param array<string, string> $files       file name => content
     * @param list<string>          $appliedIds
     * @param list<string>          $checkoutIds
     *
     * @return array{migrations: list<array<string, mixed>>, problems: list<string>}
     */
    public static function fromContents(array $files, array $appliedIds, array $checkoutIds): array
    {
        ksort($files);
        $out = [];
        $problems = [];
        $seen = [];
        foreach ($files as $name => $content) {
            $m = DbMigrations::parse($content);
            if (null === $m) {
                $problems[] = "{$name}: missing '-- id:' or '-- type:' header, so it would be ignored";
                continue;
            }
            if (isset($seen[$m['id']])) {
                $problems[] = "duplicate migration id '{$m['id']}' in {$seen[$m['id']]} and {$name}";
                continue;
            }
            $seen[$m['id']] = $name;
            if (\in_array($m['id'], $appliedIds, true) || \in_array($m['id'], $checkoutIds, true)) {
                continue;
            }
            $reason = DbMigrations::destructiveReason($m);
            $entry = [
                'id' => $m['id'],
                'file' => $name,
                'type' => $m['type'],
                'data_class' => $m['data-class'],
                'description' => $m['description'],
                'destructive' => null !== $reason,
            ];
            if (null !== $reason) {
                $entry['reason'] = $reason;
            }
            $out[] = $entry;
        }

        return ['migrations' => $out, 'problems' => $problems];
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
