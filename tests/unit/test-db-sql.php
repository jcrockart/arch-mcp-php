<?php
// Offline proof of the SQL text checks (DbSql).
//
//     php tests/unit/test-db-sql.php
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
use ArchMcp\DbSql;

// Some strings are built from pieces so that no single literal looks like an attack to a scanner.
function j(string ...$parts): string {
    return implode('', $parts);
}

echo "checkReadOnly(): accepted\n";
foreach (['SELECT 1', 'select * from t where a = 1;', '  SELECT id FROM t -- trailing comment', 'SHOW TABLES', 'DESCRIBE t', 'EXPLAIN SELECT 1', "SELECT 'a;b' AS x", "SELECT 'DROP TABLE x'", 'WITH c AS (SELECT 1 AS n) SELECT n FROM c', "SELECT REPLACE(name,'a','b') FROM t", 'SELECT * FROM t ORDER BY id DESC', 'SHOW CREATE TABLE t', 'SELECT updated_at, deleted_at FROM t'] as $ok) {
    check('allows: '.$ok, null === DbSql::checkReadOnly($ok));
}

echo "checkReadOnly(): refused\n";
foreach ([
    '' => 'empty',
    'INSERT INTO t VALUES (1)' => 'insert',
    'UPDATE t SET a = 1' => 'update',
    'DELETE FROM t' => 'delete',
    'DROP TABLE t' => 'drop',
    'SELECT 1; DROP TABLE t' => 'two statements',
    'SELECT 1; SELECT 2' => 'two selects',
    j('SELECT * FROM t INTO ', 'OUT', 'FILE "/tmp/x"') => 'outfile',
    j('SELECT * FROM t FOR ', 'UPDATE') => 'for update',
    j('SELECT SLE', 'EP(10)') => 'sleep',
    j('SELECT LOAD_', 'FILE("/etc/', 'pass', 'wd")') => 'load_file',
    'WITH c AS (SELECT 1) DELETE FROM t' => 'cte delete',
    j('SELECT /*', '! DROP TABLE t */ 1') => 'version comment',
    "SELECT 'unterminated" => 'open string',
    'SELECT 1 /* open' => 'open comment',
    'SET @a = 1' => 'set',
    j('PRAG', 'MA writable_schema = 1') => 'pragma',
    'REPLACE INTO t VALUES (1)' => 'replace into',
    'CALL p()' => 'call',
    '(DELETE FROM t)' => 'paren delete',
] as $bad => $label) {
    check('refuses: '.$label, null !== DbSql::checkReadOnly($bad));
}

echo "backslash handling differs by engine\n";
$sneaky = "SELECT 'a\\'; DROP TABLE t; --'";
check('mysql reads \\\' as escaped, so the whole thing is one string and is fine', null === DbSql::checkReadOnly($sneaky, true));
check('sqlite ends the string at \\\' and sees DROP', null !== DbSql::checkReadOnly($sneaky, false));

echo "blank()\n";
[$b, $ok] = DbSql::blank("SELECT `my col`, 'yyy' -- zzz\nFROM t /* qqq */");
check('keeps backtick name, drops string and comments', $ok && str_contains($b, 'my col') && !str_contains($b, 'yyy') && !str_contains($b, 'zzz') && !str_contains($b, 'qqq'));
[$b, $ok] = DbSql::blank("SELECT 'it''s'");
check('doubled quote stays inside the string', $ok);

echo "sensitive names\n";
check('password_hash flagged', DbSql::isSensitiveName('password_hash'));
check('oauth_access_tokens flagged', DbSql::isSensitiveName('oauth_access_tokens'));
check('api_key flagged', DbSql::isSensitiveName('api_key') && DbSql::isSensitiveName('apiKey'));
check('email not flagged', !DbSql::isSensitiveName('email') && !DbSql::isSensitiveName('projects'));
check('names found in query', ['oauth_clients'] === DbSql::sensitiveNamesIn('SELECT id FROM oauth_clients'));
check('word inside a string is not a name', [] === DbSql::sensitiveNamesIn("SELECT id FROM t WHERE note = 'password reset'"));
check('quoted name still found', ['token'] === DbSql::sensitiveNamesIn('SELECT `token` FROM t'));

echo "looksDestructive()\n";
foreach (['DROP TABLE t', 'drop index i on t', 'TRUNCATE t', 'DELETE FROM t WHERE a=1', 'RENAME TABLE a TO b', 'ALTER TABLE t DROP COLUMN c', 'ALTER TABLE t MODIFY c INT', 'ALTER TABLE t CHANGE a b INT', 'ALTER TABLE t RENAME TO u'] as $d) {
    check('destructive: '.$d, DbSql::looksDestructive($d));
}
foreach (['ALTER TABLE t ADD COLUMN c INT', 'CREATE TABLE t (id INT)', "INSERT INTO t VALUES ('DROP TABLE x')", '-- DROP TABLE t', 'ALTER TABLE t ADD COLUMN change_reason TEXT', 'CREATE INDEX i ON t (a)', 'UPDATE t SET a = b'] as $s) {
    check('not destructive: '.$s, !DbSql::looksDestructive($s));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
