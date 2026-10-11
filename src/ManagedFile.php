<?php

namespace ArchMcp;

/**
 * Naming and header rules for the framework files install_bootstrap puts
 * into a project's assets (ARCH-63, decided 2026-10-11).
 *
 * Assets are flat, so "a dedicated path" is a file-name prefix. A managed
 * file is the arch-bootstrap source with a short header saying it is managed
 * and should not be edited. Users are asked not to edit these files; nothing
 * can enforce that, so the header only makes the intent visible and the
 * install plan (BootstrapPlan) reports any local edit it finds.
 *
 * Header form per type: .md gets an HTML comment (after front matter if the
 * file has any); .json gets a leading "$comment" key, inserted as text so the
 * rest of the file stays byte for byte as it was; .yaml, .yml and .txt get a
 * leading '#' line. Any other type, a JSON file that is not an object, or a
 * JSON file that already has its own "$comment" key, gets no header and
 * withHeader() returns null: the caller reports it as not installed rather
 * than installing it unmarked.
 *
 * Pure functions, no I/O.
 */
final class ManagedFile
{
    public const PREFIX = 'arch-bootstrap--';

    public static function targetName(string $sourceName): string
    {
        return self::PREFIX.$sourceName;
    }

    public static function isManagedName(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && \strlen($name) > \strlen(self::PREFIX);
    }

    /** The arch-bootstrap file name a managed name was made from, or null if it is not a managed name. */
    public static function sourceName(string $targetName): ?string
    {
        return self::isManagedName($targetName) ? substr($targetName, \strlen(self::PREFIX)) : null;
    }

    public static function sha256(string $content): string
    {
        return hash('sha256', $content);
    }

    /** The one-line statement used in every header. */
    public static function comment(string $sourceName): string
    {
        return 'MANAGED FRAMEWORK FILE. Installed from arch-bootstrap ('.$sourceName.') by install_bootstrap. '
            .'Do not edit: local changes are reported and overwritten when bootstrap is run again.';
    }

    /** The content to install: the source with its header, or null (see the class comment). */
    public static function withHeader(string $sourceName, string $content): ?string
    {
        $text = self::comment($sourceName);

        return match (strtolower(pathinfo($sourceName, \PATHINFO_EXTENSION))) {
            'md' => self::markdown($text, $content),
            'json' => self::json($text, $content),
            'yaml', 'yml', 'txt' => '# '.$text."\n".$content,
            default => null,
        };
    }

    private static function markdown(string $text, string $content): string
    {
        $comment = '<!-- '.str_replace('--', '- -', $text).' -->';
        if (str_starts_with($content, "---\n") || str_starts_with($content, "---\r\n")) {
            if (1 === preg_match('/\R---[ \t]*\R/', $content, $m, \PREG_OFFSET_CAPTURE, 3)) {
                $end = $m[0][1] + \strlen($m[0][0]);

                return substr($content, 0, $end).$comment."\n\n".substr($content, $end);
            }
        }

        return $comment."\n\n".$content;
    }

    private static function json(string $text, string $content): ?string
    {
        $decoded = json_decode($content);
        if (!\is_object($decoded) || property_exists($decoded, '$comment')) {
            return null;
        }
        if (1 !== preg_match('/^\s*\{/', $content, $m)) {
            return null;
        }
        $pos = \strlen($m[0]);
        $rest = substr($content, $pos);
        $empty = 1 === preg_match('/^\s*\}/', $rest);
        $encoded = json_encode($text, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($encoded)) {
            return null;
        }
        $out = substr($content, 0, $pos)."\n  \"\$comment\": ".$encoded.($empty ? '' : ',').$rest;
        $check = json_decode($out);
        if (!\is_object($check) || ($check->{'$comment'} ?? null) !== $text) {
            return null;
        }

        return $out;
    }
}
