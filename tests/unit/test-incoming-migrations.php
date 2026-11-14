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

// ---------------------------------------------------------------------------------------
// atCommit (ARCH job: chat promote-to-production, step 1). The files are read from ONE
// named commit with git show, so the judge, the apply and the pull can all name the same
// commit and the origin tip moving in between cannot change what is judged or applied.
// An empty atCommit leaves everything above exactly as it was.
// ---------------------------------------------------------------------------------------
// config.php and the sqlite file live in this throwaway checkout; keep them out of `git status`
// the way a real production checkout does, so a clean tree reads as clean.
file_put_contents($d.'/.git/info/exclude', "config.php\napp.sqlite*\n");
$c1 = trim(sh($d, 'git rev-parse HEAD'));
$c2 = trim(sh($d, 'git rev-parse origin/main~1'));
$c3 = trim(sh($d, 'git rev-parse origin/main'));
$tablesNow = static function () use ($d): array {
    $p = new PDO('sqlite:'.$d.'/app.sqlite');
    return $p->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('a','b','c','e','z') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
};

echo "\nIncomingMigrations::filesAtCommit(): the files at one commit, with their sql\n";
$r = IncomingMigrations::filesAtCommit($d, '', $c2);
check('every migration in that commit, in file order, sql kept', true === $r['available'] && ['a', 'b', 'c'] === array_column($r['migrations'], 'id') && 'CREATE TABLE b (id INTEGER);' === $r['migrations'][1]['sql'] && [] === $r['problems']);
check('the file name is kept', '0002_b.sql' === $r['migrations'][1]['file']);
$r = IncomingMigrations::filesAtCommit($d, '', $c1);
check('an earlier commit lists only what it had', ['a'] === array_column($r['migrations'], 'id'));
$r = IncomingMigrations::filesAtCommit($d, 'app', $c3);
check('reads <lane>/db/migrations only', ['s'] === array_column($r['migrations'], 'id'));
$r = IncomingMigrations::filesAtCommit($d, '', 'abc');
check('a short id is refused', false === $r['available'] && str_contains($r['error'], '40'));
$r = IncomingMigrations::filesAtCommit($d, '../x', $c2);
check('a lane folder that climbs out is refused', false === $r['available']);
$r = IncomingMigrations::filesAtCommit($d, '', str_repeat('d', 40));
check('an unknown commit is reported, never read as empty', false === $r['available'] && '' !== $r['error']);

echo "\nforPull(): atCommit closes the origin-moves race\n";
put("$base/seed", 'db/migrations/0004_e.sql', mig('e', 'schema-additive', 'CREATE TABLE e (id INTEGER);'));
commit("$base/seed", 'origin moves on');
sh("$base/seed", 'git push -q origin main');
$live = IncomingMigrations::forPull($d, '', 'main', [], ['a']);
check('without a pin the live tip is read, so it now includes the new one', ['b', 'c', 'e'] === array_column($live['migrations'], 'id'));
$pinned = IncomingMigrations::forPull($d, '', 'main', [], ['a'], $c2);
check('with a pin only that commit is read: the new one is not there', true === $pinned['available'] && ['b', 'c'] === array_column($pinned['migrations'], 'id'));
check('the pinned answer names its ref', $c2 === $pinned['ref']);
$bad = IncomingMigrations::forPull($d, '', 'main', [], ['a'], 'abc');
check('a bad pin is refused', false === $bad['available'] && str_contains($bad['error'], '40'));

echo "\nDbTools::pending(): atCommit judges that commit, not the checkout\n";
$r = DbTools::pending($profile, 'production', false, $c2);
check('success, source commit, names the commit', true === $r['success'] && 'commit' === $r['source'] && $c2 === $r['at_commit'] && 'production' === $r['environment']);
check('pending is what that commit declares and the database lacks', ['a', 'b', 'c'] === array_column($r['pending'], 'id') && 3 === $r['pending_count'] && 3 === $r['declared']);
check('flags and reasons come from the same function as the apply step', [false, false, true] === array_column($r['pending'], 'destructive') && str_contains($r['pending'][2]['reason'], 'schema-destructive'));
check('safe_up_to is the last id before the first destructive one', 'b' === $r['safe_up_to']);
check('no sql in the listing, and no problems', !isset($r['pending'][2]['sql']) && [] === $r['problems']);
$r = DbTools::pending($profile, 'production', false, $c1);
check('an earlier commit lists only what it had', ['a'] === array_column($r['pending'], 'id') && 'a' === $r['safe_up_to']);
$plain = DbTools::pending($profile, 'production');
check('without atCommit nothing changed: the checkout, and no pin keys', ['a'] === array_column($plain['pending'], 'id') && !isset($plain['at_commit']) && !isset($plain['source']) && !isset($plain['safe_up_to']));
$r = DbTools::pending($profile, 'production', false, 'abc');
check('a short id is refused, with a reason', false === $r['success'] && 'invalid_commit' === $r['reason']);
$r = DbTools::pending($profile, 'production', false, str_repeat('e', 40));
check('an unknown commit is refused, with a reason', false === $r['success'] && 'commit_not_on_origin' === $r['reason']);
$r = DbTools::pending(['root' => $d, 'lanes' => ['core' => '']], 'production', false, $c2);
check('a profile with no pull_branch says so', false === $r['success'] && str_contains($r['error'], 'pull_branch'));
put("$base/seed", 'db/migrations/0005_nohdr.sql', "CREATE TABLE z (id INTEGER);\n");
commit("$base/seed", 'a file with no header');
sh("$base/seed", 'git push -q origin main');
$c5 = trim(sh("$base/seed", 'git rev-parse HEAD'));
$r = DbTools::pending($profile, 'production', false, $c5);
check('a file with no header shows under problems', true === $r['success'] && 1 === count(array_filter($r['problems'], fn ($p) => str_contains($p, '0005_nohdr.sql'))));

