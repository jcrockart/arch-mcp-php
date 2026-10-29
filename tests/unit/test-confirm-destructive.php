<?php
// Offline proof that db_migrate_staging / db_migrate_prod use the forgiving
// confirmDestructive (ConfirmIds) through DbTools::migrate: clear refusals, both
// formats accepted, and the production rule unchanged. Throwaway sqlite project.
//
//     php tests/unit/test-confirm-destructive.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/DbSql.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/DbSql.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
foreach (['DbSql', 'DbMigrations', 'DbRead', 'DbConnect', 'DbFreshCheck', 'DbGuard', 'DbTools', 'ConfirmIds'] as $c) {
    require_once $ROOT.'/src/'.$c.'.php';
}
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function mig(string $id, string $type, string $sql): string {
    return "-- id: $id\n-- type: $type\n-- description: test $id\n\n$sql\n";
}
use ArchMcp\DbTools;

$d = realpath(sys_get_temp_dir()) . '/confirmd-' . bin2hex(random_bytes(4));
mkdir($d.'/db/migrations', 0777, true);
$d = realpath($d);
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
file_put_contents($d.'/db/migrations/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
file_put_contents($d.'/db/migrations/0002_b.sql', mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'));
file_put_contents($d.'/db/migrations/0003_c.sql', mig('c', 'schema-destructive', 'DROP TABLE a;'));
file_put_contents($d.'/db/migrations/0004_d.sql', mig('d', 'schema-destructive', 'DROP TABLE b;'));
$boot = new PDO('sqlite:'.$d.'/app.sqlite');
$boot->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)');
// An existing table makes this a NOT-fresh database, so production's destructive rule is exercised.
$boot->exec('CREATE TABLE keep (id INTEGER)');
$boot = null;
$profile = ['root' => $d, 'lanes' => ['core' => '']];
function applied(string $d): int {
    return (int) (new PDO('sqlite:'.$d.'/app.sqlite'))->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
}

echo "nothing passed: the refusal says exactly what to pass\n";
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('refused, nothing applied', false === $r['success'] && 0 === applied($d));
check('error names the ids to pass, on one line', str_contains($r['error'], 'confirmDestructive="c,d"') && !str_contains($r['error'], "\n"));
check('confirm_with carries the same ids', 'c,d' === $r['confirm_with']);
check('destructive list and safe_up_to unchanged', 2 === \count($r['destructive']) && 'b' === $r['safe_up_to'] && str_contains($r['hint'], "upTo='b'"));

echo "\nwrong id: the refusal names what is accepted\n";
$r = DbTools::migrate($profile, 'staging', '', 'x', true);
check('refused, nothing applied', false === $r['success'] && 0 === applied($d));
check('unknown id and accepted ids named', str_contains($r['error'], 'no destructive migration has the id(s) x') && str_contains($r['error'], 'ids that would be accepted: c,d'));

echo "\nonly one of two confirmed\n";
$r = DbTools::migrate($profile, 'staging', '', 'c', true);
check('refused, nothing applied (not even the safe ones)', false === $r['success'] && 0 === applied($d));
check('names the one still missing', str_contains($r['error'], 'still unconfirmed: d') && !str_contains($r['error'], 'no destructive migration has'));
check('only d listed as destructive', ['d'] === array_column($r['destructive'], 'id'));

echo "\nmalformed list\n";
$r = DbTools::migrate($profile, 'staging', '', '["c",', true);
check('refused with the formats named, nothing applied', false === $r['success'] && 0 === applied($d) && str_contains($r['error'], 'comma-separated') && str_contains($r['error'], 'JSON list'));

echo "\nproduction from a chat: still cannot confirm\n";
$r = DbTools::migrate($profile, 'production', '', 'c,d', false);
check('refused, says Portal confirms, nothing applied', false === $r['success'] && 0 === applied($d) && str_contains($r['error'], 'confirmed in Portal'));
check('no confirm_with offered for a chat on production', !isset($r['confirm_with']));
$r = DbTools::migrate($profile, 'production', '', '["c", "d"', false);
check('a malformed list from a production chat gets the Portal message, not a format error', false === $r['success'] && str_contains($r['error'], 'confirmed in Portal'));

echo "\nJSON list with whitespace everywhere\n";
$r = DbTools::migrate($profile, 'staging', '', " [ \"c\" ,\n \" d \" ] ", true);
check('applied a, b, c and d', true === $r['success'] && ['a', 'b', 'c', 'd'] === $r['applied'] && 4 === applied($d));

echo "\ncomma-separated with spaces\n";
file_put_contents($d.'/db/migrations/0005_e.sql', mig('e', 'schema-destructive', 'DROP TABLE keep;'));
file_put_contents($d.'/db/migrations/0006_f.sql', mig('f', 'schema-destructive', 'CREATE TABLE g (id INTEGER);'));
$r = DbTools::migrate($profile, 'staging', '', ' e , f ', true);
check('applied e and f', true === $r['success'] && ['e', 'f'] === $r['applied'] && 6 === applied($d));

exec('rm -rf '.escapeshellarg($d));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
