<?php
// Offline proof of IncomingMigrations (the migrations a pull would bring in) against
// throwaway local git repos and a throwaway SQLite database. No network, no secrets,
// no live checkout touched.
//
//     php tests/unit/test-incoming-migrations.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/IncomingMigrations.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/IncomingMigrations.php')) {
    fwrite(STDERR, 'Cannot locate app root from '.__DIR__."\n");
    exit(2);
}
foreach (['DbSql', 'DbMigrations', 'DbRead', 'DbConnect', 'DbFreshCheck', 'DbGuard', 'ConfirmIds', 'DbTools', 'IncomingMigrations'] as $c) {
    require_once $ROOT.'/src/'.$c.'.php';
}

use ArchMcp\DbTools;
use ArchMcp\IncomingMigrations;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function sh(string $cwd, string $cmd): string {
    $out = [];
    exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $out, $code);
    return implode("\n", $out);
}
function mig(string $id, string $type, string $sql): string {
    return "-- id: $id\n-- type: $type\n-- description: test $id\n\n$sql\n";
}
function put(string $repo, string $file, string $text): void {
    @mkdir(\dirname("$repo/$file"), 0775, true);
    file_put_contents("$repo/$file", $text);
}
function commit(string $repo, string $msg): void {
    sh($repo, 'git add -A && git -c user.name=t -c user.email=t@t commit -q -m '.escapeshellarg($msg));
}

echo "fromContents(): the pure part\n";
$files = [
    '0001_a.sql' => mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'),
    '0002_b.sql' => mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'),
    '0003_c.sql' => mig('c', 'schema-destructive', 'DROP TABLE a;'),
    '0004_d.sql' => mig('d', 'schema-additive', 'DROP TABLE b;'),
    '0005_nohdr.sql' => "CREATE TABLE z (id INTEGER);\n",
    '0006_dup.sql' => mig('b', 'schema-additive', 'CREATE TABLE b2 (id INTEGER);'),
];
$r = IncomingMigrations::fromContents($files, ['a'], []);
check('an applied id is left out', !in_array('a', array_column($r['migrations'], 'id'), true));
check('the rest are listed in file order', ['b', 'c', 'd'] === array_column($r['migrations'], 'id'));
check('flags: additive no, declared destructive yes, hidden DROP yes', [false, true, true] === array_column($r['migrations'], 'destructive'));
check('reasons come from DbMigrations::destructiveReason', str_contains($r['migrations'][1]['reason'], 'schema-destructive') && str_contains($r['migrations'][2]['reason'], 'drops') && !isset($r['migrations'][0]['reason']));
check('a file with no header is reported, not skipped silently', 1 === count(array_filter($r['problems'], fn ($p) => str_contains($p, '0005_nohdr.sql'))));
check('a duplicate id is reported', 1 === count(array_filter($r['problems'], fn ($p) => str_contains($p, "duplicate migration id 'b'"))));
check('no sql in the listing', !isset($r['migrations'][1]['sql']));
$r = IncomingMigrations::fromContents($files, [], ['a', 'b', 'c', 'd']);
check('ids already in the checkout are left out (they are ordinary pending)', [] === $r['migrations']);

echo "\nforPull(): against real git repos\n";
$base = sys_get_temp_dir().'/archinc'.getmypid();
mkdir($base, 0775, true);
sh($base, 'git init -q --bare origin.git');
sh($base, 'git clone -q origin.git seed');
sh("$base/seed", 'git checkout -q -b main');
put("$base/seed", 'README.md', "x\n");
put("$base/seed", 'db/migrations/0001_a.sql', mig('a', 'schema-additive', 'CREATE TABLE a (id INTEGER);'));
commit("$base/seed", 'first');
sh("$base/seed", 'git push -q origin main');
sh($base, 'git --git-dir=origin.git symbolic-ref HEAD refs/heads/main');
sh($base, 'git clone -q origin.git prod');
sh("$base/prod", 'git checkout -q main');

$r = IncomingMigrations::forPull("$base/prod", '', 'main', [], ['a']);
check('nothing new on origin: available, empty, fetch ok', true === $r['available'] && true === $r['fetch_ok'] && [] === $r['migrations'] && 0 === $r['count']);

