<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';

/**
 * One call to Portal's api_assets.php with the optional `folder` field (ARCH-81).
 *
 * Portal's asset store has folders: all four actions (list, read, write,
 * set_publish) take an optional `folder` string in the request body. This class
 * adds that field and makes the one call. It does not check the folder: Portal
 * owns the rules (lower-case letters, digits, "-" and "_", up to 4 levels, no
 * "_archive", "bootstrap" owner/admin only) and answers `invalid` or `forbidden`,
 * which come back to the chat unchanged.
 *
 * A folder that is exactly "" is the top level, so no field is sent. That keeps
 * the request body byte for byte what it was before folders existed for every
 * caller who does not use them.
 *
 * Portal's reply is returned as it came: that includes the reply's `folder`,
 * the list reply's `folders`, and the write reply's `archived` count. Nothing
 * is added, dropped or renamed here.
 *
 * Does no I/O beyond the one Portal call.
 */
final class AssetsCall
{
    /**
     * @param 'list'|'read'|'write'|'set_publish' $action
     * @param array<string, mixed>                $fields the action's own fields (name, content, ...)
     *
     * @return array<string, mixed> Portal's reply
     */
    public static function send(PortalCaller $portal, string $action, string $slug, array $fields, string $folder = ''): array
    {
        if ('' !== $folder) {
            $fields['folder'] = $folder;
        }

        return $portal->call($action, $slug, $fields);
    }
}
