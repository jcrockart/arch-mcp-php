<?php

namespace ArchMcp;

require_once __DIR__.'/ProjectDatabase.php';

/**
 * What arch_project_info says about a project's outgoing email setup, per
 * environment (added 2026-10-26, ARCH-72), so a chat knows whether the sender
 * address is in place without asking and without being able to edit config.php.
 *
 * Source: that checkout's config.php, READ AS TEXT and never run, the same way
 * ProjectDatabase reads it. Only MAIL_FROM is looked at, and the address itself
 * is NEVER returned: only whether it is set and valid.
 *
 *   from_configured  true   MAIL_FROM is a plain quoted string and a valid email address
 *                    false  MAIL_FROM is absent, or present but not a valid address
 *                    null   cannot tell (no config.php, or MAIL_FROM written in a form
 *                           that cannot be read safely). Never shown as true.
 *   how_app_uses     fixed text, the same for every project, holding no project value
 *   note             why, whenever from_configured is not true
 *
 * Pure apart from reading that one file.
 */
final class ProjectMail
{
    private const HOW_APP_USES = "Send mail with PHP mail() only. Take the From address from the MAIL_FROM constant in config.php (Portal provisions that file: never hard-code the address and do not create or edit config.php). If MAIL_FROM is missing or invalid, send nothing, log why, still save the data and tell the user. MAIL_REPLY_TO and SITE_URL are optional constants in the same file.";

    /**
     * @return array{from_configured: ?bool, how_app_uses?: string, note?: string}
     */
    public static function forCheckout(string $dir): array
    {
        $config = $dir.'/config.php';
        if ('' === $dir || !is_file($config)) {
            return ['from_configured' => null, 'note' => 'no config.php in this checkout, so no sender is configured here; Portal creates config.php when it provisions the project for this environment'];
        }
        $text = (string) file_get_contents($config);

        $arg = ProjectDatabase::argument($text, 'MAIL_FROM');
        if (null === $arg) {
            if (1 === preg_match('/^(?![ \t]*(?:\/\/|#|\*|\/\*)).*\bMAIL_FROM\b/m', $text)) {
                return self::unreadable();
            }

            return ['from_configured' => false, 'how_app_uses' => self::HOW_APP_USES, 'note' => 'MAIL_FROM is not set in config.php, so the app must send no email until it is'];
        }
        $value = ProjectDatabase::literal($arg);
        if (null === $value) {
            return self::unreadable();
        }
        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['from_configured' => false, 'how_app_uses' => self::HOW_APP_USES, 'note' => 'MAIL_FROM is set but is not a valid email address'];
        }

        return ['from_configured' => true, 'how_app_uses' => self::HOW_APP_USES];
    }

    /**
     * @return array{from_configured: null, how_app_uses: string, note: string}
     */
    private static function unreadable(): array
    {
        return ['from_configured' => null, 'how_app_uses' => self::HOW_APP_USES, 'note' => 'MAIL_FROM is set in a form that cannot be read safely, so its state is not shown'];
    }
}
