<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';
require_once __DIR__.'/PortalLinks.php';

/**
 * The link to this project's Owner promote view in Portal (promote_url), read the
 * way PortalDeployed reads deployed_version: SELECT only, fail soft.
 *
 * Portal keeps it as project_profiles.promote_url on the PRODUCTION row (P6 in the
 * chat promote-to-production working doc): a full https URL on Portal's own host, NULL
 * on a project that cannot be promoted from chat (framework projects, v1). Whatever is
 * stored is shown to a person, so it is only returned if PortalLinks::sameHostUrl
 * accepts it (https, no user info, at most 500 characters, the same host as the
 * trusted Portal address). Anything else, a missing column (Portal has not added it
 * yet), an unreachable database or an unknown project, is null. A link is never built
 * from a guess.
 */
final class PortalPromoteUrl
{
    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     * @param list<mixed>            $issuers     OAuthBearer::trustedIssuerUrlsForEnvironment($environment)
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger, array $issuers): ?string
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);
        if (null === $pdo) {
            return null;
        }

        return self::fetchWith($pdo, $slug, $issuers);
    }

    /**
     * @param list<mixed> $issuers
     */
    public static function fetchWith(\PDO $pdo, string $slug, array $issuers): ?string
    {
        try {
            $stmt = $pdo->prepare("SELECT pp.promote_url FROM project_profiles pp JOIN projects p ON p.id = pp.project_id WHERE p.slug = :slug AND pp.environment = 'production'");
            if (false === $stmt) {
                return null;
            }
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if (!\is_array($row)) {
            return null;
        }

        return PortalLinks::sameHostUrl($issuers, $row['promote_url'] ?? null);
    }
}
