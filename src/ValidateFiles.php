<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateContext.php';

/**
 * Reads the metadata files for the validator: lists a folder the way Python's
 * Path.glob("*<suffix>") did (hidden files included, sorted by name) and
 * decodes one JSON file.
 */
final class ValidateFiles
{
    /**
     * Full paths of the entries in $dir whose name ends in $suffix, sorted.
     * A missing folder has none.
     *
     * @return list<string>
     */
    public static function glob(string $dir, string $suffix): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name && str_ends_with($name, $suffix)) {
                $names[] = $name;
            }
        }
        sort($names, \SORT_STRING);

        return array_map(static fn (string $n): string => $dir.'/'.$n, $names);
    }

    /**
     * Decode a JSON file with objects kept as \stdClass. Invalid JSON is
     * recorded as an error and gives null. A file holding the JSON value null
     * also gives null with no error, exactly as the Python loader behaved, so
     * such a file is skipped. A file that cannot be read at all raises
     * ValidateCrash.
     */
    public static function json(ValidateContext $ctx, string $path): mixed
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new ValidateCrash('the file could not be read');
        }
        $value = json_decode($raw, false, 1024);
        if (null === $value && \JSON_ERROR_NONE !== json_last_error()) {
            $ctx->fail("{$path}: invalid JSON — ".json_last_error_msg());

            return null;
        }

        return $value;
    }
}
