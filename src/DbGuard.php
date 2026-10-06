<?php

namespace ArchMcp;

/**
 * Who may use the production database tools. Pure decisions, no I/O.
 *
 * Production reads and migrations are for a project's Owner, or for Portal's
 * own service credential (Portal checks the Owner itself in its own screen
 * before it calls). Destructive production migrations may be confirmed ONLY
 * by Portal's service credential, never from a chat.
 */
final class DbGuard
{
    public static function mayUseProd(?string $role, bool $portalService): bool
    {
        return $portalService || 'owner' === $role;
    }

    public static function mayConfirmDestructive(string $kind, bool $portalService): bool
    {
        return 'staging' === $kind || $portalService;
    }
}
