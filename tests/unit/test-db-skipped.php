<?php
// Offline proof that migration files which are ignored are reported instead of
// silently doing nothing: DbMigrations::listSkipped() and the `skipped` list
// (and the changed "nothing pending" note) in db_pending and db_migrate
// (DbTools). A throwaway SQLite project; no network, no secrets.
//
//     php tests/unit/test-db-skipped.php
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
function mig(string $id, string $type, string $sql): string {
    return "-- id: $id\n-- type: $type\n-- description: test $id\n\n$sql\n";
}

$d = realpath(sys_get_temp_dir());
$d .= '/dbskip'.getmypid();
mkdir($d, 0777, true);
$d = realpath($d);
$dir = $d.'/db/migrations';
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
$boot = new PDO('sqlite:'.$d.'/app.sqlite');
$boot->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)');
$boot = null;
$profile = ['root' => $d, 'lanes' => ['core' => '']];
function clearDir(string $dir): void {
    foreach (glob($dir.'/*') ?: [] as $f) {
        unlink($f);
    }
}

echo "listSkipped(): nothing to report\n";
check('no migrations folder: empty list', [] === DbMigrations::listSkipped($d.'/nope'));
mkdir($dir, 0777, true);
check('an empty folder: empty list', [] === DbMigrations::listSkipped($dir));
file_put_contents($dir.'/0001_ok.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
file_put_contents($dir.'/README.md', "# notes\n");
file_put_contents($dir.'/notes.txt', "notes\n");
check('valid files and non-sql files are not reported', [] === DbMigrations::listSkipped($dir));

echo "\nlistSkipped(): ignored files, each with its reason\n";
file_put_contents($dir.'/0002_plain.sql', "CREATE TABLE p (id INTEGER);\n");
file_put_contents($dir.'/0003_noid.sql', "-- type: schema-additive\n\nCREATE TABLE q (id INTEGER);\n");
file_put_contents($dir.'/0004_notype.sql', "-- id: x\n\nCREATE TABLE r (id INTEGER);\n");
file_put_contents($dir.'/0005_late.sql', "CREATE TABLE s (id INTEGER);\n-- id: late\n-- type: schema-additive\n");
file_put_contents($dir.'/0006_UPPER.SQL', mig('u', 'schema-additive', 'CREATE TABLE u (id INTEGER);'));
file_put_contents($dir.'/0008_crlf.sql', str_replace("\n", "\r\n", mig('crlf', 'schema-additive', 'CREATE TABLE w (id INTEGER);')));
$s = DbMigrations::listSkipped($dir);
$byFile = array_column($s, 'problem', 'file');
check('exactly the five ignored files, in name order', ['0002_plain.sql', '0003_noid.sql', '0004_notype.sql', '0005_late.sql', '0006_UPPER.SQL'] === array_column($s, 'file'));
check('no header at all: both lines named', str_contains($byFile['0002_plain.sql'], '-- id:') && str_contains($byFile['0002_plain.sql'], '-- type:'));
check('only the id missing: only the id named', str_contains($byFile['0003_noid.sql'], '-- id:') && !str_contains($byFile['0003_noid.sql'], '-- type:'));
check('only the type missing: only the type named', str_contains($byFile['0004_notype.sql'], '-- type:') && !str_contains($byFile['0004_notype.sql'], '-- id:'));
check('header lines placed after the SQL do not count', str_contains($byFile['0005_late.sql'], '-- id:') && str_contains($byFile['0005_late.sql'], '-- type:'));
check('upper-case extension: said so', str_contains($byFile['0006_UPPER.SQL'], "lower case '.sql'"));
check('a valid file with Windows line endings is not reported', !isset($byFile['0008_crlf.sql']));
check('every problem says the file is ignored', 5 === \count(array_filter($byFile, static fn (string $p): bool => str_contains($p, 'ignored'))));

echo "\ndb_pending: skipped files are listed\n";
$r = DbTools::pending($profile, 'staging');
check('the valid migrations are still listed', true === $r['success'] && 2 === $r['declared'] && ['a', 'crlf'] === array_column($r['pending'], 'id'));
check('the ignored files are listed with their reasons', 5 === \count($r['skipped']) && '0002_plain.sql' === $r['skipped'][0]['file'] && isset($r['skipped'][0]['problem']));
check('no "nothing pending" note while something is pending', !isset($r['note']));

echo "\nonly ignored files left: not a silent \"nothing pending\"\n";
unlink($dir.'/0001_ok.sql');
unlink($dir.'/0008_crlf.sql');
$r = DbTools::pending($profile, 'staging');
check('pending: zero, but the note says files were ignored', true === $r['success'] && 0 === $r['pending_count'] && str_contains($r['note'], 'nothing pending, but 5 file(s)') && 5 === \count($r['skipped']));
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('migrate: success, nothing applied, the note and the list say why', true === $r['success'] && [] === $r['applied'] && str_contains($r['note'], 'were ignored') && 5 === \count($r['skipped']));

echo "\ndb_migrate: the list rides along on every outcome\n";
file_put_contents($dir.'/0009_b.sql', mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'));
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('applied: success and the list is still there', true === $r['success'] && ['b'] === $r['applied'] && 5 === \count($r['skipped']));
file_put_contents($dir.'/0010_c.sql', mig('c', 'schema-destructive', 'DROP TABLE b;'));
$r = DbTools::migrate($profile, 'production', '', '', false);
check('refused as destructive: refusal and the list', false === $r['success'] && isset($r['destructive']) && 5 === \count($r['skipped']));
check('and nothing was applied by the refusal', [] === (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT id FROM schema_migrations WHERE id = 'c'")->fetchAll());

echo "\nall files valid: the replies are exactly as before\n";
unlink($dir.'/0010_c.sql');
foreach (['0002_plain.sql', '0003_noid.sql', '0004_notype.sql', '0005_late.sql', '0006_UPPER.SQL'] as $f) {
    unlink($dir.'/'.$f);
}
$r = DbTools::pending($profile, 'staging');
check('pending: no skipped key, plain note', true === $r['success'] && 0 === $r['pending_count'] && 'nothing pending' === $r['note'] && !isset($r['skipped']));
$r = DbTools::migrate($profile, 'staging', '', '', true);
check('migrate: no skipped key, plain note', true === $r['success'] && 'nothing pending' === $r['note'] && !isset($r['skipped']));
clearDir($dir);
$r = DbTools::pending($profile, 'staging');
check('an empty migrations folder is unchanged too', 0 === $r['declared'] && !isset($r['skipped']) && 'nothing pending' === $r['note']);

exec('rm -rf '.escapeshellarg($d));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
