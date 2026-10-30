<?php

namespace ArchMcp;

/**
 * Where Portal is, for arch_project_info's `portal` block (ARCH-74): the base
 * address of the Portal this connector talks to, and the project's own Portal
 * page. A chat needs them for asset uploads, connector sign-in, Promote,
 * provisioning status and "contact the owner".
 *
 * The base address is the FIRST trusted issuer for the connector's environment,
 * the same one PortalAssetsClient sends its calls to, so the address shown can
 * never differ from where calls actually go. A production connector gives the
 * production Portal and a staging connector the staging Portal. It must be an
 * https address; anything else is treated as not configured and reported as
 * null with a note, never guessed.
 *
 * The project page path (/project.php?slug=<slug>) is Portal's, given by Portal
 * on 2026-10-30.
 *
 * Pure: no I/O. The caller passes the trusted issuer list.
 */
final class PortalLinks
{
    /**
     * @param 'staging'|'production' $environment
     * @param list<mixed>            $issuers     OAuthBearer::trustedIssuerUrlsForEnvironment($environment)
     *
     * @return array{environment: string, base_url: ?string, project_url: ?string, note?: string}
     */
    public static function describe(string $environment, string $slug, array $issuers): array
    {
        $base = self::baseUrl($issuers);
        if (null === $base) {
            return [
                'environment' => $environment,
                'base_url' => null,
                'project_url' => null,
                'note' => 'no https Portal address is configured for this connector, so no Portal links are known',
            ];
        }

        return [
            'environment' => $environment,
            'base_url' => $base,
            'project_url' => $base.'/project.php?slug='.rawurlencode($slug),
        ];
    }

    /**
     * @param list<mixed> $issuers
     */
    public static function baseUrl(array $issuers): ?string
    {
        $first = $issuers[0] ?? null;
        if (!\is_string($first) || 1 !== preg_match('#^https://[^\s/?\#]+(/[^\s?\#]*)?$#i', trim($first))) {
            return null;
        }

        return rtrim(trim($first), '/');
    }
}
