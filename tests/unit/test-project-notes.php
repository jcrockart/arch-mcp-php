<?php
// Offline proof of the environment notes in arch_project_info (ProjectNotes):
// the text passing through unchanged, the null and empty cases, the safety
// limits (control characters, 2000-byte cut on a character boundary, invalid
// UTF-8), the fail-soft read against an in-memory SQLite stand-in for Portal's
// projects table (including a Portal that does not have the column yet), and
// the wiring into ProjectInfo::describe(). No network, no git.
//
//     php tests/unit/test-project-notes.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectNotes.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectNotes.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectNotes.php';
require $ROOT.'/src/ProjectInfo.php';

use ArchMcp\ProjectInfo;
use ArchMcp\ProjectNotes;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

// The wording belongs to Portal and may change: here it is just an opaque string.
$default = 'This project is auto-provisioned on shared cPanel hosting. The runtime is PHP and the database is SQLite. '
    .'There is no Node, no shell and no build step available, so write plain PHP, SQL, HTML, CSS and JS that run as they are. '
    .'Add files through the code lane only. Do not assume Composer is available unless the project info says dependency_manager is composer.';

echo "clean(): passes through\n";
$r = ProjectNotes::clean($default);
check('the default text comes back byte for byte', $default === $r['text'] && false === $r['truncated'] && false === $r['unavailable']);
$multi = "Line one.\n\nLine three with a\ttab.\n";
check('newlines and tabs inside the text are kept', "Line one.\n\nLine three with a\ttab." === ProjectNotes::clean($multi)['text']);
check('surrounding whitespace is trimmed', 'hi' === ProjectNotes::clean("  hi \n")['text']);
check('multibyte text is kept as it is', 'Café — naïve ✓' === ProjectNotes::clean('Café — naïve ✓')['text']);

echo "\nclean(): not set\n";
$r = ProjectNotes::clean(null);
check('null: no text, and that is not an error', null === $r['text'] && false === $r['truncated'] && false === $r['unavailable']);
$r = ProjectNotes::clean('');
check('empty string counts as not set', null === $r['text'] && false === $r['unavailable']);
$r = ProjectNotes::clean("  \n\t \r\n ");
check('whitespace only counts as not set', null === $r['text'] && false === $r['unavailable']);
$r = ProjectNotes::clean("\x00\x07\x1B");
check('only control characters counts as not set', null === $r['text'] && false === $r['unavailable']);

echo "\nclean(): safety limits\n";
check('CRLF and bare CR become LF', "a\nb\nc" === ProjectNotes::clean("a\r\nb\rc")['text']);
check('control characters are removed', 'abcde' === ProjectNotes::clean("a\x00b\x07c\x1Bd\x7Fe")['text']);
$r = ProjectNotes::clean(str_repeat('a', 2000));
check('exactly 2000 bytes is not truncated', 2000 === \strlen($r['text']) && false === $r['truncated']);
$r = ProjectNotes::clean(str_repeat('a', 2001));
check('2001 bytes is cut to 2000 and says so', 2000 === \strlen($r['text']) && true === $r['truncated']);
$r = ProjectNotes::clean(str_repeat('é', 1500));
check('a 2-byte character text is cut on a character boundary', true === $r['truncated'] && mb_check_encoding($r['text'], 'UTF-8') && \strlen($r['text']) <= 2000 && 0 === \strlen($r['text']) % 2);
$r = ProjectNotes::clean(str_repeat('€', 1000));
check('a 3-byte character text is cut on a character boundary', true === $r['truncated'] && mb_check_encoding($r['text'], 'UTF-8') && \strlen($r['text']) <= 2000 && 0 === \strlen($r['text']) % 3);
$r = ProjectNotes::clean(str_repeat('a', 1999).' '.str_repeat('b', 10));
check('a cut that lands on a space does not leave it at the end', str_repeat('a', 1999) === $r['text'] && true === $r['truncated']);
$r = ProjectNotes::clean("\xff\xfe broken");
check('invalid UTF-8 is unavailable, not passed on', null === $r['text'] && true === $r['unavailable']);

