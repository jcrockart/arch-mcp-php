<?php
// Offline proof of the host facts in arch_project_info (ProjectHostFacts): the
// stored JSON passing through per environment, the not-set and unreadable cases,
// the safety limits (control characters, 1200-byte cut on a character boundary,
// invalid UTF-8, malformed records), the fail-soft read against an in-memory
// SQLite stand-in for Portal's projects table (including a Portal that does not
// have the column yet), and the wiring into ProjectInfo::describe() as a key
// separate from environment_notes. No network, no git.
//
//     php tests/unit/test-project-host-facts.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectHostFacts.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectHostFacts.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectNotes.php';
require $ROOT.'/src/ProjectHostFacts.php';
require $ROOT.'/src/ProjectInfo.php';

use ArchMcp\ProjectHostFacts;
use ArchMcp\ProjectInfo;
use ArchMcp\ProjectNotes;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$stagingText = "STAGING (arch-staging.crockart.com.au) checked 2026-10-29\nPHP 8.3.6 (fpm-fcgi); memory_limit 256M; upload_max 64M; post_max 64M; max_execution_time 30\nExt: pdo_sqlite yes, mbstring yes, gd yes, imagick no\nopen_basedir: none\nWritable: public/ yes; assets/ yes\nconfig.php constants: DB_ENGINE, DB_PATH";
$failedText = 'host facts: not collected (own domain) 2026-10-29';
$stored = json_encode([
    'staging' => ['ok' => true, 'checked_at' => '2026-10-29', 'host' => 'arch-staging.crockart.com.au', 'text' => $stagingText, 'dropped' => [], 'facts' => ['php' => '8.3.6']],
    'production' => ['ok' => false, 'checked_at' => '2026-10-29', 'reason' => 'own domain', 'text' => $failedText],
]);

echo "parse(): passes through\n";
$r = ProjectHostFacts::parse($stored);
check('both environments come back, staging first', false === $r['unavailable'] && ['staging', 'production'] === array_keys($r['environments']));
check('the block text is unchanged', $stagingText === $r['environments']['staging']['text']);
check('only ok, checked_at and text are passed on for a good record', ['ok', 'checked_at', 'text'] === array_keys($r['environments']['staging']) && true === $r['environments']['staging']['ok']);
check('a failed record carries its reason and the not-collected line', false === $r['environments']['production']['ok'] && 'own domain' === $r['environments']['production']['reason'] && $failedText === $r['environments']['production']['text']);
check("Portal's structured facts are not passed on", !isset($r['environments']['staging']['facts']) && !isset($r['environments']['staging']['host']));
$one = ProjectHostFacts::parse(json_encode(['production' => ['ok' => true, 'checked_at' => '2026-10-01', 'text' => 'PRODUCTION x']]));
check('an environment Portal has not collected is simply absent', ['production'] === array_keys($one['environments']));

echo "\nparse(): not set and unreadable\n";
foreach ([[null, 'NULL'], ['', 'empty string'], ["  \n", 'whitespace']] as [$v, $label]) {
    $r = ProjectHostFacts::parse($v);
    check("$label: no facts, not an error", null === $r['environments'] && false === $r['unavailable']);
}
foreach ([['junk', 'not JSON'], ['[]', 'empty list'], ['"x"', 'a string'], ['{"staging":"x"}', 'record is not an object'], ['{"staging":{"ok":1,"checked_at":"2026-10-29","text":"t"}}', 'ok is not a bool'], ['{"staging":{"ok":true,"checked_at":"yesterday","text":"t"}}', 'bad date'], ['{"staging":{"ok":true,"checked_at":"2026-10-29","text":"   "}}', 'blank text'], ['{"dev":{"ok":true,"checked_at":"2026-10-29","text":"t"}}', 'only an unknown environment']] as [$v, $label]) {
    $r = ProjectHostFacts::parse($v);
    check("$label: unavailable, no exception", null === $r['environments'] && true === $r['unavailable']);
}
$mixed = ProjectHostFacts::parse(json_encode(['staging' => 'bad', 'production' => ['ok' => true, 'checked_at' => '2026-10-02', 'text' => 'PRODUCTION fine']]));
check('one bad record is skipped, the good one is kept', ['production'] === array_keys($mixed['environments']) && false === $mixed['unavailable']);

