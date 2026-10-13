<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';

/**
 * arch_bootstrap_read (ARCH-63, direction settled 2026-10-13): serve the
 * current arch-bootstrap framework files to a Claude project chat so it can
 * save them into its own project knowledge. Read-only: nothing is written to
 * the project or to Portal.
 *
 * With no path it lists the files (name, size, sha256). With a path it returns
 * that file's text with its size and sha256, so the chat can read back what it
 * saved and compare. The hash is computed here from the text actually
 * returned, and a file whose text does not match the hash Portal reported is
 * refused rather than handed on.
 *
 * Portal does the access check (owner or contributor of the project, which is
 * what lets a non-member of arch-bootstrap read it) and holds the only copy.
 *
 * Does no I/O beyond the one Portal call.
 */
final class BootstrapRead
{
    private const MAX_NAME = 200;

    /**
     * @return array<string, mixed>
     */
    public static function run(PortalCaller $bootstrap, string $slug, string $path): array
    {
        $path = trim($path);

        if ('' === $path) {
            $reply = $bootstrap->call('bootstrap_list', $slug);
            if (true !== ($reply['success'] ?? false)) {
                return self::portalFail($reply, 'could not list the framework files');
            }
            $files = [];
            foreach ((array) ($reply['files'] ?? []) as $f) {
                if (\is_array($f) && \is_string($f['name'] ?? null)) {
                    $files[] = ['name' => $f['name'], 'size' => (int) ($f['size'] ?? 0), 'sha256' => (string) ($f['sha256'] ?? '')];
                }
            }

            return ['success' => true, 'slug' => $slug, 'files' => $files, 'next' => 'Call arch_bootstrap_read again with path set to one of these names to read it.'];
        }

        if (\strlen($path) > self::MAX_NAME || 1 === preg_match('~[/\\\\\x00-\x1f]|\.\.~', $path)) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'path must be one file name exactly as listed by arch_bootstrap_read with no path.'];
        }

        $reply = $bootstrap->call('bootstrap_read', $slug, ['name' => $path]);
        if (true !== ($reply['success'] ?? false)) {
            return self::portalFail($reply, "could not read '{$path}'");
        }
        $content = $reply['content'] ?? null;
        if (!\is_string($content)) {
            return ['success' => false, 'code' => 'server', 'error' => "could not read '{$path}': unexpected reply from Portal"];
        }
        $sha = hash('sha256', $content);
        if (isset($reply['sha256']) && \is_string($reply['sha256']) && !hash_equals(strtolower($reply['sha256']), $sha)) {
            return ['success' => false, 'code' => 'changed', 'error' => "'{$path}' changed while it was being read; call arch_bootstrap_read again"];
        }

        return ['success' => true, 'name' => $path, 'size' => \strlen($content), 'sha256' => $sha, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $reply a failed Portal reply
     *
     * @return array<string, mixed>
     */
    private static function portalFail(array $reply, string $what): array
    {
        return ['success' => false, 'code' => (string) ($reply['code'] ?? 'server'), 'error' => $what.': '.mb_substr((string) ($reply['error'] ?? 'unexpected reply from Portal'), 0, 300)];
    }
}
