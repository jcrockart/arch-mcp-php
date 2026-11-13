<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';

/**
 * What Portal recorded as deployed for the PRODUCTION profile, for
 * arch_project_info (ARCH-83): project_profiles.deployed_version,
 * deployed_commit and promoted_at (UTC). SELECT only, fail soft.
 *
 * Reported as {version, commit, promoted_at}, where promoted_at is ISO 8601 UTC
 * ("2026-11-13T10:15:00Z"). Any of the three can be null and none of that is an
 * error: a commit with promoted_at null means Portal saw the commit but not the
 * promote itself. A change in commit or promoted_at is the signal that a promote
 * happened. Staging is never written by Portal (it moves with every commit), so
 * it is not read here; use the staging view's git.head.
 */
final class PortalDeployed
{
    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     *
     * @return array{production: ?array{version: ?string, commit: ?string, promoted_at: ?string}, unavailable: bool}
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger): array
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);
        if (null === $pdo) {
            return ['production' => null, 'unavailable' => true];
        }
        $r = self::fetchWith($pdo, $slug);
        if ($r['unavailable']) {
            $logger->warning('portal_deployed_unavailable', ['slug' => $slug, 'environment' => $environment]);
        }

        return $r;
    }

    /**
     * @return array{production: ?array{version: ?string, commit: ?string, promoted_at: ?string}, unavailable: bool}
     */
    public static function fetchWith(\PDO $pdo, string $slug): array
    {
        try {
            $stmt = $pdo->prepare("SELECT pp.deployed_version, pp.deployed_commit, pp.promoted_at FROM project_profiles pp JOIN projects p ON p.id = pp.project_id WHERE p.slug = :slug AND pp.environment = 'production'");
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return ['production' => null, 'unavailable' => true];
        }

        return ['production' => false === $row ? null : self::shape($row), 'unavailable' => false];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{version: ?string, commit: ?string, promoted_at: ?string}
     */
    public static function shape(array $row): array
    {
        $s = static fn (mixed $v): ?string => \is_string($v) && '' !== trim($v) ? trim($v) : null;
        $at = $s($row['promoted_at'] ?? null);
        if (null !== $at && 1 === preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $at, $m)) {
            $at = $m[1].'T'.$m[2].'Z';
        }

        return ['version' => $s($row['deployed_version'] ?? null), 'commit' => $s($row['deployed_commit'] ?? null), 'promoted_at' => $at];
    }
}
