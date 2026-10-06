<?php

namespace ArchMcp;

/**
 * Connects to a project's OWN database through its own config.php, the same
 * way ArchTools::connectProjectDb does, with one extra protection: that
 * config.php defines constants, which can only be set once per PHP process.
 * If a second call named a different project root, the first project's
 * database would be used silently. This class refuses instead.
 */
final class DbConnect
{
    private static ?string $loadedRoot = null;

    /**
     * @return array{pdo?: \PDO, error?: string, engine?: string}
     */
    public static function connect(string $root): array
    {
        $configPath = $root.'/config.php';
        if (!is_file($configPath)) {
            return ['error' => "this project has no config.php to read its database settings from"];
        }
        if (null !== self::$loadedRoot && self::$loadedRoot !== $root) {
            return ['error' => "another project's database settings are already loaded in this request; retry the call on its own"];
        }
        if (null === self::$loadedRoot) {
            if (\defined('DB_ENGINE') || \defined('DB_HOST')) {
                return ['error' => 'database settings were already loaded in this request by something else; retry the call on its own'];
            }
            require_once $configPath;
            self::$loadedRoot = $root;
        }

        $engine = \defined('DB_ENGINE') ? \DB_ENGINE : 'mysql';
        try {
            if ('sqlite' === $engine) {
                if (!\defined('DB_PATH')) {
                    return ['error' => 'config.php says sqlite but defines no DB_PATH'];
                }
                $pdo = new \PDO(\sprintf('sqlite:%s', \DB_PATH), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            } else {
                if (!\defined('DB_HOST') || !\defined('DB_NAME') || !\defined('DB_USER') || !\defined('DB_PASS')) {
                    return ['error' => 'config.php does not define DB_HOST, DB_NAME, DB_USER and DB_PASS'];
                }
                $pdo = new \PDO(
                    \sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', \DB_HOST, \DB_NAME),
                    \DB_USER,
                    \DB_PASS,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            }
        } catch (\PDOException $e) {
            return ['error' => "could not connect to this project's database"];
        }

        return ['pdo' => $pdo, 'engine' => $engine];
    }
}
