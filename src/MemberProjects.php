<?php

namespace ArchMcp;

/**
 * The slugs of the projects a Portal user is a member of, from Portal's
 * read-only database (ARCH-80, arch_ref general search). Uses the same
 * `projects` and `project_members` tables, with the same read-only grant, as
 * PortalProjectResolver; nothing here widens that. The caller still resolves
 * each slug through PortalProjectResolver before touching any code, so this
 * list is a starting point, not a permission.
 */
final class MemberProjects
{
    public const MAX = 200;

    /**
     * @return list<string> sorted slugs; empty when the id is not numeric or the query fails
     */
    public static function slugs(\PDO $pdo, string $portalUserId): array
    {
        if (1 !== preg_match('/^[0-9]+$/', $portalUserId)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT p.slug FROM projects p
                 JOIN project_members pm ON pm.project_id = p.id
                 WHERE pm.user_id = :user_id
                 ORDER BY p.slug
                 LIMIT '.self::MAX
            );
            $stmt->execute(['user_id' => (int) $portalUserId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return [];
        }

        $slugs = [];
        foreach ($rows as $slug) {
            if (\is_string($slug) && 1 === preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }
}
