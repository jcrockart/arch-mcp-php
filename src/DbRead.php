<?php

namespace ArchMcp;

/**
 * Read-only SELECT against a project's own database, for db_read_staging and
 * db_read_prod. Two layers: the text is checked first (DbSql::checkReadOnly),
 * and the query then runs inside a read-only transaction (MySQL) or with
 * query_only on (SQLite), so a statement the text check missed still cannot
 * write. The handle is passed in, so this is testable offline.
 */
final class DbRead
{
    public const DEFAULT_ROWS = 50;
    public const MAX_ROWS = 200;
    private const MAX_CELL = 2000;

    /**
     * @param bool $redact blank the value of any column whose name looks like a
     *                     secret, and refuse queries that name a secret table or column
     *
     * @return array<string, mixed>
     */
    public static function run(\PDO $pdo, string $sql, int $maxRows, bool $redact): array
    {
        $cap = max(1, min(self::MAX_ROWS, $maxRows));
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $backslash = 'sqlite' !== $driver;

        $refusal = DbSql::checkReadOnly($sql, $backslash);
        if (null !== $refusal) {
            return ['success' => false, 'error' => 'refused: '.$refusal];
        }
        if ($redact) {
            $names = DbSql::sensitiveNamesIn($sql, $backslash);
            if ([] !== $names) {
                return ['success' => false, 'error' => 'refused: the query names columns or tables that hold secrets ('.implode(', ', $names).')'];
            }
        }

        $inTx = false;
        try {
            if ('sqlite' === $driver) {
                $pdo->exec('PRAGMA query_only = ON');
            } else {
                try {
                    $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000');
                } catch (\PDOException $e) {
                    // not supported on this server version; the row cap still applies
                }
                $pdo->exec('START TRANSACTION READ ONLY');
                $inTx = true;
            }

            $stmt = $pdo->query($sql);
            $rows = [];
            $truncated = false;
            while (false !== ($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                if (\count($rows) >= $cap) {
                    $truncated = true;
                    break;
                }
                $rows[] = $row;
            }
            $stmt->closeCursor();
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => 'query failed: '.$e->getMessage()];
        } finally {
            if ($inTx) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\PDOException $e) {
                    // nothing to roll back
                }
            }
        }

        $columns = [] === $rows ? [] : array_keys($rows[0]);
        $redacted = [];
        foreach ($columns as $col) {
            if ($redact && DbSql::isSensitiveName((string) $col)) {
                $redacted[] = (string) $col;
            }
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $col => $value) {
                if (\in_array((string) $col, $redacted, true)) {
                    $rows[$i][$col] = '[redacted]';
                } elseif (\is_string($value)) {
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        $rows[$i][$col] = '[binary, '.\strlen($value).' bytes]';
                    } elseif (\strlen($value) > self::MAX_CELL) {
                        $rows[$i][$col] = substr($value, 0, self::MAX_CELL).'... [cut at '.self::MAX_CELL.' of '.\strlen($value).' bytes]';
                    }
                }
            }
        }

        $result = [
            'success' => true,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => \count($rows),
            'max_rows' => $cap,
            'truncated' => $truncated,
        ];
        if ([] !== $redacted) {
            $result['redacted_columns'] = $redacted;
        }

        return $result;
    }
}
