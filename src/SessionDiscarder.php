<?php

namespace ArchMcp;

require_once __DIR__.'/SessionDiscard.php';
require_once __DIR__.'/SessionFile.php';
require_once __DIR__.'/SessionPurge.php';
require_once __DIR__.'/SessionResult.php';

/**
 * Discards the active session as a soft delete: the work is saved as a
 * snapshot commit (SessionDiscard), the branch is renamed discarded/<name>
 * and tagged with a discard marker, and main is checked out clean. The branch
 * is kept for SessionPurge::GRACE_DAYS days; arch_session_recover brings it
 * back.
 *
 * The session's lane is stored in git config (branch.<name>.archlane) just
 * before the rename. git moves a branch's config with the branch, so the lane
 * survives the discarded/ name and recover can restore it.
 */
final class SessionDiscarder
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public static function run(string $repoPath, callable $git, ?int $now = null): array
    {
        try {
            $session = SessionFile::read($repoPath);
        } catch (\RuntimeException $e) {
            return SessionResult::fail($e->getMessage());
        }
        if (null === $session) {
            return SessionResult::fail('No active session to discard.');
        }
        $branch = $session['branch'];

        $snap = SessionDiscard::snapshot($git, $repoPath);
        if (true !== $snap['ok']) {
            return SessionResult::fail('discard refused, the changes in this session could not be saved first: '.$snap['error']);
        }
        $free = SessionDiscard::freeDiscardName($git, $repoPath);
        if (true !== $free['ok']) {
            return SessionResult::fail('discard refused: '.$free['error']);
        }

        $discarded = SessionPurge::PREFIX.$branch;
        $epoch = $now ?? time();

        $git(['config', "branch.{$branch}.archlane", $session['lane']]);
        $checkout = $git(['checkout', '-q', 'main']);
        if (0 !== $checkout['exit_code']) {
            return SessionResult::fail('could not switch to main: '.trim($checkout['stderr']).' Nothing was discarded.');
        }
        $rename = $git(['branch', '-m', $branch, $discarded]);
        if (0 !== $rename['exit_code']) {
            $git(['checkout', '-q', $branch]);

            return SessionResult::fail("could not rename '{$branch}' to '{$discarded}': ".trim($rename['stderr']).' The session is still active.');
        }
        $tag = $git(['tag', "discard-marker/{$discarded}/{$epoch}", $discarded]);
        SessionFile::delete($repoPath);

        $out = "Session '{$branch}' discarded. main is untouched.\n"
            ."Branch retained as '{$discarded}' for ".SessionPurge::GRACE_DAYS." days in case this was accidental.\n"
            ."Recover with: arch_session_recover {$branch}\n";
        if (0 !== $tag['exit_code']) {
            $out .= "WARNING: the discard marker tag could not be made ({$tag['stderr']}); the branch will not be purged automatically.\n";
        }
        if (null !== $free['moved']) {
            $out .= "An older discarded branch with the same name was kept as '{$free['moved']}'.\n";
        }
        if ($snap['snapshotted']) {
            $out .= "Uncommitted changes ({$snap['files']} file(s)) were saved on the discarded branch, so main's working tree is clean; arch_session_recover brings them back.\n";
        }
        $out .= implode('', SessionPurge::run($git, $epoch));

        return SessionResult::ok($out);
    }
}