echo "\nparse(): safety limits\n";
$with = static fn (string $text, array $extra = []): array => ProjectHostFacts::parse(json_encode(['staging' => array_merge(['ok' => true, 'checked_at' => '2026-10-29', 'text' => $text], $extra)]));
check('CRLF becomes LF and control characters go, newline and tab stay', "a\nb\tc\nd" === $with("a\r\nb\tc\x07\x1B\nd\x7F")['environments']['staging']['text']);
$r = $with(str_repeat('a', 1200));
check('exactly 1200 bytes is kept whole', 1200 === \strlen($r['environments']['staging']['text']));
$r = $with(str_repeat('a', 1500));
check('1500 bytes is cut to 1200', 1200 === \strlen($r['environments']['staging']['text']));
$r = $with(str_repeat('€', 600));
check('a 3-byte character text is cut on a character boundary', mb_check_encoding($r['environments']['staging']['text'], 'UTF-8') && \strlen($r['environments']['staging']['text']) <= 1200 && 0 === \strlen($r['environments']['staging']['text']) % 3);
$bad = ProjectHostFacts::parse("{\"staging\":{\"ok\":true,\"checked_at\":\"2026-10-29\",\"text\":\"\xff\xfe\"}}");
check('invalid UTF-8 never reaches the reply', null === $bad['environments'] || !isset($bad['environments']['staging']));
$r = $with('not collected', ['ok' => false, 'reason' => "line\none\x07 ".str_repeat('r', 200)]);
check('a reason is one line and at most 80 characters', 80 === mb_strlen($r['environments']['staging']['reason']) && !str_contains($r['environments']['staging']['reason'], "\n"));
$r = $with('not collected', ['ok' => false]);
check('a failed record with no reason says unknown reason', 'unknown reason' === $r['environments']['staging']['reason']);

echo "\nfetchWith(): the read, against a stand-in for Portal's projects table\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT, environment_notes TEXT, host_facts TEXT)');
$ins = $pdo->prepare('INSERT INTO projects (slug, environment_notes, host_facts) VALUES (:s, :n, :h)');
$ins->execute(['s' => 'withfacts', 'n' => 'hand written', 'h' => $stored]);
$ins->execute(['s' => 'nulled', 'n' => null, 'h' => null]);
$ins->execute(['s' => 'broken', 'n' => null, 'h' => 'not json']);
$r = ProjectHostFacts::fetchWith($pdo, 'withfacts');
check('a stored value comes back', false === $r['unavailable'] && $stagingText === $r['environments']['staging']['text']);
$r = ProjectHostFacts::fetchWith($pdo, 'nulled');
check('NULL in Portal: no facts, not flagged', null === $r['environments'] && false === $r['unavailable']);
check('unreadable JSON in Portal: unavailable', true === ProjectHostFacts::fetchWith($pdo, 'broken')['unavailable']);
$r = ProjectHostFacts::fetchWith($pdo, 'nobody');
check('unknown slug: unavailable, not "not set"', null === $r['environments'] && true === $r['unavailable']);
check("another project's facts are never returned", null === ProjectHostFacts::fetchWith($pdo, 'nulled')['environments']);

echo "\nfetchWith(): a Portal that cannot answer\n";
$old = new PDO('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, slug TEXT, environment_notes TEXT)');
$old->exec("INSERT INTO projects (slug) VALUES ('x')");
$r = ProjectHostFacts::fetchWith($old, 'x');
check('column not there yet: unavailable, no exception', null === $r['environments'] && true === $r['unavailable']);
$none = new PDO('sqlite::memory:');
$none->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
check('projects table missing: unavailable, no exception', true === ProjectHostFacts::fetchWith($none, 'x')['unavailable']);

echo "\nProjectInfo::describe(): wiring\n";
$base = sys_get_temp_dir().'/archhostfacts'.getmypid();
mkdir($base, 0775, true);
$p = [
    'label' => 'p', 'root' => $base, 'lanes' => ['code' => '', 'core' => ''], 'session_tools' => true, 'write_extensions' => ['php'],
    'pull_allowed' => false, 'pull_branch' => 'main', 'push_allowed' => false, 'push_branch' => 'main',
    'db_apply_allowed' => false, 'dependency_manager' => 'none', 'profile_environment' => 'staging',
];
$notes = ProjectNotes::clean('Hand written notes.');
$r = ProjectInfo::describe('demo', 'production', $p, null, $notes, ProjectHostFacts::parse($stored));
check('host_facts is its own top-level key, per environment', ['staging', 'production'] === array_keys($r['host_facts']) && $stagingText === $r['host_facts']['staging']['text']);
check('environment_notes is untouched by it', 'Hand written notes.' === $r['environment_notes'] && !isset($r['environment_notes_truncated']));
check('no flag when nothing is wrong', !isset($r['host_facts_unavailable']));
$r = ProjectInfo::describe('demo', 'production', $p, null, $notes, ProjectHostFacts::parse(null));
check('not set: the key is present and null', \array_key_exists('host_facts', $r) && null === $r['host_facts'] && !isset($r['host_facts_unavailable']));
$r = ProjectInfo::describe('demo', 'production', $p, null, $notes);
check('facts not read at all: the key is present and null', \array_key_exists('host_facts', $r) && null === $r['host_facts']);
$r = ProjectInfo::describe('demo', 'production', $p, null, $notes, ProjectHostFacts::fetchWith($old, 'x'));
check('an unreadable value carries the unavailable flag and still answers', true === $r['host_facts_unavailable'] && null === $r['host_facts'] && true === $r['success']);
check('the rest of the reply is unchanged', isset($r['urls']) && ['code', 'core'] === $r['staging']['lanes'] && 'Hand written notes.' === $r['environment_notes']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
