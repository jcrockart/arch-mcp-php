<?php
// Offline proof of the member-project list behind arch_ref general search
// (MemberProjects, ARCH-80), using an in-memory SQLite database that mimics
// the two Portal tables it reads. Skips cleanly if pdo_sqlite is missing.
//
//     php tests/unit/test-member-projects.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/MemberProjects.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/MemberProjects.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require_once $ROOT.'/src/MemberProjects.php';

use ArchMcp\MemberProjects;

if (!\extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available\n";
    exit(0);
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT)');
$pdo->exec('CREATE TABLE project_members (project_id INTEGER, user_id INTEGER, role TEXT)');
$pdo->exec("INSERT INTO projects (id, slug) VALUES (1, 'gamma'), (2, 'alpha'), (3, 'beta'), (4, 'bad slug!')");
$pdo->exec("INSERT INTO project_members (project_id, user_id, role) VALUES (1, 7, 'owner'), (2, 7, 'member'), (4, 7, 'member'), (3, 8, 'member')");

echo "MemberProjects::slugs()\n";
check('a member sees exactly their projects, sorted', ['alpha', 'gamma'] === MemberProjects::slugs($pdo, '7'));
check('a slug with an invalid shape is dropped', !\in_array('bad slug!', MemberProjects::slugs($pdo, '7'), true));
check('another user sees only theirs', ['beta'] === MemberProjects::slugs($pdo, '8'));
check('a user with no memberships gets an empty list', [] === MemberProjects::slugs($pdo, '9'));
check('a non-numeric id gets an empty list', [] === MemberProjects::slugs($pdo, 'x') && [] === MemberProjects::slugs($pdo, '7; DROP TABLE projects') && [] === MemberProjects::slugs($pdo, ''));
$empty = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check('a failing query gives an empty list, not an error', [] === MemberProjects::slugs($empty, '7'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