put("$base/seed", 'db/migrations/0002_b.sql', mig('b', 'schema-additive', 'CREATE TABLE b (id INTEGER);'));
put("$base/seed", 'db/migrations/0003_c.sql', mig('c', 'schema-destructive', 'DROP TABLE a;'));
put("$base/seed", 'db/migrations/notes.txt', "not a migration\n");
commit("$base/seed", 'two more');
sh("$base/seed", 'git push -q origin main');
$headBefore = trim(sh("$base/prod", 'git rev-parse HEAD'));
$r = IncomingMigrations::forPull("$base/prod", '', 'main', [], ['a']);
check('it fetches by itself and finds the two new ones', true === $r['available'] && ['b', 'c'] === array_column($r['migrations'], 'id'));
check('with their destructive flag', [false, true] === array_column($r['migrations'], 'destructive'));
check('.txt files are not migrations', 2 === $r['count'] && [] === $r['problems']);
check('the working tree and HEAD are untouched', $headBefore === trim(sh("$base/prod", 'git rev-parse HEAD')) && !is_file("$base/prod/db/migrations/0002_b.sql"));
check('applied ids are left out', ['c'] === array_column(IncomingMigrations::forPull("$base/prod", '', 'main', ['b'], ['a'])['migrations'], 'id'));

$r = IncomingMigrations::forPull("$base/prod", '', 'nosuch', [], ['a']);
check('an unknown branch is reported, never read as nothing incoming', false === $r['available'] && str_contains($r['error'], 'origin/nosuch'));
$r = IncomingMigrations::forPull("$base/prod", '', '--output=/tmp/x', [], []);
check('a hostile branch name is refused', false === $r['available'] && str_contains($r['error'], 'invalid pull_branch'));
$r = IncomingMigrations::forPull("$base/prod", '../x', 'main', [], []);
check('a lane folder that climbs out is refused', false === $r['available'] && str_contains($r['error'], 'invalid core lane'));
$r = IncomingMigrations::forPull("$base/does-not-exist", '', 'main', [], []);
check('a missing checkout is reported', false === $r['available']);

sh("$base/prod", 'git remote set-url origin '.escapeshellarg("$base/gone.git"));
$r = IncomingMigrations::forPull("$base/prod", '', 'main', [], ['a']);
check('a failed fetch is flagged but the last fetched origin is still listed', true === $r['available'] && false === $r['fetch_ok'] && '' !== $r['fetch_error'] && ['b', 'c'] === array_column($r['migrations'], 'id'));
sh("$base/prod", 'git remote set-url origin '.escapeshellarg("$base/origin.git"));

echo "\nmigrations in a lane sub-folder\n";
put("$base/seed", 'app/db/migrations/0001_s.sql', mig('s', 'schema-additive', 'CREATE TABLE s (id INTEGER);'));
commit("$base/seed", 'sub lane');
sh("$base/seed", 'git push -q origin main');
$r = IncomingMigrations::forPull("$base/prod", 'app', 'main', [], []);
check('reads <lane>/db/migrations only', ['s'] === array_column($r['migrations'], 'id'));

echo "\nDbTools::pending(): opt-in, and the default is unchanged\n";
$d = $base.'/proj';
sh($base, 'git clone -q origin.git proj');
sh($d, 'git checkout -q main');
sh($d, 'git reset -q --hard HEAD~2');
file_put_contents($d.'/config.php', "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_PATH', ".var_export($d.'/app.sqlite', true).");\n");
$pdo = new PDO('sqlite:'.$d.'/app.sqlite');
$pdo->exec('CREATE TABLE schema_migrations (id TEXT PRIMARY KEY, type TEXT, data_class TEXT, description TEXT, applied_at TEXT, applied_by TEXT)');
$pdo->exec('CREATE TABLE keep (id INTEGER)');
$pdo = null;
$profile = ['root' => $d, 'lanes' => ['core' => ''], 'pull_branch' => 'main'];
$plain = DbTools::pending($profile, 'production');
check('without the flag there is no incoming key', !array_key_exists('incoming', $plain) && ['a'] === array_column($plain['pending'], 'id'));
$with = DbTools::pending($profile, 'production', true);
check('with the flag pending is the same as before', $with['pending'] === $plain['pending']);
check('and incoming lists what origin has beyond the checkout', ['b', 'c'] === array_column($with['incoming']['migrations'], 'id'));
check('a profile with no pull_branch says so', false === DbTools::pending(['root' => $d, 'lanes' => ['core' => '']], 'production', true)['incoming']['available']);

sh($base, 'rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
