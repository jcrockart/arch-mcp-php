<?php

namespace ArchMcp;

/**
 * The generic guidance arch-mcp can hand to a client in its MCP `initialize`
 * response (the `instructions` string), ARCH-75. It exists so Portal's
 * "Give this to Claude" starter text could shrink to "This project is <slug>.
 * Use the ARCH connector." IF claude.ai passes the string to the model, which is
 * NOT known: a staging test decides, so this is behind a switch and the current
 * starter text stays as the fallback.
 *
 * The text points at the ARCH project constitution; it does not repeat its
 * rules. It says the constitution wins if the two ever differ, and it names only
 * tools the server registers (a gate test checks that, so the text cannot drift
 * away from the tool list). It is generic on purpose: the connector is set up
 * once per account, so it reaches every chat that has the connector, and it
 * cannot know the project. The opening line limits it to chats about an ARCH
 * project.
 *
 * Switch: a small JSON file outside git, in the same secrets directory as
 * provisioning-tools.json, edited by hand:
 *
 *   {"staging": true, "production": false}
 *
 * The text is on for an environment only when that key is exactly true. A
 * missing file, a file that does not parse, a missing key or any other value
 * means off, which gives null, and null makes the server build exactly as it did
 * before. Deploying this code therefore changes nothing until someone writes the
 * file. A change takes effect for a chat only when the connector is reconnected
 * and a new chat is started.
 */
final class ServerInstructions
{
    /** Version tag and test marker: a chat that received the text can say it. */
    public const GUIDANCE_ID = 'arch-g1';

    public const TEXT = <<<'TXT'
ARCH connector guidance (id arch-g1).
Use this only when the person is working on an ARCH project, which they will name by its slug.
1. First call arch_project_info with that slug. Its binding section gives the claudeProjectId: pass it on every other call, and never ask the person for it. If it says this Claude project is not registered, follow its note.
2. Then call arch_bootstrap_read with the slug to list the framework files, read the ARCH project constitution it lists, and follow it for the rest of the chat. The constitution is the rule book. This text only points to it; if they ever differ, the constitution wins.
3. Never ask for, accept or repeat a password, token or key. Never guess a value, an address or a result: say you do not know and ask.
4. If a tool refuses or fails, tell the person plainly and stop. Do not look for another route.
TXT;

    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/server-instructions.json';

    /** True only when the file's text sets this environment's key to exactly true. */
    public static function parse(?string $json, string $environment): bool
    {
        if (null === $json) {
            return false;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) && true === ($decoded[$environment] ?? null);
    }

    /**
     * The text to give the server for this environment, or null when the switch
     * is off. $path is only for tests.
     */
    public static function forEnvironment(string $environment, ?string $path = null): ?string
    {
        $path ??= self::CONFIG_PATH;
        if (!\is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return self::parse(\is_string($raw) ? $raw : null, $environment) ? self::TEXT : null;
    }
}
