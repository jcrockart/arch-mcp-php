<?php

namespace ArchMcp;

/**
 * Reading, planning and applying a project's db/migrations/*.sql files for
 * the db_migrate_staging and db_migrate_prod tools. The database handle is
 * passed in, so this is testable offline against a throwaway SQLite file.
 *
 * Differences from the older arch_core_db_apply_migrations, on purpose:
 *   - no policy tiers and no db_apply_allowed flag; instead a migration that
 *     is destructive is applied only when the caller names its id;
 *   - the whole selection is checked BEFORE anything runs, so a refusal
 *     applies nothing (the old tool applied the safe ones and skipped the rest);
 *   - a migration that registers itself in schema_migrations (the 2026-09-28
 *     convention) is not recorded a second time.
 */
final class DbMigrations
{
    /**
     * Parse a migration file's `-- key: value` header and SQL body. Null for
     * a file without a valid id and type header.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $content): ?array
    {
        $id = null;
        $type = null;
        $dataClass = null;
        $descriptionParts = [];
        $sqlLines = [];
        $inHeader = true;

        foreach (explode("\n", $content) as $line) {
            if ($inHeader) {
                if (1 === preg_match('/^--\s*id:\s*(.+)$/', $line, $m)) {
                    $id = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*type:\s*(.+)$/', $line, $m)) {
                    $type = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*data-class:\s*(.+)$/', $line, $m)) {
                    $dataClass = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*description:\s*(.+)$/', $line, $m)) {
                    $descriptionParts[] = trim($m[1]);
                    continue;
                }
                if ([] !== $descriptionParts && 1 === preg_match('/^--\s{2,}(.+)$/', $line, $m)) {
                    $descriptionParts[] = trim($m[1]);
                    continue;
                }
                if ('' === trim($line) || 1 === preg_match('/^--/', $line)) {
                    continue;
                }
                $inHeader = false;
            }
            $sqlLines[] = $line;
        }

        if (null === $id || null === $type) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'data-class' => $dataClass,
            'description' => implode(' ', $descriptionParts),
            'sql' => trim(implode("\n", $sqlLines)),
        ];
    }

    /**
     * Every migration in $dir, in filename order.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException when two files declare the same id
     */
    public static function listDeclared(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.sql') ?: [];
        sort($files);

        $migrations = [];
        $seenBy = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (false === $content) {
                continue;
            }
            $parsed = self::parse($content);
            if (null === $parsed) {
                continue;
            }
            if (isset($seenBy[$parsed['id']])) {
                throw new \RuntimeException(\sprintf(
                    "duplicate migration id '%s' declared in both %s and %s",
                    $parsed['id'],
                    basename($seenBy[$parsed['id']]),
                    basename($file)
                ));
            }
            $seenBy[$parsed['id']] = $file;
            $migrations[] = $parsed;
        }