echo "\nfetchWith(): the read, against a stand-in for Portal's projects table\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT, environment_notes TEXT)');
$ins = $pdo->prepare('INSERT INTO projects (slug, environment_notes) VALUES (:s, :n)');
$ins->execute(['s' => 'withnotes', 'n' => $default]);
$ins->execute(['s' => 'nulled', 'n' => null]);
$ins->execute(['s' => 'blank', 'n' => "  \n "]);
$ins->execute(['s' => 'huge', 'n' => str_repeat('x', 5000)]);
$ins->execute(['s' => 'messy', 'n' => "a\x07b\r\nc"]);
$r = ProjectNotes::fetchWith($pdo, 'withnotes');
check('a stored value comes back unchanged', $default === $r['text'] && false === $r['unavailable']);
$r = ProjectNotes::fetchWith($pdo, 'nulled');
check('NULL in Portal: no text, not flagged', null === $r['text'] && false === $r['unavailable']);
$r = ProjectNotes::fetchWith($pdo, 'blank');
check('blank in Portal: no text, not flagged', null === $r['text'] && false === $r['unavailable']);
$r = ProjectNotes::fetchWith($pdo, 'huge');
check('a very long value is cut and flagged', 2000 === \strlen($r['text']) && true === $r['truncated']);
check('the limits apply to what was read', "ab\nc" === ProjectNotes::fetchWith($pdo, 'messy')['text']);
$r = ProjectNotes::fetchWith($pdo, 'nobody');
check('unknown slug: unavailable, not "not set"', null === $r['text'] && true === $r['unavailable']);
check('another project\'s notes are never returned', null === ProjectNotes::fetchWith($pdo, 'nulled')['text']);

echo "\nfetchWith(): a Portal that cannot answer\n";
$old = new PDO('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT)');
$old->exec("INSERT INTO projects (slug) VALUES ('x')");
$r = ProjectNotes::fetchWith($old, 'x');
check('column not there yet: unavailable, no exception', null === $r['text'] && true === $r['unavailable']);
$none = new PDO('sqlite::memory:');
$none->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('projects table missing: unavailable, no exception', true === ProjectNotes::fetchWith($none, 'x')['unavailable']);

echo "\nProjectInfo::describe(): wiring\n";
$base = sys_get_temp_dir().'/archnotes'.getmypid();
mkdir($base, 0775, true);
$p = [
    'label' => 'p', 'root' => $base, 'lanes' => ['code' => '', 'core' => ''], 'session_tools' => true, 'write_extensions' => ['php'],
    'pull_allowed' => false, 'pull_branch' => 'main', 'push_allowed' => false, 'push_branch' => 'main',
    'db_apply_allowed' => false, 'dependency_manager' => 'none', 'profile_environment' => 'staging',
];
$r = ProjectInfo::describe('demo', 'production', $p, null, ProjectNotes::clean($default));
check('the text is returned once, at the top level, unchanged', $default === $r['environment_notes'] && !isset($r['staging']['environment_notes']) && !isset($r['production']['environment_notes']));
check('no flags when nothing is wrong', !isset($r['environment_notes_truncated']) && !isset($r['environment_notes_unavailable']));
$r = ProjectInfo::describe('demo', 'production', $p, null, ProjectNotes::clean(null));
check('not set: the key is present and null', \array_key_exists('environment_notes', $r) && null === $r['environment_notes'] && !isset($r['environment_notes_unavailable']));
$r = ProjectInfo::describe('demo', 'production', $p, null);
check('notes not read at all: the key is present and null', \array_key_exists('environment_notes', $r) && null === $r['environment_notes']);
$r = ProjectInfo::describe('demo', 'production', $p, null, ProjectNotes::clean(str_repeat('a', 3000)));
check('truncated text carries the truncated flag', true === $r['environment_notes_truncated'] && 2000 === \strlen($r['environment_notes']));
$r = ProjectInfo::describe('demo', 'production', $p, null, ProjectNotes::fetchWith($old, 'x'));
check('an unreadable value carries the unavailable flag and still answers', true === $r['environment_notes_unavailable'] && null === $r['environment_notes'] && true === $r['success']);
check('the rest of the reply is unchanged: urls and lanes are still there', isset($r['urls']) && ['code', 'core'] === $r['staging']['lanes']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
