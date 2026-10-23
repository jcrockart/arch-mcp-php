<?php

namespace ArchMcp;

require_once __DIR__.'/SessionFile.php';
require_once __DIR__.'/SessionPurge.php';
require_once __DIR__.'/SessionResult.php';

/**
 * Brings a discarded session back: renames discarded/<name> to <name>, checks
 * it out, removes its discard-marker tags and writes the session file again,
 * with the lane the session had when it was discarded (or the lane named in
 * the call). Refused while another session is active.
 */
final class SessionRecover
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function run(string $repoPath, string $name, callable $git, string $lane = ''): array
    {
        if (SessionFile::exists($repoPath)) {
            return SessionResult::fail('A session is already active. Commit or discard it before recovering another.');
        }
        if ('' === $name || str_starts_with($name, '-')) {
            return SessionResult::fail('give the name of the discarded session to recover');
        }
        $discarded = SessionPurge::PREFIX.$name;
        if ('' === trim($git(['branch', '--list', $discarded])['stdout'])) {
            return SessionResult::fail("No discarded branch found matching '{$name}'. It may have already been purged after the ".SessionPurge::GRACE_DAYS.'-day grace window, or never existed.');
        }
        if (0 === $git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$name])['exit_code']) {
            return SessionResult::fail("A branch named '{$name}' already exists, so '{$discarded}' cannot be renamed back to it.");
        }

        $rename = $git(['branch', '-m', $discarded, $name]);
        if (0 !== $rename['exit_code']) {
            return SessionResult::fail('could not rename the discarded branch: '.trim($rename['stderr']));
        }
        $checkout = $git(['checkout', '-q', $name]);
        if (0 !== $checkout['exit_code']) {
            $git(['branch', '-m', $name, $discarded]);

            return SessionResult::fail("could not check out '{$name}': ".trim($checkout['stderr']).' The branch was left discarded.');
        }

        foreach (preg_split('/\s+/', trim($git(['tag', '--list', "discard-marker/{$discarded}/*"])['stdout'])) ?: [] as $tag) {
            if ('' !== $tag) {
                $git(['tag', '-d', $tag]);
            }
        }

        // An explicit lane wins; else the lane recorded at discard; else the
        // metadata lane (a session discarded before lanes were recorded).
        $laneRead = $git(['config', "branch.{$name}.archlane"]);
        $recorded = trim($laneRead['stdout']);
        $known = '' !== $lane || (0 === $laneRead['exit_code'] && '' !== $recorded);
        if ('' === $lane) {
            $lane = '' !== $recorded && 0 === $laneRead['exit_code'] ? $recorded : 'metadata';
        }
        $git(['config', '--unset', "branch.{$name}.archlane"]);

        $base = trim($git(['merge-base', 'main', $name])['stdout']);
        if (!SessionFile::write($repoPath, $name, $base, $lane, true)) {
            return SessionResult::fail("the branch '{$name}' is back and checked out, but .arch-session.json could not be written");
        }

        return SessionResult::ok("Session '{$name}' recovered and reactivated as the current session (lane: {$lane}).\n"
            .($known ? '' : "Its lane was not recorded when it was discarded, so it is on the metadata lane. If it was another lane, discard it and recover it again, naming the lane.\n"));
    }
}
