<?php

namespace ArchMcp;

require_once __DIR__.'/ClaudeProjectId.php';
require_once __DIR__.'/BindingMode.php';

/**
 * The decision behind the Claude project binding check (ARCH-62, decided
 * 2026-10-11). A guard against mistakes, not a security boundary: it stops a
 * chat that is not part of a project from calling that project's tools by
 * accident, and gives each call an auditable link to its project. Membership
 * and role are still checked on every call from the OAuth identity; this is
 * an extra check on top and never replaces them.
 *
 * Pure function: no I/O. The caller supplies what Portal's registry says and
 * acts on the answer. It is deliberately not called for install_bootstrap,
 * which creates the binding, nor for the arch_bootstrap_fill_inception_row
 * tool; leaving those two out is the caller's job.
 *
 * Callers ($caller):
 *   user            an OAuth-authenticated person: the check applies
 *   portal_service  Portal's own service credential: exempt (it has no Claude project)
 *   breakglass      the static-secret recovery address: exempt, but logged as a warning
 *
 * Registry input: $registered is the list of GUIDs registered for the slug, or
 * null when the registry could not be read. $grandfathered says the project
 * existed before the rollout and has never been bootstrapped; such a project
 * keeps working without a binding, but once a GUID is registered for it the
 * check applies in full.
 *
 * Result: allow (does the call go ahead), outcome, violation (a rule failed),
 * error (the message to give the caller when a violation blocks, also set in
 * log-only mode so the log can carry it) and log (the caller should write a
 * log line). In log-only mode a violation is allowed but still reported.
 * When the registry cannot be read, enforce mode refuses (same fail-closed
 * posture as the rest of this server) and log-only mode allows.
 */
final class BindingGuard
{
    public const CALLER_USER = 'user';
    public const CALLER_PORTAL_SERVICE = 'portal_service';
    public const CALLER_BREAKGLASS = 'breakglass';

    /**
     * @param list<string>|null $registered
     *
     * @return array{allow: bool, outcome: string, violation: bool, error: ?string, log: bool}
     */
    public static function decide(string $mode, string $caller, string $claudeProjectId, ?array $registered, bool $grandfathered): array
    {
        if (self::CALLER_PORTAL_SERVICE === $caller) {
            return self::result($mode, 'exempt_service', null, false);
        }
        if (self::CALLER_BREAKGLASS === $caller) {
            return self::result($mode, 'exempt_breakglass', null, true);
        }
        if (null === $registered) {
            return self::result($mode, 'registry_unavailable', 'could not check which Claude projects are registered for this project; try again', true);
        }
        if ([] === $registered) {
            if ($grandfathered) {
                return self::result($mode, 'grandfathered', null, false);
            }

            return self::result($mode, 'not_bootstrapped', 'project not bootstrapped, run bootstrap first', true);
        }
        if ('' === trim($claudeProjectId)) {
            return self::result($mode, 'missing_id', 'this project is bootstrapped, so every call must include claudeProjectId (the GUID in the Claude project URL)', true);
        }
        $id = ClaudeProjectId::normalize($claudeProjectId);
        if (null === $id) {
            return self::result($mode, 'invalid_id', 'claudeProjectId is not a valid Claude project ID (expected the GUID from https://claude.ai/project/<guid>)', true);
        }
        if (!\in_array($id, $registered, true)) {
            return self::result($mode, 'unregistered_id', 'this Claude project is not registered for this project', true);
        }

        return self::result($mode, 'ok', null, false);
    }

    /**
     * @return array{allow: bool, outcome: string, violation: bool, error: ?string, log: bool}
     */
    private static function result(string $mode, string $outcome, ?string $error, bool $log): array
    {
        $violation = null !== $error;

        return [
            'allow' => !$violation || BindingMode::ENFORCE !== $mode,
            'outcome' => $outcome,
            'violation' => $violation,
            'error' => $error,
            'log' => $log,
        ];
    }
}
