<?php

namespace ArchMcp;

/**
 * The staging and production site URLs of a project, for arch_project_info
 * (added 2026-10-13), so a chat can give the person the address to look at
 * after a commit without working it out from the project config itself.
 *
 * Source: the checkout's metadata/project.config.json. Each environment
 * (`environments.staging`, `environments.production`, or the legacy single
 * `hosting` block, which counts as production) has a `domain` and a
 * `document_root`. On this hosting a project is served from a folder named
 * after its slug under the domain's own web root:
 *
 *   document_root /home/<account>/public_html/<domain>/<slug>
 *   -> https://<domain>/<slug>/
 *
 * and a document root that IS the domain's web root gives https://<domain>/.
 *
 * A project on its own subdomain (added 2026-10-21) is served from the root
 * of that domain, and its document root can be anywhere under public_html
 * (usually a symlink to the project's public/ folder):
 *
 *   slug the-office, domain the-office.crockart.com.au,
 *   document_root /home/<account>/public_html/arch-projects/the-office/public
 *   -> https://the-office.crockart.com.au/
 *
 * It is recognised only when the domain's first label is exactly the
 * project's slug (project.slug in the same config) and the document root is a
 * plain path under public_html. The slug is the only signal, so a path-style
 * project is never mistaken for one.
 *
 * Only those shapes are recognised. Anything else (another layout, a bad domain,
 * odd path segments) gives null for that environment plus a note, never a
 * guess. The scheme is always https.
 *
 * The config file is project-editable data, so the domain must look like a
 * host name and every path segment like a plain folder name before they are
 * put into a URL. No server path is ever returned. Pure apart from reading
 * the one file.
 */
final class ProjectUrls
{
    private const FILE = 'metadata/project.config.json';
    private const HOST = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * @return array{staging: ?string, production: ?string, source: string, note?: string}
     */
    public static function forCheckout(string $dir): array
    {
        $none = ['staging' => null, 'production' => null, 'source' => self::FILE];
        $file = $dir.'/'.self::FILE;
        if ('' === $dir || !is_file($file)) {
            return $none + ['note' => 'no project.config.json in the checkout, so no URLs are known'];
        }
        $cfg = json_decode((string) file_get_contents($file), true);
        if (!\is_array($cfg)) {
            return $none + ['note' => 'project.config.json is not valid JSON, so no URLs are known'];
        }

        return self::fromConfig($cfg);
    }

    /**
     * @param array<string, mixed> $cfg a decoded project.config.json
     *
     * @return array{staging: ?string, production: ?string, source: string, note?: string}
     */
    public static function fromConfig(array $cfg): array
    {
        if (isset($cfg['environments']) && \is_array($cfg['environments'])) {
            $envs = ['staging' => $cfg['environments']['staging'] ?? null, 'production' => $cfg['environments']['production'] ?? null];
        } elseif (isset($cfg['hosting']) && \is_array($cfg['hosting'])) {
            $envs = ['staging' => null, 'production' => $cfg['hosting']];
        } else {
            $envs = ['staging' => null, 'production' => null];
        }

        $out = ['staging' => null, 'production' => null, 'source' => self::FILE];
        $slug = \is_array($cfg['project'] ?? null) && \is_string($cfg['project']['slug'] ?? null) ? $cfg['project']['slug'] : '';
        $notes = [];
        foreach (['staging', 'production'] as $name) {
            $env = $envs[$name];
            if (!\is_array($env)) {
                $notes[] = "no {$name} hosting is declared";
                continue;
            }
            $url = self::urlFor((string) ($env['domain'] ?? ''), (string) ($env['document_root'] ?? ''), $slug);
            if (null === $url) {
                $notes[] = "the {$name} URL could not be worked out from its domain and document root";
                continue;
            }
            $out[$name] = $url;
        }
        if ([] !== $notes) {
            $out['note'] = implode('; ', $notes);
        }

        return $out;
    }

    /** The URL for one environment, or null if its domain and document root are not in the recognised shape. */
    public static function urlFor(string $domain, string $documentRoot, string $slug = ''): ?string
    {
        $domain = strtolower(trim($domain));
        if (1 !== preg_match(self::HOST, $domain)) {
            return null;
        }
        $root = rtrim(trim($documentRoot), '/');
        if (1 !== preg_match('~^/home/[^/]+/public_html/'.preg_quote($domain, '~').'(/.*)?$~i', $root, $m)) {
            return self::ownSubdomainUrl($domain, $root, $slug);
        }
        $rest = trim($m[1] ?? '', '/');
        if ('' === $rest) {
            return 'https://'.$domain.'/';
        }
        $segments = explode('/', $rest);
        foreach ($segments as $segment) {
            if (1 !== preg_match(self::SEGMENT, $segment) || '..' === $segment) {
                return null;
            }
        }

        return 'https://'.$domain.'/'.implode('/', $segments).'/';
    }

    /**
     * A project on its own subdomain: the domain's first label is exactly the
     * project's slug and the document root is a plain path under public_html.
     * Gives the bare domain, or null if either condition fails.
     */
    private static function ownSubdomainUrl(string $domain, string $root, string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if ('' === $slug || explode('.', $domain, 2)[0] !== $slug) {
            return null;
        }
        if (1 !== preg_match('~^/home/[^/]+/public_html/(.+)$~', $root, $m)) {
            return null;
        }
        foreach (explode('/', $m[1]) as $segment) {
            if (1 !== preg_match(self::SEGMENT, $segment)) {
                return null;
            }
        }

        return 'https://'.$domain.'/';
    }
}
