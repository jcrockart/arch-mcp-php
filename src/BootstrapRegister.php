<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';
require_once __DIR__.'/ClaudeProjectId.php';

/**
 * The install_bootstrap run (ARCH-62 / ARCH-63, direction settled 2026-10-13):
 * register the caller's Claude project for a project, and say what to do next.
 *
 * It writes nothing into the project. The framework files (whatever arch-bootstrap
 * holds: the constitution and any others) are not copied anywhere; the Claude project chat pulls
 * them with arch_bootstrap_read (BootstrapRead) and saves them into its own
 * project knowledge. Registering the (slug, Claude project ID) pair is what
 * "bootstrapped" means, and it is what the binding check later compares against.
 *
 * Portal does the checking: the caller must be owner or contributor of a
 * provisioned project, and an ID already registered for a different project is
 * refused (code bound_elsewhere, without naming that project) unless the caller
 * may act on both. Re-running for the same pair succeeds with created=false, so
 * the call is safe to repeat.
 *
 * Does no I/O beyond the one Portal call. Never returns file contents.
 */
final class BootstrapRegister
{
    private const NEXT = 'Next: call arch_bootstrap_read with this slug and no path to list the framework files. '
        .'Then read each listed file and save it into this Claude project\'s knowledge exactly as returned; '
        .'read each saved copy back and check its sha256 against the one the tool returned. '
        .'From now on pass claudeProjectId (the ID registered here) on every arch-projects tool call for this project.';

    /**
     * @return array<string, mixed>
     */
    public static function run(PortalCaller $bootstrap, string $slug, string $claudeProjectId, string $claudeProjectName): array
    {
        $id = ClaudeProjectId::normalize($claudeProjectId);
        if (null === $id) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'claudeProjectId must be the GUID from the Claude project URL (https://claude.ai/project/<guid>), or that URL itself.'];
        }
        $label = ClaudeProjectId::label($claudeProjectName);

        $reply = $bootstrap->call('register_binding', $slug, ['claude_project_id' => $id, 'name_label' => '' === $label ? null : $label]);
        if (true !== ($reply['success'] ?? false)) {
            return [
                'success' => false,
                'code' => (string) ($reply['code'] ?? 'server'),
                'error' => 'could not register this Claude project: '.mb_substr((string) ($reply['error'] ?? 'unexpected reply from Portal'), 0, 300),
            ];
        }

        return [
            'success' => true,
            'slug' => $slug,
            'binding' => ['claude_project_id' => $id, 'created' => (bool) ($reply['created'] ?? false), 'rebound' => (bool) ($reply['rebound'] ?? false)],
            'next' => self::NEXT,
        ];
    }
}
