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
    private const MAX_LINK = 500;

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
            'project_url' => self::projectUrl($issuers, $slug),
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

    /**
     * The project's own Portal page, from the first trusted issuer, or null when no
     * https Portal address is configured.
     *
     * @param list<mixed> $issuers
     */
    public static function projectUrl(array $issuers, string $slug): ?string
    {
        $base = self::baseUrl($issuers);

        return null === $base ? null : $base.'/project.php?slug='.rawurlencode($slug);
    }

    /**
     * A link Portal supplied (for example in a provision status reply), returned
     * only if it is safe to hand to a person: a string of at most 500 characters
     * made only of characters allowed in a URL (so no spaces, quotes, backslashes or
     * control characters), https, no user info, and the same host and port as the
     * trusted Portal address. Anything else is null.
     *
     * @param list<mixed> $issuers
     */
    public static function sameHostUrl(array $issuers, mixed $url): ?string
    {
        $base = self::baseUrl($issuers);
        if (null === $base || !\is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ('' === $url || \strlen($url) > self::MAX_LINK || 1 !== preg_match('/^[A-Za-z0-9\-._~:\/?#\[\]@!$&()*+,;=%]+$/', $url)) {
            return null;
        }
        $want = parse_url($base);
        $got = parse_url($url);
        if (!\is_array($want) || !\is_array($got) || !isset($want['host'], $got['host'])) {
            return null;
        }
        if ('https' !== strtolower((string) ($got['scheme'] ?? '')) || isset($got['user']) || isset($got['pass'])) {
            return null;
        }
        if (strtolower($got['host']) !== strtolower($want['host']) || ($got['port'] ?? 443) !== ($want['port'] ?? 443)) {
            return null;
        }

        return $url;
    }
}
