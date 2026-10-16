<?php

namespace ArchMcp;

/**
 * Keeps the arch_session_* file tools out of sessions they cannot serve,
 * added 2026-10-16.
 *
 * THE PROBLEM: arch_session_write_file / read_file / list_files resolve paths
 * inside the metadata lane's own folder (metadata/), not the repo root, and
 * they never checked which lane the active session is on. In a one-lane
 * project (the code lane's stage paths include "metadata", decided 2026-10-16)
 * a chat that reached for them wrote "metadata/proof.json" to
 * metadata/metadata/proof.json and "src/proof.php" to metadata/src/proof.php,
 * with no error, and both were committed. In a two-lane project the same
 * write left a file that the code lane's commit never stages.
 *
 * THE RULE (decided 2026-10-16, option 1, with a narrower rule for reads):
 *  - a WRITE is refused unless the active session is on the metadata lane;
 *  - a READ or LIST is refused only when the session's lane stages
 *    metadata/, because only there is "use the arch_code_* tools" the right
 *    answer. In a code session that does not stage metadata/, arch_code_read_file
 *    cannot reach it, so reading through the session tool stays allowed;
 *  - no active session: nothing is refused here (reads work on main as they
 *    always did; a write already fails for want of a session).
 *
 * Reads the session file and arch-gate.json directly and does no other I/O.
 * ArchTools calls refusal() first in each of the three tools.
 */
final class SessionLaneGuard
{
    public const WRITE = 'write';
    public const READ = 'read';
    public const LIST = 'list';

    /**
     * The active session's lane, or null when there is no readable session.
     */
    public static function sessionLane(string $repoPath): ?string
    {
        $file = $repoPath.'/.arch-session.json';
        if (!is_file($file)) {
            return null;
        }
        $session = json_decode((string) file_get_contents($file), true);
        if (!\is_array($session)) {
            return null;
        }

        // Same default arch.py uses for a session file with no lane recorded.
        return \is_string($session['lane'] ?? null) && '' !== $session['lane'] ? $session['lane'] : 'metadata';
    }

    /**
     * Whether arch-gate.json lists metadata/ (or a folder inside it) among a
     * lane's stage paths. False for a missing or unreadable config.
     */
    public static function stagesMetadata(string $repoPath, string $lane): bool
    {
        $gate = $repoPath.'/arch-gate.json';
        if (!is_file($gate)) {
            return false;
        }
        $cfg = json_decode((string) file_get_contents($gate), true);
        $paths = \is_array($cfg) ? ($cfg[$lane]['stage']['paths'] ?? null) : null;
        if (!\is_array($paths)) {
            return false;
        }
        foreach ($paths as $p) {
            if (!\is_string($p)) {
                continue;
            }
            $p = trim($p, '/');
            if ('metadata' === $p || str_starts_with($p, 'metadata/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message to refuse with, or null when the call may go ahead.
     *
     * @param string $operation one of self::WRITE, self::READ, self::LIST
     */
    public static function refusal(string $repoPath, string $operation): ?string
    {
        $lane = self::sessionLane($repoPath);
        if (null === $lane || 'metadata' === $lane) {
            return null;
        }

        if (self::stagesMetadata($repoPath, $lane)) {
            $use = [
                self::WRITE => 'arch_code_write_file or arch_code_edit_file',
                self::READ => 'arch_code_read_file',
                self::LIST => 'arch_code_list_files',
            ][$operation] ?? 'the arch_code_* tools';

            return "this session is on the '{$lane}' lane, which also stages metadata/. The arch_session_* file tools work inside the metadata folder, so they would put files in the wrong place or not find them. Use {$use} with repo-root paths instead, for example 'metadata/entities/note.json'.";
        }

        if (self::WRITE === $operation) {
            return "this session is on the '{$lane}' lane, which does not stage metadata/, so a file written with arch_session_write_file would never be committed. Start a metadata-lane session for metadata, or use arch_code_write_file for the paths this lane stages.";
        }

        return null;
    }
}