        return $migrations;
    }

    /**
     * Files in $dir that look like migrations but are ignored, with the reason, so a
     * migration written without its header, or with the wrong extension, is reported
     * instead of silently doing nothing. Files that are not .sql at all are not listed.
     *
     * @return list<array{file: string, problem: string}>
     */
    public static function listSkipped(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = scandir($dir) ?: [];
        sort($names);

        $skipped = [];
        foreach ($names as $name) {
            $path = $dir.'/'.$name;
            if (!is_file($path) || 'sql' !== strtolower(pathinfo($name, \PATHINFO_EXTENSION))) {
                continue;
            }
            if (!str_ends_with($name, '.sql')) {
                $skipped[] = ['file' => $name, 'problem' => "the extension must be lower case '.sql', so this file is ignored"];
                continue;
            }
            $content = file_get_contents($path);
            if (false === $content) {
                $skipped[] = ['file' => $name, 'problem' => 'the file could not be read, so it is ignored'];
                continue;
            }
            if (null === self::parse($content)) {
                $skipped[] = ['file' => $name, 'problem' => self::headerProblem($content)];
            }
        }

        return $skipped;
    }

    /** Which of the two required header lines (id, type) a file lacks, as a sentence. */
    private static function headerProblem(string $content): string
    {
        $hasId = false;
        $hasType = false;
        foreach (explode("\n", $content) as $line) {
            if (1 === preg_match('/^--\s*id:\s*(.+)$/', $line)) {
                $hasId = true;
                continue;
            }
            if (1 === preg_match('/^--\s*type:\s*(.+)$/', $line)) {
                $hasType = true;
                continue;
            }
            if ('' === trim($line) || 1 === preg_match('/^--/', $line)) {
                continue;
            }
            break;
        }
        $missing = [];
        if (!$hasId) {
            $missing[] = '-- id:';
        }
        if (!$hasType) {
            $missing[] = '-- type:';
        }

        return 'missing '.implode(' and ', $missing).' in the header at the top of the file, so it is ignored';
    }

    /**
     * Ids already recorded in schema_migrations; empty when the table does not exist.
     *
     * @return list<string>
     */
    public static function appliedIds(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT id FROM schema_migrations');

            return $stmt ? array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Declared migrations not yet recorded as applied, in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(string $dir, \PDO $pdo): array
    {
        $applied = self::appliedIds($pdo);

        return array_values(array_filter(
            self::listDeclared($dir),
            static fn (array $m): bool => !\in_array($m['id'], $applied, true)
        ));
    }

    /**
     * Why this migration counts as destructive, or null if it does not.
     * Destructive means: declared schema-destructive, declared transactional
     * data, or its SQL body drops, truncates, deletes, renames or alters away
     * something. Declaring a destructive file as additive does not hide it.
     *
     * @param array<string, mixed> $m
     */
    public static function destructiveReason(array $m): ?string
    {
        if ('schema-destructive' === ($m['type'] ?? '')) {
            return 'declared schema-destructive';
        }
        if ('transactional' === ($m['data-class'] ?? null)) {
            return "data-class 'transactional'";
        }
        if (DbSql::looksDestructive((string) ($m['sql'] ?? ''))) {
            return "declared {$m['type']} but the SQL drops, deletes, renames or alters away something";
        }

        return null;
    }

    /**
     * Choose what to run: every pending migration, or those up to and
     * including $upTo. Then work out which of them are destructive and not
     * covered by $confirmedIds.
     *
     * @param list<array<string, mixed>> $pending
     * @param list<string>               $confirmedIds
     *
     * @return array{error?: string, selected: list<array<string, mixed>>, unconfirmed: list<array<string, string>>, safe_up_to: string|null}
     */
    public static function plan(array $pending, string $upTo, array $confirmedIds): array
    {
        $selected = $pending;
        if ('' !== $upTo) {
            $selected = [];
            $found = false;
            foreach ($pending as $m) {
                $selected[] = $m;
                if ($m['id'] === $upTo) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['error' => "upTo '{$upTo}' is not a pending migration", 'selected' => [], 'unconfirmed' => [], 'safe_up_to' => null];
            }
        }

        $unconfirmed = [];
        $safeUpTo = null;
        $hitFirst = false;
        foreach ($selected as $m) {
            $reason = self::destructiveReason($m);
            if (null !== $reason && !\in_array($m['id'], $confirmedIds, true)) {
                $hitFirst = true;
                $unconfirmed[] = ['id' => (string) $m['id'], 'reason' => $reason, 'sql' => (string) $m['sql']];
            } elseif (!$hitFirst) {
                $safeUpTo = (string) $m['id'];
            }
        }

        return ['selected' => $selected, 'unconfirmed' => $unconfirmed, 'safe_up_to' => $safeUpTo];
    }

    /**
     * Makes sure the schema_migrations ledger exists before anything is applied, so a
     * brand-new project's empty database does not make its first migration fail with
     * "no such table". Only the apply step calls this: listing what is pending stays
     * read-only and creates nothing. An existing ledger is never touched, whatever
     * its columns, and the create statement is not even tried when it can be read.
     * Only SQLite and MySQL are known; for any other driver nothing is created.
     *
     * @return string|null an error message, or null when the ledger exists or was created
     */
    public static function ensureLedger(\PDO $pdo): ?string
    {
        try {
            $probe = $pdo->query('SELECT 1 FROM schema_migrations LIMIT 1');
            if (false !== $probe) {
                $probe->closeCursor();
            }

            return null;
        } catch (\PDOException $e) {
            // Not readable: most likely missing. Create it below.
        }

        $ddl = match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => 'CREATE TABLE IF NOT EXISTS schema_migrations ('
                .'id TEXT PRIMARY KEY, type TEXT NOT NULL, data_class TEXT, description TEXT, '
                .'applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, applied_by TEXT)',
            'mysql' => 'CREATE TABLE IF NOT EXISTS schema_migrations ('
                .'id VARCHAR(191) NOT NULL PRIMARY KEY, type VARCHAR(64) NOT NULL, data_class VARCHAR(64) NULL, description TEXT NULL, '
                .'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, applied_by VARCHAR(128) NULL) DEFAULT CHARSET=utf8mb4',
            default => null,
        };
        if (null === $ddl) {
            return null;
        }
        try {
            $pdo->exec($ddl);
        } catch (\PDOException $e) {
            return 'could not create the schema_migrations table: '.$e->getMessage();
        }

        return null;
    }

    /**
     * Run $selected in order, each in its own transaction, recording each in
     * schema_migrations unless the migration already recorded itself.
     *
     * @param list<array<string, mixed>> $selected
     *
     * @return array<string, mixed>
     */
    public static function apply(\PDO $pdo, array $selected, string $appliedBy): array
    {
        $ledgerError = self::ensureLedger($pdo);
        if (null !== $ledgerError) {
            return ['success' => false, 'error' => $ledgerError, 'applied_before_failure' => []];
        }
        $applied = [];
        foreach ($selected as $m) {
            try {
                $pdo->beginTransaction();
                $pdo->exec((string) $m['sql']);

                $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE id = ?');
                $check->execute([$m['id']]);
                if (false === $check->fetchColumn()) {
                    $pdo->prepare(
                        'INSERT INTO schema_migrations (id, type, data_class, description, applied_at, applied_by)
                         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
                    )->execute([$m['id'], $m['type'], $m['data-class'] ?? null, $m['description'] ?? null, $appliedBy]);
                }
                // MySQL commits implicitly at any DDL statement, so there may be no open transaction left.
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $applied[] = (string) $m['id'];
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return [
                    'success' => false,
                    'error' => "migration '{$m['id']}' failed: ".$e->getMessage(),
                    'failed' => (string) $m['id'],
                    'applied_before_failure' => $applied,
                ];
            }
        }

        return ['success' => true, 'applied' => $applied];
    }
}
