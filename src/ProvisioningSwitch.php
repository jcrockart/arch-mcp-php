<?php

namespace ArchMcp;

/**
 * Whether the two chat provisioning tools (arch_provision_request and
 * arch_provision_status, ARCH-74) are registered on a connector. Registering a
 * tool shows its description to every chat, so keeping chats unaware of them
 * until the constitution allows it (Rule 1 change in arch-bootstrap, applied by
 * a project member and confirmed by James) takes a switch, not just silence.
 *
 * The switch lives in a small JSON file outside git, in the same secrets
 * directory as BindingMode's file, edited by hand:
 *
 *   {"staging": true, "production": false}
 *
 * A tool set is on for an environment only when that key is exactly true.
 * A missing file, a file that does not parse, a missing key or any other value
 * means off, so deploying this code changes nothing until someone writes the
 * file, and a broken file can never switch the tools on. A tool-list change
 * reaches a chat only when it starts a new chat (and refreshes the connector).
 */
final class ProvisioningSwitch
{
    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/provisioning-tools.json';

    /** True only when the file's text sets this environment's key to exactly true. */
    public static function parse(?string $json, string $environment): bool
    {
        if (null === $json) {
            return false;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) && true === ($decoded[$environment] ?? null);
    }

    /** Reads the deploy-time file; $path is only for tests. */
    public static function enabled(string $environment, ?string $path = null): bool
    {
        $path ??= self::CONFIG_PATH;
        if (!\is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);

        return self::parse(\is_string($raw) ? $raw : null, $environment);
    }
}
