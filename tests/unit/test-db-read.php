<?php
// Offline proof of the read-only query runner (DbRead).
//
//     php tests/unit/test-db-read.php
$ROOT = __DIR__;
while (!is_file($ROOT."/src/DbSql.php") && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT."/src/DbSql.php")) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
foreach (["DbSql", "DbMigrations", "DbRead", "DbConnect", "DbGuard", "DbTools"] as $c) {
    require_once $ROOT."/src/".$c.".php";
}
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? "PASS" : "FAIL", $what);
}
use ArchMcp\DbRead;

function seeded(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, notes TEXT, blob_col BLOB)');
    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, notes, blob_col) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= 300; ++$i) {
        $ins->execute(["u$i@example.com", "hash$i", str_repeat('n', 5), null]);
    }
    $pdo->exec('CREATE TABLE oauth_clients (id INTEGER, client_secret TEXT)');
    return $pdo;
}
$pdo = seeded();

echo "basic read\n";
$r = DbRead::run($pdo, 'SELECT id, email FROM users ORDER BY id', 3, true);
check('success, 3 rows, truncated', true === $r['success'] && 3 === $r['row_count'] && true === $r['truncated'] && ['id', 'email'] === $r['columns']);
check('first row content', 'u1@example.com' === $r['rows'][0]['email']);
$r = DbRead::run($pdo, 'SELECT id FROM users WHERE id < 3', 50, true);
check('not truncated when under the cap', 2 === $r['row_count'] && false === $r['truncated']);
$r = DbRead::run($pdo, 'SELECT id FROM users', 9999, true);
check('cap is clamped to 200', 200 === $r['row_count'] && 200 === $r['max_rows'] && true === $r['truncated']);
$r = DbRead::run($pdo, 'SELECT id FROM users', 0, true);
check('cap of 0 becomes 1', 1 === $r['row_count']);
$r = DbRead::run($pdo, 'SELECT id FROM users WHERE id < 0', 5, true);
check('empty result is success with no rows', true === $r['success'] && 0 === $r['row_count'] && [] === $r['rows']);

echo "redaction and secret names\n";
$r = DbRead::run($pdo, 'SELECT * FROM users LIMIT 2', 5, true);
check('SELECT * redacts the hash column', true === $r['success'] && '[redacted]' === $r['rows'][0]['password_hash'] && ['password_hash'] === $r['redacted_columns']);
check('other columns untouched', 'u1@example.com' === $r['rows'][0]['email']);
$r = DbRead::run($pdo, 'SELECT password_hash FROM users', 5, true);
check('naming a secret column is refused', false === $r['success'] && str_contains($r['error'], 'password_hash'));
$r = DbRead::run($pdo, 'SELECT * FROM oauth_clients', 5, true);
check('naming a secret table is refused', false === $r['success'] && str_contains($r['error'], 'oauth_clients'));
$r = DbRead::run($pdo, 'SELECT id, password_hash FROM users LIMIT 1', 5, false);
check('with redaction off (staging) it is returned', true === $r['success'] && 'hash1' === $r['rows'][0]['password_hash'] && !isset($r['redacted_columns']));
$r = DbRead::run($pdo, 'SELECT password_hash AS h FROM users LIMIT 1', 5, true);
check('aliasing a secret column is still refused by name', false === $r['success']);
$r = DbRead::run($pdo, "SELECT 'x' AS password_hash", 5, true);
check('an alias that looks secret is refused too', false === $r['success']);

echo "refusals\n";
foreach (['DELETE FROM users', 'DROP TABLE users', 'INSERT INTO users (email) VALUES (1)', 'SELECT 1; DELETE FROM users', 'UPDATE users SET email = 1'] as $bad) {
    $r = DbRead::run($pdo, $bad, 5, false);
    check('refused: '.$bad, false === $r['success'] && str_starts_with($r['error'], 'refused'));
}
check('table still has 300 rows', 300 === (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

echo "backstop: the connection itself is read-only\n";
$caught = false;
try { $pdo->exec('DELETE FROM users WHERE id = 1'); } catch (PDOException $e) { $caught = true; }
check('after a read, writes on that handle fail (query_only)', $caught);

echo "odd values\n";
$pdo2 = seeded();
$pdo2->exec("UPDATE users SET blob_col = X'FFFE00' WHERE id = 1");
$pdo2->exec("UPDATE users SET notes = '".str_repeat('z', 5000)."' WHERE id = 2");
$r = DbRead::run($pdo2, 'SELECT id, blob_col, notes FROM users WHERE id IN (1, 2) ORDER BY id', 5, true);
check('binary becomes a marker, and the result is JSON-safe', str_starts_with((string) $r['rows'][0]['blob_col'], '[binary') && false !== json_encode($r));
check('long text is cut and says so', str_contains((string) $r['rows'][1]['notes'], '[cut at 2000 of 5000 bytes]'));
$r = DbRead::run($pdo2, 'SELECT * FROM missing_table', 5, true);
check('sql errors come back as failure', false === $r['success'] && str_contains($r['error'], 'query failed'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
