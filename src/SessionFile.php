<?php

namespace ArchMcp;

/**
 * The one-session-at-a-time marker, .arch-session.json in the project root.
 * Keys: branch, base_commit, started_at, lane, and recovered (only after a
 * recover). Written with two-space indentation, as arch.py wrote it.
 */
final class SessionFile
{
    public static function path(string $repoPath): string
    {
        return rtrim($repoPath, '/').'/.arch-session.json';
    }

    public static function exists(string $repoPath): bool
    {
        clearstatcache(true, self::path($repoPath));

        return is_file(self::path($repoPath));
    }

    /**
     * @return array{branch: string, base_commit: string, started_at: string, lane: string, recovered?: bool}|null null when there is no session
     *
     * @throws \RuntimeException when the file exists but cannot be read as a session
     */
    public static function read(string $repoPath): ?array
    {
        if (!self::exists($repoPath)) {
            return null;
        }
        $data = json_decode((string) file_get_contents(self::path($repoPath)), true);
        if (!\is_array($data) || !\is_string($data['branch'] ?? null) || '' === $data['branch']) {
            throw new \RuntimeException('.arch-session.json cannot be read as a session (no branch name)');
        }

        return [
            'branch' => $data['branch'],
            'base_commit' => \is_string($data['base_commit'] ?? null) ? $data['base_commit'] : '',
            'started_at' => \is_string($data['started_at'] ?? null) ? $data['started_at'] : '',
            // A file with no lane belongs to the metadata lane, as it always has.
            'lane' => \is_string($data['lane'] ?? null) && '' !== $data['lane'] ? $data['lane'] : 'metadata',
        ] + (true === ($data['recovered'] ?? null) ? ['recovered' => true] : []);
    }

    public static function write(string $repoPath, string $branch, string $baseCommit, string $lane, bool $recovered = false, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = [
            'branch' => $branch,
            'base_commit' => $baseCommit,
            'started_at' => $now->format('Y-m-d\TH:i:s.uP'),
            'lane' => $lane,
        ];
        if ($recovered) {
            $data['recovered'] = true;
        }
        $json = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $json = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(\strlen($m[1]), 2)), $json);

        return false !== file_put_contents(self::path($repoPath), $json);
    }

    public static function delete(string $repoPath): void
    {
        @unlink(self::path($repoPath));
        clearstatcache(true, self::path($repoPath));
    }
}