echo "\nDbTools::migrate(): atCommit applies from the commit, before any pull\n";
check('start: nothing applied', [] === $tablesNow());
file_put_contents($d.'/stray.txt', "x\n");
$r = DbTools::migrate($profile, 'production', 'b', '', true, $c2);
check('a dirty production tree is refused before any schema changes', false === $r['success'] && 'tree_dirty' === $r['reason'] && [] === $tablesNow());
unlink($d.'/stray.txt');
$r = DbTools::migrate($profile, 'production', 'b', 'c', false, $c2);
check('confirmDestructive is refused with a pin, for a chat caller', false === $r['success'] && 'confirm_not_allowed' === $r['reason'] && [] === $tablesNow());
$r = DbTools::migrate($profile, 'production', 'b', 'c', true, $c2);
check('and for Portal too', false === $r['success'] && 'confirm_not_allowed' === $r['reason'] && [] === $tablesNow());
$r = DbTools::migrate($profile, 'production', '', '', true, $c2);
check('a destructive migration in the selection stops everything', false === $r['success'] && 'destructive_in_selection' === $r['reason'] && 'c' === $r['destructive'][0]['id'] && 'b' === $r['safe_up_to'] && [] === $tablesNow());
$r = DbTools::migrate($profile, 'production', '', '', true, $c5);
check('problems in the commit refuse the whole apply', false === $r['success'] && 'migration_problems' === $r['reason'] && 1 === count(array_filter($r['problems'], fn ($p) => str_contains($p, '0005_nohdr.sql'))) && [] === $tablesNow());
$r = DbTools::migrate($profile, 'production', 'zzz', '', true, $c2);
check('an upTo that is not pending in that commit is refused', false === $r['success'] && 'upto_not_pending' === $r['reason'] && [] === $tablesNow());
$r = DbTools::migrate($profile, 'production', '', '', true, 'abc');
check('a short id is refused', false === $r['success'] && 'invalid_commit' === $r['reason']);
$r = DbTools::migrate($profile, 'staging', '', '', true, $c2);
check('a pin is for production only', false === $r['success'] && 'invalid_use' === $r['reason']);

$headBefore = trim(sh($d, 'git rev-parse HEAD'));
$r = DbTools::migrate($profile, 'production', 'b', '', true, $c2);
check('upTo b applies a and b from the commit', true === $r['success'] && ['a', 'b'] === $r['applied'] && 'commit' === $r['source'] && $c2 === $r['at_commit'] && 'production' === $r['environment']);
check('both tables exist, and b was never in the checkout', ['a', 'b'] === $tablesNow() && !is_file($d.'/db/migrations/0002_b.sql'));
check('pending_after counts what is left in that commit', 1 === $r['pending_after']);
check('the checkout was not touched', $headBefore === trim(sh($d, 'git rev-parse HEAD')));
$by = (new PDO('sqlite:'.$d.'/app.sqlite'))->query("SELECT applied_by FROM schema_migrations WHERE id='b'")->fetchColumn();
check('recorded as a production apply', 'agent:db_migrate_prod' === $by);
$r = DbTools::migrate($profile, 'production', 'b', '', true, $c2);
check('a second time b is no longer pending: refused', false === $r['success'] && 'upto_not_pending' === $r['reason']);
$r = DbTools::migrate($profile, 'production', '', '', true, $c2);
check('what is left is destructive, so still refused, nothing more applied', false === $r['success'] && 'destructive_in_selection' === $r['reason'] && ['a', 'b'] === $tablesNow());

sh($d, 'git merge -q --ff-only '.$c2);
$after = DbTools::pending($profile, 'production');
check('after the pull the checkout sees a and b as applied: only c is pending', ['c'] === array_column($after['pending'], 'id'));

sh($d, 'git -c user.name=t -c user.email=t@t commit --allow-empty -q -m local');
$r = DbTools::migrate($profile, 'production', '', '', true, $c5);
check('production ahead of the pin is not a fast-forward: refused', false === $r['success'] && 'not_fast_forward' === $r['reason']);
sh($d, 'git reset -q --hard '.$c2);

sh($base, 'rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
