<?php
// Offline proof that the schema_migrations ledger is created automatically when
// migrations are applied (DbMigrations::ensureLedger, apply, and DbTools::migrate
// end to end), and ONLY then: listing what is pending stays read-only. Throwaway
// SQLite databases; no network, no secrets. MySQL's statement is not exercised
// here (no MySQL offline).
//
//     php tests/unit/test-db-ledger.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/DbSql.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/DbSql.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
foreach (['DbSql', 'DbMigrations', 'DbRead', 'DbConnect', 'DbGuard', 'DbTools'] as $c) {
    require_once $ROOT.'/src/'.$c.'.php';
}

use ArchMcp\DbMigrations;
use ArchMcp\DbTools;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function mem(): PDO {
    $p = new PDO('sqlite::memory:');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $p;
}
/** @return list<string> */
function tables(PDO $p): array {
    return $p->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
/** @return list<string> */
function cols(PDO $p, string $t): array {
    return array_column($p->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
}
/** @return array<string, mixed> */
function m(string $id, string $sql, string $type = 'schema-additive'): array {
    return ['id' => $id, 'type' => $type, 'data-class' => null, 'description' => "test $id", 'sql' => $sql];
}
function mig(string $id, string $type, string $sql): string {
    return "-- id: $id\n-- type: $type\n-- description: test $id\n\n$sql\n";
}

echo "ensureLedger()\n";
$p = mem();
check('empty database: created, no error', null === DbMigrations::ensureLedger($p) && ['schema_migrations'] === tables($p));
check('with the columns the apply step writes', ['id', 'type', 'data_class', 'description', 'applied_at', 'applied_by'] === cols($p, 'schema_migrations'));
check('a second call changes nothing', null === DbMigrations::ensureLedger($p) && ['schema_migrations'] === tables($p));
$p = mem();
$p->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, note TEXT)');
check('an existing ledger is never touched, whatever its columns', null === DbMigrations::ensureLedger($p) && ['id', 'type', 'note'] === cols($p, 'schema_migrations'));
$p = mem();
$p->exec('PRAGMA query_only = ON');
$err = DbMigrations::ensureLedger($p);
check('a database that cannot be written: a clear error, nothing created', \is_string($err) && str_contains($err, 'could not create the schema_migrations table') && [] === tables($p));

echo "\nlisting what is pending stays read-only\n";
$dir = sys_get_temp_dir().'/ledger'.getmypid().'-'.bin2hex(random_bytes(3));
mkdir($dir, 0777, true);
file_put_contents($dir.'/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
$p = mem();
$pending = DbMigrations::pending($dir, $p);
check('pending() on a fresh database lists the migration', 1 === \count($pending) && 'a' === $pending[0]['id']);
check('...and creates nothing, not even the ledger', [] === tables($p));

echo "\napply(): a brand-new, empty database\n";
$p = mem();
$r = DbMigrations::apply($p, [m('a', 'CREATE TABLE a (id INTEGER);'), m('b', 'CREATE TABLE b (id INTEGER);')], 'tester');
check('the first migration no longer fails for want of the ledger', true === $r['success'] && ['a', 'b'] === $r['applied']);
check('the tables and the ledger exist', ['a', 'b', 'schema_migrations'] === tables($p));
check('both are recorded, with who applied them', [['id' => 'a', 'applied_by' => 'tester'], ['id' => 'b', 'applied_by' => 'tester']] === $p->query('SELECT id, applied_by FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

echo "\napply(): a migration that creates and fills the ledger itself\n";
$p = mem();
$self = "CREATE TABLE IF NOT EXISTS schema_migrations (id TEXT PRIMARY KEY, type TEXT NOT NULL, data_class TEXT, description TEXT, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, applied_by TEXT);\n"
    ."CREATE TABLE t (id INTEGER);\n"
    ."INSERT INTO schema_migrations (id, type, applied_by) VALUES ('self', 'schema-additive', 'self');";
$r = DbMigrations::apply($p, [m('self', $self)], 'tester');
check('with IF NOT EXISTS it still works', true === $r['success'] && ['self'] === $r['applied']);
check('and is recorded once, keeping its own applied_by', [['id' => 'self', 'applied_by' => 'self']] === $p->query('SELECT id, applied_by FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC));

echo "\napply(): a migration that creates the ledger WITHOUT IF NOT EXISTS\n";
$p = mem();
$r = DbMigrations::apply($p, [m('first', 'CREATE TABLE schema_migrations (id TEXT PRIMARY KEY);'), m('second', 'CREATE TABLE later (id INTEGER);')], 'tester');
check('fails clearly, naming the migration', false === $r['success'] && 'first' === $r['failed'] && str_contains($r['error'], 'already exists'));
check('and stops: the next one never ran', [] === $r['applied_before_failure'] && !\in_array('later', tables($p), true));
check('the automatic ledger is there, empty', ['schema_migrations'] === tables($p) && 0 === (int) $p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());

echo "\napply(): a database that cannot be written\n";
$p = mem();
$p->exec('PRAGMA query_only = ON');
$r = DbMigrations::apply($p, [m('a', 'CREATE TABLE a (id INTEGER);')], 'tester');
check('refused before anything runs, with the reason', false === $r['success'] && str_starts_with($r['error'], 'could not create') && [] === $r['applied_before_failure'] && [] === tables($p));

echo "\nthrough the tools: a project with an empty SQLite file\n";
$d = realpath(sys_get_temp_dir()).'/ledgerproj'.getmypid();
mkdir($d.'/db/migrations', 0777, true);
$d = realpath($d);
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
file_put_contents($d.'/db/migrations/0001_first.sql', mig('first', 'schema-additive', 'CREATE TABLE todos (id INTEGER PRIMARY KEY, title TEXT NOT NULL);'));
$profile = ['root' => $d, 'lanes' => ['core' => '']];
$r = DbTools::pending($profile, 'staging');
check('db_pending sees the migration', true === $r['success'] && 1 === $r['pending_count']);
$names = (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
check('and wrote nothing to the database', [] === $names);
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('db_migrate applies the first migration on the empty database', true === $r['success'] && ['first'] === $r['applied'] && 0 === $r['pending_after']);
$names = (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
check('the ledger and the table are both there', ['schema_migrations', 'todos'] === $names);
$r = DbTools::pending($profile, 'staging');
check('and nothing is pending afterwards', 0 === $r['pending_count'] && 'nothing pending' === $r['note']);

exec('rm -rf '.escapeshellarg($d).' '.escapeshellarg($dir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
