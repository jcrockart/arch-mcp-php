<?php

namespace ArchMcp;

/**
 * The reply shape of the session tools: exit_code, stdout, stderr. Kept the
 * same as when these steps ran in arch.py, because callers and CommitOutput
 * read it.
 */
final class SessionResult
{
    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function ok(string $stdout): array
    {
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function fail(string $message, string $stdout = ''): array
    {
        return ['exit_code' => 1, 'stdout' => $stdout, 'stderr' => $message];
    }
}
