<?php
// Offline proof of the fresh-database waiver: DbFreshCheck and its use in DbTools::migrate.
//
//     php tests/unit/test-db-fresh.php
$ROOT = __DIR__;
while (!is_file($ROOT."/src/DbSql.php") && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT."/src/DbSql.php")) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
foreach (["DbSql", "DbMigrations", "DbConnect", "DbFreshCheck", "DbTools"] as $c) {
    require_once $ROOT."/src/".$c.".php";
}
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? "PASS" : "FAIL", $what);
}
function mig(string $id, string $type, string $sql): string {
    return "-- id: $id\n-- type: $type\n-- description: test $id\n\n$sql\n";
}
function tmpdir(): string {
    $d = sys_get_temp_dir()."/dbf-".bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return realpath($d);
}
function liveTables(string $d): array {
    $p = new PDO('sqlite:'.$d.'/app.sqlite');
    return $p->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('a','b','d') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
use ArchMcp\DbFreshCheck;
use ArchMcp\DbTools;

echo "DbFreshCheck::isFresh()\n";
$p = new PDO('sqlite::memory:');
check('a completely empty database is fresh', DbFreshCheck::isFresh($p));
$p->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)');
check('only the empty ledger is still fresh', DbFreshCheck::isFresh($p));
$p->exec("INSERT INTO schema_migrations (id, type) VALUES ('x', 'schema-additive')");
check('an applied migration means not fresh', !DbFreshCheck::isFresh($p));

$p = new PDO('sqlite::memory:');
$p->exec('CREATE TABLE customers (id INTEGER)');
check('a table with no ledger is NOT fresh', !DbFreshCheck::isFresh($p));
$p->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT)');
check('a table plus an empty ledger is NOT fresh', !DbFreshCheck::isFresh($p));

$p = new PDO('sqlite::memory:');
$p->exec('CREATE TABLE t (id INTEGER)');
$p->exec('CREATE VIEW v AS SELECT id FROM t');
$p->exec('DROP TABLE t');
check('a leftover view is NOT fresh', !DbFreshCheck::isFresh($p));

echo "DbTools::migrate(): waiver applies only to a fresh production database\n";
$d = tmpdir();
mkdir($d.'/db/migrations', 0777, true);
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
file_put_contents($d.'/db/migrations/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
file_put_contents($d.'/db/migrations/0002_b.sql', mig('b', 'schema-destructive', 'DROP TABLE a;'));
$profile = ['root' => $d, 'lanes' => ['core' => '']];

$r = DbTools::migrate($profile, 'staging', '', '', false);
check('staging with no confirmation is still refused (waiver is production only)', false === $r['success'] && [] === liveTables($d));

$r = DbTools::migrate($profile, 'production', '', '', false);
check('fresh production from a chat caller applies everything', true === $r['success'] && ['a', 'b'] === $r['applied'] && 0 === $r['pending_after']);
check('the destructive migration really ran, and the reply says fresh', [] === liveTables($d) && true === ($r['fresh_database'] ?? false));

file_put_contents($d.'/db/migrations/0003_c.sql', mig('c', 'schema-additive', 'CREATE TABLE d (id INTEGER);'));
file_put_contents($d.'/db/migrations/0004_d.sql', mig('d', 'schema-destructive', 'DROP TABLE d;'));
$r = DbTools::migrate($profile, 'production', '', '', false);
check('a later destructive migration is refused from a chat', false === $r['success'] && str_contains($r['error'], 'confirmed in Portal') && 'd' === $r['destructive'][0]['id']);
check('and nothing from that batch was applied', [] === liveTables($d));
$r = DbTools::migrate($profile, 'production', 'c', '', false);
check('the safe part can still be applied with upTo', true === $r['success'] && ['c'] === $r['applied'] && !isset($r['fresh_database']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
