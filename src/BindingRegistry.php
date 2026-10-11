<?php

namespace ArchMcp;

require_once __DIR__.'/ClaudeProjectId.php';
require_once __DIR__.'/PortalProjectResolver.php';

/**
 * Reads what Portal's registry says about a project's Claude project
 * bindings (ARCH-62): the IDs registered for the slug, and whether the project
 * is grandfathered (it existed before the rollout, so it keeps working
 * without a binding until someone bootstraps it).
 *
 * Read-only, through the same standing connection PortalProjectResolver uses
 * for membership (SELECT only). Portal is the only writer of these tables.
 *
 * Returns null whenever the answer cannot be trusted: the project row is not
 * there, the tables or the read grant are missing, or the query fails. The
 * caller (BindingGuard) treats null as "registry unavailable", never as
 * "nothing registered".
 */
final class BindingRegistry
{
    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     *
     * @return array{registered: list<string>, grandfathered: bool}|null
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger): ?array
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);

        return null === $pdo ? null : self::fetchWith($pdo, $slug);
    }

    /**
     * @return array{registered: list<string>, grandfathered: bool}|null
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
            $stmt = $pdo->prepare('SELECT claude_project_id FROM project_claude_bindings WHERE project_id = :id');
            $stmt->execute(['id' => (int) $project['id']]);
            $ids = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
                $id = ClaudeProjectId::normalize((string) $value);
                if (null !== $id) {
                    $ids[] = $id;
                }
            }

            return ['registered' => $ids, 'grandfathered' => 1 === (int) $project['bootstrap_grandfathered']];
        } catch (\PDOException) {
            return null;
        }
    }
}
