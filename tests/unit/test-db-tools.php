<?php
// Offline proof of the database tools end to end, the role guard and the Portal-caller check
// (DbTools, DbConnect, DbGuard, OAuthBearer::isPortalService).
//
//     php tests/unit/test-db-tools.php
$ROOT = __DIR__;
while (!is_file($ROOT."/src/DbSql.php") && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT."/src/DbSql.php")) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
foreach (["DbSql", "DbMigrations", "DbRead", "DbConnect", "DbGuard", "DbTools", "OAuthBearer"] as $c) {
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
use ArchMcp\DbConnect;
use ArchMcp\DbGuard;
use ArchMcp\DbTools;
use ArchMcp\OAuthBearer;

echo "DbGuard\n";
check('owner may use prod', DbGuard::mayUseProd('owner', false));
check('member may not', !DbGuard::mayUseProd('member', false));
check('no role may not', !DbGuard::mayUseProd(null, false));
check('portal service may, whatever the role', DbGuard::mayUseProd(null, true) && DbGuard::mayUseProd('member', true));
check('staging: anyone may confirm destructive', DbGuard::mayConfirmDestructive('staging', false));
check('production: a chat user (even an owner) may not', !DbGuard::mayConfirmDestructive('production', false));
check('production: portal service may', DbGuard::mayConfirmDestructive('production', true));

// A throwaway project: config.php for sqlite, three migrations.
$d = realpath(tmpdir());
mkdir($d.'/db/migrations', 0777, true);
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
file_put_contents($d.'/db/migrations/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
file_put_contents($d.'/db/migrations/0002_b.sql', mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'));
file_put_contents($d.'/db/migrations/0003_c.sql', mig('c', 'schema-destructive', 'DROP TABLE a;'));
$boot = new PDO('sqlite:'.$d.'/app.sqlite');
$boot->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)');
$boot = null;
$profile = ['root' => $d, 'lanes' => ['core' => '']];
function tables(string $d): array {
    $p = new PDO('sqlite:'.$d.'/app.sqlite');
    return $p->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('a','b') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

echo "coreRoot()\n";
check('empty lane subdir is the root', $d === DbTools::coreRoot($profile));
check('no core lane is null', null === DbTools::coreRoot(['root' => $d, 'lanes' => ['site' => 'x']]));
mkdir($d.'/sub');
check('a real subdir works', $d.'/sub' === DbTools::coreRoot(['root' => $d, 'lanes' => ['core' => 'sub']]));
check('a lane that climbs out is refused', null === DbTools::coreRoot(['root' => $d.'/sub', 'lanes' => ['core' => '..']]));

echo "migrate(): destructive in the selection stops everything\n";
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('refused, nothing applied', false === $r['success'] && [] === tables($d) && 1 === \count($r['destructive']) && 'c' === $r['destructive'][0]['id']);
check('says how far is safe', 'b' === $r['safe_up_to'] && str_contains($r['hint'], "upTo='b'"));
check('the destructive sql is shown for review', 'DROP TABLE a;' === $r['destructive'][0]['sql']);

echo "migrate(): production caller from a chat cannot confirm\n";
$r = DbTools::migrate($profile, 'production', '', 'c', false);
check('refused even though confirmDestructive named it', false === $r['success'] && [] === tables($d) && str_contains($r['error'], 'confirmed in Portal'));

echo "migrate(): upTo applies the safe part\n";
$r = DbTools::migrate($profile, 'staging', 'b', '', true);
check('a and b applied', true === $r['success'] && ['a', 'b'] === $r['applied'] && ['a', 'b'] === tables($d));
check('reports one still pending', 1 === $r['pending_after'] && 'staging' === $r['environment']);
$by = (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT applied_by FROM schema_migrations WHERE id='a'")->fetchColumn();
check('staging marker recorded', 'agent:db_migrate_staging' === $by);

echo "migrate(): portal service confirms the destructive one on production\n";
$r = DbTools::migrate($profile, 'production', '', 'c', true);
check('c applied, table a gone', true === $r['success'] && ['c'] === $r['applied'] && ['b'] === tables($d) && 0 === $r['pending_after']);
$by = (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT applied_by FROM schema_migrations WHERE id='c'")->fetchColumn();
check('production marker recorded', 'agent:db_migrate_prod' === $by);

echo "migrate(): nothing pending\n";
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('success, empty', true === $r['success'] && [] === $r['applied'] && 0 === $r['pending_after']);

echo "read()\n";
$r = DbTools::read($profile, 'SELECT id FROM schema_migrations ORDER BY id', 10, true);
check('reads the same database', true === $r['success'] && 3 === $r['row_count']);
$r = DbTools::read($profile, 'DELETE FROM schema_migrations', 10, true);
check('write refused', false === $r['success']);
check('no core lane is a clean error', false === DbTools::read(['root' => $d, 'lanes' => []], 'SELECT 1', 5, true)['success']);

echo "DbConnect: a second project in the same process is refused\n";
$d2 = realpath(tmpdir());
file_put_contents($d2.'/config.php', "<?php\n");
$c = DbConnect::connect($d2);
check('refused with a clear message', isset($c['error']) && str_contains($c['error'], 'already loaded'));
$c = DbConnect::connect($d);
check('the first project still connects', isset($c['pdo']) && 'sqlite' === $c['engine']);
$d3 = realpath(tmpdir());
check('no config.php is a clean error', isset(DbConnect::connect($d3)['error']));

echo "OAuthBearer::isPortalService()\n";
check('matching client id is Portal', OAuthBearer::isPortalService(['service_client_id' => 'abc123'], 'abc123'));
check('a different client id is not', !OAuthBearer::isPortalService(['service_client_id' => 'abc123'], 'claude-client'));
check('an entry with no service_client_id never matches', !OAuthBearer::isPortalService(['issuer' => 'x'], 'abc123'));
check('an empty service_client_id never matches an empty aud', !OAuthBearer::isPortalService(['service_client_id' => ''], ''));
check('no entry at all never matches', !OAuthBearer::isPortalService([], 'abc123'));
check('a non-string value never matches', !OAuthBearer::isPortalService(['service_client_id' => 7], '7'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
