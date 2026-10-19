<?php

namespace ArchMcp;

require_once __DIR__.'/SessionLaneGuard.php';

/**
 * Lets arch_session_list_files serve a one-lane code session, added 2026-10-19.
 *
 * Before: in a code-lane session whose stage paths include metadata/ (a
 * one-lane project) arch_session_list_files was refused with "use
 * arch_code_list_files". The refusal was right, because the session tool
 * lists inside metadata/ and would show only part of what the session
 * stages, but it sent every caller on a second round trip.
 *
 * Now: in exactly that case the call is answered with the code lane's own
 * listing (ArchTools::archCodeListFiles(), so the same lane, stage-path and
 * dot-component rules apply) plus a note that the paths are repo-root
 * paths. Nothing else changes: a metadata-lane session, a code session that
 * does not stage metadata/, and a call with no session all list metadata/
 * exactly as before, and arch_session_read_file / arch_session_write_file
 * still refuse on a one-lane session (see SessionLaneGuard).
 */
final class SessionList
{
    /**
     * The code lane's listing when this session is a one-lane code session,
     * or null when the normal metadata listing applies.
     *
     * @return array<string, mixed>|null
     */
    public static function viaCodeLane(ArchTools $tools, string $repoPath): ?array
    {
        if ('code' !== SessionLaneGuard::sessionLane($repoPath) || !SessionLaneGuard::stagesMetadata($repoPath, 'code')) {
            return null;
        }

        $list = $tools->archCodeListFiles();
        if (true !== ($list['success'] ?? false)) {
            return $list;
        }

        return $list + [
            'paths' => 'repo-root',
            'lane_note' => "this session is on the 'code' lane, which also stages metadata/, so this is the code lane's listing (the same as arch_code_list_files): every staged file, with repo-root paths such as 'metadata/entities/note.json'. Use those paths with arch_code_read_file, arch_code_write_file and arch_code_edit_file.",
        ];
    }
}
