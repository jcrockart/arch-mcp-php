<?php

namespace ArchMcp;

/**
 * Runs git in a project checkout. The session modules take a `$git` callable
 * (list of arguments in, exit code and output out) so tests can use a stub or
 * a real temporary repository; Git::in() makes the real one.
 */
final class Git
{
    /**
     * @return \Closure(list<string>): array{exit_code: int, stdout: string, stderr: string}
     */
    public static function in(string $root): \Closure
    {
        return static fn (array $args): array => self::run($root, $args);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function run(string $root, array $args): array
    {
        $process = @proc_open(
            array_merge(['git'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!\is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed to spawn git'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * `-c` options that supply a git identity for whichever of user.name and
     * user.email is missing, so a commit or merge in a checkout with no
     * identity configured still works. A configured value is never overridden.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return list<string>
     */
    public static function identity(callable $git, string $who): array
    {
        $args = [];
        if ('' === trim($git(['config', 'user.name'])['stdout'])) {
            array_push($args, '-c', 'user.name='.$who);
        }
        if ('' === trim($git(['config', 'user.email'])['stdout'])) {
            array_push($args, '-c', 'user.email=arch-session@localhost');
        }

        return $args;
    }
}
