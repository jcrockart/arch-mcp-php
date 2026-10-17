<?php

namespace ArchMcp;

/**
 * Is this database brand new? True only when no migration is recorded as applied
 * AND the database holds no tables or views other than the schema_migrations
 * ledger. A first production deploy has nothing to lose, so the destructive-
 * migration confirmation is waived for it (see DbTools::migrate). Anything we
 * cannot positively establish as empty (unknown driver, unreadable catalog) is
 * NOT fresh. Read-only: nothing is created or written.
 */
final class DbFreshCheck
{
    public static function isFresh(\PDO $pdo): bool
    {
        if ([] !== DbMigrations::appliedIds($pdo)) {
            return false;
        }
        $tables = self::tables($pdo);
        if (null === $tables) {
            return false;
        }
        foreach ($tables as $name) {
            if ('schema_migrations' !== strtolower($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|null null when the catalog cannot be read
     */
    private static function tables(\PDO $pdo): ?array
    {
        $sql = match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND substr(name, 1, 7) <> 'sqlite_'",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
            default => null,
        };
        if (null === $sql) {
            return null;
        }
        try {
            $stmt = $pdo->query($sql);

            return false === $stmt ? null : array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            return null;
        }
    }
}
