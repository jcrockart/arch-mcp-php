<?php

namespace ArchMcp;

require_once __DIR__.'/BindingGuard.php';
require_once __DIR__.'/BindingMode.php';
require_once __DIR__.'/ClaudeProjectId.php';
require_once __DIR__.'/PortalProjectResolver.php';

/**
 * The `binding` section of arch_project_info (ARCH-62 follow-on, 2026-10-21).
 *
 * arch_project_info is exempt from the Claude project ID check so that a chat
 * can ask it for its claudeProjectId at the start of every session instead of
 * asking the person. This class builds the answer: the check's mode, a state,
 * and the Claude project IDs that THIS caller registered for the slug.
 * Registrations made by other people are never listed, only counted as a state
 * (registered_by_others), so a member cannot read a colleague's ID here.
 *
 * Read-only, through the same standing connection BindingRegistry uses
 * (SELECT only). Portal is the only writer of these tables. An unreadable
 * registry gives state "unavailable", never "not_bootstrapped".
 *
 * Does no access control of its own: the caller has already been resolved as a
 * member of the project, and the role checks are unchanged.
 */
final class ProjectBindings
{
    public const REGISTERED = 'registered';
    public const REGISTERED_BY_OTHERS = 'registered_by_others';
    public const GRANDFATHERED = 'grandfathered';
    public const NOT_BOOTSTRAPPED = 'not_bootstrapped';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_APPLICABLE = 'not_applicable';

    /** Added to the notes for a project with no registration of the caller's (ARCH-76): a chat cannot see its own URL. */
    private const ASK_FOR_URL = ' If you cannot tell this chat\'s Claude project ID, ask the person to paste the project\'s URL (https://claude.ai/project/<guid>); do not guess.';

    /** Added to the note when the caller has one registered ID (ARCH-76): do not borrow another Claude project's ID. */
    private const NOT_IN_LIST = ' If you know this chat\'s own Claude project ID and it is not in this list, tell the person before going on, and do not borrow another project\'s ID.';

    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     *
     * @return array{rows: list<array{id: string, user: int}>, grandfathered: bool}|null
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger): ?array
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);

        return null === $pdo ? null : self::fetchWith($pdo, $slug);
    }

    /**
     * @return array{rows: list<array{id: string, user: int}>, grandfathered: bool}|null null when the answer cannot be trusted
     */
    public static function fetchWith(\PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare('SELECT id, bootstrap_grandfathered FROM projects WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $project = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (false === $project) {
                return null;
            }
            $stmt = $pdo->prepare('SELECT claude_project_id, registered_by FROM project_claude_bindings WHERE project_id = :id ORDER BY id');
            $stmt->execute(['id' => (int) $project['id']]);
            $rows = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $id = ClaudeProjectId::normalize((string) $row['claude_project_id']);
                if (null !== $id) {
                    $rows[] = ['id' => $id, 'user' => (int) $row['registered_by']];
                }
            }

            return ['rows' => $rows, 'grandfathered' => 1 === (int) $project['bootstrap_grandfathered']];
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * @param string                                                                           $mode         BindingMode::LOG or ENFORCE
     * @param string                                                                           $caller       one of the BindingGuard::CALLER_* values
     * @param string                                                                           $portalUserId the calling person's Portal user id
     * @param array{rows: list<array{id: string, user: int}>, grandfathered: bool}|null $registry     what fetch() returned; null when unreadable
     *
     * @return array{mode: string, enforced: bool, state: string, claude_project_ids: list<string>, note: string}
     */
    public static function describe(string $mode, string $caller, string $portalUserId, ?array $registry): array
    {
        if (BindingGuard::CALLER_USER !== $caller) {
            return self::result($mode, self::NOT_APPLICABLE, [], 'This caller (Portal service or the break-glass address) has no Claude project and is exempt from the ID check.');
        }
        if (null === $registry) {
            return self::result($mode, self::UNAVAILABLE, [], 'Could not read the registry just now; try again.');
        }

        $user = ctype_digit($portalUserId) ? (int) $portalUserId : 0;
        $own = [];
        foreach ($registry['rows'] as $row) {
            if ($user > 0 && $row['user'] === $user && !\in_array($row['id'], $own, true)) {
                $own[] = $row['id'];
            }
        }

        if ([] !== $own) {
            $note = 'Pass this value as claudeProjectId on every other arch-projects tool call for this project. Do not ask the user for it.'.self::NOT_IN_LIST;
            if (\count($own) > 1) {
                $note = 'You registered more than one Claude project for this project: pass the ID of this chat\'s own Claude project on every other arch-projects tool call, and ask the user only if you cannot tell which it is.';
            }

            return self::result($mode, self::REGISTERED, $own, $note);
        }
        if ([] !== $registry['rows']) {
            return self::result($mode, self::REGISTERED_BY_OTHERS, [], 'Other people have registered Claude projects for this project, but none was registered by you. Run install_bootstrap from this Claude project (the slug and the GUID in its URL) to register it.'.self::ASK_FOR_URL);
        }
        if ($registry['grandfathered']) {
            return self::result($mode, self::GRANDFATHERED, [], 'No Claude project is registered yet and this project predates the ID check, so calls work without claudeProjectId. Run install_bootstrap to register this Claude project.'.self::ASK_FOR_URL);
        }

        return self::result($mode, self::NOT_BOOTSTRAPPED, [], 'No Claude project is registered for this project yet. Run install_bootstrap first (the slug and the GUID in this Claude project\'s URL).'.self::ASK_FOR_URL);
    }

    /**
     * @param list<string> $ids
     *
     * @return array{mode: string, enforced: bool, state: string, claude_project_ids: list<string>, note: string}
     */
    private static function result(string $mode, string $state, array $ids, string $note): array
    {
        return [
            'mode' => $mode,
            'enforced' => BindingMode::ENFORCE === $mode,
            'state' => $state,
            'claude_project_ids' => $ids,
            'note' => $note,
        ];
    }
}
