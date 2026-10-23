<?php

namespace ArchMcp;

require_once __DIR__.'/Git.php';
require_once __DIR__.'/SessionFile.php';
require_once __DIR__.'/SessionResult.php';

/**
 * Starts a session: a new git branch off the current commit, plus the
 * session file. Only one session can be active at a time.
 */
final class SessionStart
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function run(string $repoPath, string $name, string $lane, callable $git, ?\DateTimeImmutable $now = null): array
    {
        if (SessionFile::exists($repoPath)) {
            return SessionResult::fail('A session is already active. Commit or discard it before starting another.');
        }
        // The lane's gate config is deliberately not read here: a broken
        // arch-gate.json must not stop the session that would fix it.
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $branch = '' !== $name ? $name : 'session-'.$now->format('Ymd\THis\Z');
        $head = $git(['rev-parse', 'HEAD']);
        if (0 !== $head['exit_code']) {
            return SessionResult::fail('could not read the current commit: '.trim($head['stderr']));
        }
        $base = trim($head['stdout']);

        $checkout = $git(['checkout', '-q', '-b', $branch]);
        if (0 !== $checkout['exit_code']) {
            return SessionResult::fail("could not create branch '{$branch}': ".trim($checkout['stderr']));
        }
        if (!SessionFile::write($repoPath, $branch, $base, $lane, false, $now)) {
            $git(['checkout', '-q', '-']);
            $git(['branch', '-D', $branch]);

            return SessionResult::fail('could not write .arch-session.json, so the session was not started');
        }

        return SessionResult::ok("Session started: branch '{$branch}' from ".substr($base, 0, 8)." (lane: {$lane})\n"
            ."Origination is unrestricted in this session (22-ARCH-GOVERNANCE §22.7).\n"
            ."Run arch_session_validate any time to see what the commit's validation would say.\n");
    }
}
