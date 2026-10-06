<?php
// Offline proof of migration reading, planning and applying (DbMigrations).
//
//     php tests/unit/test-db-migrations.php
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
function mig(string $id, string $type, string $sql, ?string $dc = null): string {
    return "-- id: $id\n-- type: $type\n".(null !== $dc ? "-- data-class: $dc\n" : "")."-- description: test $id\n\n$sql\n";
}
function tmpdir(): string {
    $d = sys_get_temp_dir()."/dbt-".bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}
function freshdb(): PDO {
    $pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)");
    return $pdo;
}
use ArchMcp\DbMigrations;

echo "parse()\n";
$p = DbMigrations::parse(mig('m1', 'schema-additive', 'CREATE TABLE a (id INT);', 'reference'));
check('id, type, data-class, sql', 'm1' === $p['id'] && 'schema-additive' === $p['type'] && 'reference' === $p['data-class'] && 'CREATE TABLE a (id INT);' === $p['sql']);
check('description joined', 'test m1' === $p['description']);
check('no header means null', null === DbMigrations::parse("CREATE TABLE a (id INT);\n"));

echo "listDeclared() and pending()\n";
$d = tmpdir();
mkdir($d.'/db/migrations', 0777, true);
file_put_contents($d.'/db/migrations/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
file_put_contents($d.'/db/migrations/0002_b.sql', mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'));
file_put_contents($d.'/db/migrations/0003_c.sql', mig('c', 'schema-destructive', 'DROP TABLE a;'));
file_put_contents($d.'/db/migrations/README.md', 'not a migration');
$dir = $d.'/db/migrations';
check('three declared, in order', ['a', 'b', 'c'] === array_column(DbMigrations::listDeclared($dir), 'id'));
check('missing dir is empty', [] === DbMigrations::listDeclared($d.'/nope'));
$pdo = freshdb();
check('all pending on a fresh db', ['a', 'b', 'c'] === array_column(DbMigrations::pending($dir, $pdo), 'id'));
$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('missing schema_migrations table means all pending', 3 === \count(DbMigrations::pending($dir, $bare)));
file_put_contents($d.'/db/migrations/0004_dup.sql', mig('a', 'schema-additive', 'SELECT 1;'));
$threw = false;
try { DbMigrations::listDeclared($dir); } catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'duplicate'); }
check('duplicate id throws', $threw);
unlink($d.'/db/migrations/0004_dup.sql');

echo "destructiveReason()\n";
check('declared destructive', null !== DbMigrations::destructiveReason(['id' => 'x', 'type' => 'schema-destructive', 'sql' => 'SELECT 1']));
check('transactional data', null !== DbMigrations::destructiveReason(['id' => 'x', 'type' => 'data', 'data-class' => 'transactional', 'sql' => 'SELECT 1']));
check('additive that drops is caught', null !== DbMigrations::destructiveReason(['id' => 'x', 'type' => 'schema-additive', 'sql' => 'ALTER TABLE t DROP COLUMN c']));
check('plain additive is not', null === DbMigrations::destructiveReason(['id' => 'x', 'type' => 'schema-additive', 'sql' => 'ALTER TABLE t ADD COLUMN c INT']));
check('reference data is not', null === DbMigrations::destructiveReason(['id' => 'x', 'type' => 'data', 'data-class' => 'reference', 'sql' => "INSERT INTO t VALUES (1)"]));

echo "plan()\n";
$pending = DbMigrations::pending($dir, $pdo);
$pl = DbMigrations::plan($pending, '', []);
check('all selected', 3 === \count($pl['selected']));
check('the destructive one is unconfirmed, with its sql', 1 === \count($pl['unconfirmed']) && 'c' === $pl['unconfirmed'][0]['id'] && 'DROP TABLE a;' === $pl['unconfirmed'][0]['sql']);
check('safe_up_to is the last one before it', 'b' === $pl['safe_up_to']);
$pl = DbMigrations::plan($pending, '', ['c']);
check('naming the id confirms it', [] === $pl['unconfirmed']);
$pl = DbMigrations::plan($pending, 'b', []);
check('upTo stops early and avoids the destructive one', ['a', 'b'] === array_column($pl['selected'], 'id') && [] === $pl['unconfirmed']);
$pl = DbMigrations::plan($pending, 'zzz', []);
check('unknown upTo is an error', isset($pl['error']) && [] === $pl['selected']);
$first = DbMigrations::plan([['id' => 'd', 'type' => 'schema-destructive', 'sql' => 'DROP TABLE q;']], '', []);
check('destructive first leaves safe_up_to null', null === $first['safe_up_to']);

echo "apply()\n";
$r = DbMigrations::apply($pdo, array_slice($pending, 0, 2), 'agent:test');
check('two applied', true === $r['success'] && ['a', 'b'] === $r['applied']);
check('tables exist', 2 === (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('a','b')")->fetchColumn());
check('recorded with applied_by', 'agent:test' === $pdo->query("SELECT applied_by FROM schema_migrations WHERE id='a'")->fetchColumn());
check('pending is now just c', ['c'] === array_column(DbMigrations::pending($dir, $pdo), 'id'));

echo "apply(): self-registering migration is not recorded twice\n";
$selfReg = ['id' => 's', 'type' => 'schema-additive', 'data-class' => null, 'description' => '', 'sql' => "CREATE TABLE s (id INTEGER);\nINSERT INTO schema_migrations (id, type, applied_at, applied_by) VALUES ('s', 'schema-additive', CURRENT_TIMESTAMP, 'manual');"];
$r = DbMigrations::apply($pdo, [$selfReg], 'agent:test');
check('succeeds', true === $r['success']);
check('one row, keeps its own applied_by', 1 === (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE id='s'")->fetchColumn() && 'manual' === $pdo->query("SELECT applied_by FROM schema_migrations WHERE id='s'")->fetchColumn());

echo "apply(): failure rolls back that migration and stops\n";
$bad = ['id' => 'bad', 'type' => 'schema-additive', 'sql' => "CREATE TABLE ok1 (id INTEGER);\nCREATE TABLE ok1 (id INTEGER);"];
$after = ['id' => 'after', 'type' => 'schema-additive', 'sql' => 'CREATE TABLE after1 (id INTEGER);'];
$r = DbMigrations::apply($pdo, [$bad, $after], 'agent:test');
check('reports failure, names it', false === $r['success'] && 'bad' === $r['failed'] && [] === $r['applied_before_failure']);
check('failed one not recorded', 0 === (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE id='bad'")->fetchColumn());
check('the next one never ran', 0 === (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='after1'")->fetchColumn());
check('half-run first statement rolled back (sqlite DDL is transactional)', 0 === (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='ok1'")->fetchColumn());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
