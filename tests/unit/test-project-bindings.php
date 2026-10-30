<?php
// Offline proof of the `binding` section of arch_project_info (ProjectBindings)
// against an in-memory SQLite stand-in for Portal's tables: only the calling
// person's own registered IDs are listed, other people's are never shown, each
// state comes out right, and an unreadable registry is "unavailable", never
// "not bootstrapped".
//
//     php tests/unit/test-project-bindings.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectBindings.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectBindings.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectBindings.php';

use ArchMcp\BindingGuard;
use ArchMcp\BindingMode;
use ArchMcp\ProjectBindings;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$one = '123e4567-e89b-12d3-a456-426614174000';
$two = '223e4567-e89b-12d3-a456-426614174000';
$three = '323e4567-e89b-12d3-a456-426614174000';
$U = BindingGuard::CALLER_USER;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT, bootstrap_grandfathered INTEGER NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE project_claude_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, claude_project_id TEXT, name_label TEXT, registered_by INTEGER NOT NULL, registered_at TEXT)');
$pdo->exec("INSERT INTO projects (id, slug, bootstrap_grandfathered) VALUES (1, 'old-one', 1), (2, 'new-one', 0), (3, 'shared', 0), (4, 'twice', 0), (5, 'messy', 0)");
$pdo->exec("INSERT INTO project_claude_bindings (project_id, claude_project_id, registered_by) VALUES (3, '$one', 7), (3, '$two', 8), (4, '$one', 7), (4, '$three', 7), (5, 'garbage', 7), (5, '".strtoupper($two)."', 7)");

echo "fetchWith()\n";
$r = ProjectBindings::fetchWith($pdo, 'shared');
check('both people\'s rows come back with who registered each', [['id' => $one, 'user' => 7], ['id' => $two, 'user' => 8]] === $r['rows'] && false === $r['grandfathered']);
$r = ProjectBindings::fetchWith($pdo, 'old-one');
check('grandfathered project with no rows', [] === $r['rows'] && true === $r['grandfathered']);
$r = ProjectBindings::fetchWith($pdo, 'messy');
check('stored values that are not GUIDs are dropped, upper case is folded', [['id' => $two, 'user' => 7]] === $r['rows']);
check('unknown slug: null, not an empty list', null === ProjectBindings::fetchWith($pdo, 'nobody'));

echo "\ndescribe(): the caller's own IDs\n";
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', ProjectBindings::fetchWith($pdo, 'shared'));
check('registered: only my ID', ProjectBindings::REGISTERED === $d['state'] && [$one] === $d['claude_project_ids']);
check('the note says to pass it and not to ask', str_contains($d['note'], 'claudeProjectId') && str_contains($d['note'], 'Do not ask the user'));
check('the other person\'s ID is nowhere in the reply', !str_contains(json_encode($d), $two));
check('one registered ID: the note says to tell the person if this chat\'s own ID is not in the list, and not to borrow another', str_contains($d['note'], 'tell the person before going on') && str_contains($d['note'], 'do not borrow another project') && str_contains($d['note'], 'Do not ask the user'));
check('one registered ID: no ask-for-the-URL line (the ID is already known)', !str_contains($d['note'], 'paste'));
$d = ProjectBindings::describe(BindingMode::LOG, $U, '8', ProjectBindings::fetchWith($pdo, 'shared'));
check('the other person sees their own ID, not mine', [$two] === $d['claude_project_ids'] && !str_contains(json_encode($d), $one));
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', ProjectBindings::fetchWith($pdo, 'twice'));
check('two of my own: the borrow warning is not added (the note already says to pick this chat\'s own)', !str_contains($d['note'], 'borrow') && !str_contains($d['note'], 'paste'));
check('two of my own: both listed in order, note says to pick this chat\'s own', [$one, $three] === $d['claude_project_ids'] && str_contains($d['note'], 'own Claude project'));

echo "\ndescribe(): the other states\n";
$d = ProjectBindings::describe(BindingMode::LOG, $U, '9', ProjectBindings::fetchWith($pdo, 'shared'));
check('registered by others only: no IDs, install_bootstrap named', ProjectBindings::REGISTERED_BY_OTHERS === $d['state'] && [] === $d['claude_project_ids'] && str_contains($d['note'], 'install_bootstrap'));
check('and no other person\'s ID leaks', !str_contains(json_encode($d), $one) && !str_contains(json_encode($d), $two));
check('registered by others only: asks for the project URL if the ID is unknown, and not to guess', str_contains($d['note'], 'ask the person to paste') && str_contains($d['note'], 'https://claude.ai/project/<guid>') && str_contains($d['note'], 'do not guess'));
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', ProjectBindings::fetchWith($pdo, 'old-one'));
check('grandfathered: calls work without an ID, install_bootstrap offered', ProjectBindings::GRANDFATHERED === $d['state'] && str_contains($d['note'], 'install_bootstrap'));
check('grandfathered: asks for the project URL if the ID is unknown, and not to guess', str_contains($d['note'], 'ask the person to paste') && str_contains($d['note'], 'do not guess'));
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', ProjectBindings::fetchWith($pdo, 'new-one'));
check('not bootstrapped: run install_bootstrap first', ProjectBindings::NOT_BOOTSTRAPPED === $d['state'] && [] === $d['claude_project_ids']);
check('not bootstrapped: asks for the project URL if the ID is unknown, and not to guess', str_contains($d['note'], 'install_bootstrap') && str_contains($d['note'], 'ask the person to paste') && str_contains($d['note'], 'do not guess'));
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', null);
check('registry unreadable: unavailable, never not_bootstrapped', ProjectBindings::UNAVAILABLE === $d['state'] && [] === $d['claude_project_ids']);
check('unavailable: the new lines are not added', !str_contains($d['note'], 'paste') && !str_contains($d['note'], 'borrow'));
$d = ProjectBindings::describe(BindingMode::LOG, $U, 'abc', ProjectBindings::fetchWith($pdo, 'shared'));
check('a user id that is not a number owns nothing', [] === $d['claude_project_ids'] && ProjectBindings::REGISTERED_BY_OTHERS === $d['state']);
$d = ProjectBindings::describe(BindingMode::LOG, $U, '0', ProjectBindings::fetchWith($pdo, 'shared'));
check('user id 0 owns nothing', [] === $d['claude_project_ids']);

echo "\ndescribe(): exempt callers and mode\n";
foreach ([BindingGuard::CALLER_PORTAL_SERVICE, BindingGuard::CALLER_BREAKGLASS] as $caller) {
    $d = ProjectBindings::describe(BindingMode::LOG, $caller, '7', ProjectBindings::fetchWith($pdo, 'shared'));
    check("$caller: not_applicable, no IDs", ProjectBindings::NOT_APPLICABLE === $d['state'] && [] === $d['claude_project_ids'] && !str_contains(json_encode($d), $one));
}
$d = ProjectBindings::describe(BindingMode::ENFORCE, $U, '7', ProjectBindings::fetchWith($pdo, 'shared'));
check('enforce mode is reported', 'enforce' === $d['mode'] && true === $d['enforced']);
$d = ProjectBindings::describe(BindingMode::LOG, $U, '7', ProjectBindings::fetchWith($pdo, 'shared'));
check('log mode is reported', 'log' === $d['mode'] && false === $d['enforced']);

echo "\nunreadable registry\n";
$pdo->exec('DROP TABLE project_claude_bindings');
check('bindings table missing (or grant missing): null', null === ProjectBindings::fetchWith($pdo, 'shared'));
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('projects table missing: null', null === ProjectBindings::fetchWith($pdo2, 'shared'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
