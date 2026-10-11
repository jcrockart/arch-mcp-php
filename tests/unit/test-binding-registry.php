<?php
// Offline proof of the registry read (BindingRegistry::fetchWith) against an
// in-memory SQLite stand-in for Portal's tables: registered IDs, the
// grandfather flag, and that anything untrustworthy comes back as null
// ("registry unavailable"), never as "nothing registered".
//
//     php tests/unit/test-binding-registry.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BindingRegistry.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BindingRegistry.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BindingRegistry.php';

use ArchMcp\BindingRegistry;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$one = '123e4567-e89b-12d3-a456-426614174000';
$two = '223e4567-e89b-12d3-a456-426614174000';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT, bootstrap_grandfathered INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE project_claude_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, claude_project_id TEXT)');
$pdo->exec("INSERT INTO projects (id, slug, bootstrap_grandfathered) VALUES (1, 'old-one', 1), (2, 'new-one', 0), (3, 'bound', 0), (4, 'messy', 1)");
$pdo->exec("INSERT INTO project_claude_bindings (project_id, claude_project_id) VALUES (3, '$one'), (3, '$two'), (4, '$one-not'), (4, 'garbage'), (4, '".strtoupper($two)."')");

echo "fetchWith()\n";
$r = BindingRegistry::fetchWith($pdo, 'old-one');
check('grandfathered project with no bindings', [] === $r['registered'] && true === $r['grandfathered']);
$r = BindingRegistry::fetchWith($pdo, 'new-one');
check('new project with no bindings is not grandfathered', [] === $r['registered'] && false === $r['grandfathered']);
$r = BindingRegistry::fetchWith($pdo, 'bound');
check('several IDs for one slug', [$one, $two] === $r['registered'] && false === $r['grandfathered']);
$r = BindingRegistry::fetchWith($pdo, 'messy');
check('stored values that are not GUIDs are dropped, upper case is folded', [$two] === $r['registered'] && true === $r['grandfathered']);
check('unknown slug: null, not an empty list', null === BindingRegistry::fetchWith($pdo, 'nobody'));
check('another project\'s bindings are never mixed in', [] === BindingRegistry::fetchWith($pdo, 'new-one')['registered']);

echo "\nunreadable registry\n";
$pdo->exec('DROP TABLE project_claude_bindings');
check('bindings table missing (or grant missing): null', null === BindingRegistry::fetchWith($pdo, 'bound'));
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('projects table missing: null', null === BindingRegistry::fetchWith($pdo2, 'bound'));
$pdo3 = new PDO('sqlite::memory:');
$pdo3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo3->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT)');
$pdo3->exec("INSERT INTO projects (id, slug) VALUES (1, 'x')");
check('grandfather column missing: null', null === BindingRegistry::fetchWith($pdo3, 'x'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
