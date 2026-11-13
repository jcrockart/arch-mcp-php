<?php
// Offline proof of the arch-library parts of arch_ref (ARCH-94): the listing of
// functions (RefLibrary) and the never-copy rules (RefGuard, RefRead, RefSearch).
// Throwaway git repositories; needs the git binary. No network, no secrets.
//
//     php tests/unit/test-ref-library.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/RefLibrary.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/RefLibrary.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require_once $ROOT.'/src/Git.php';
require_once $ROOT.'/src/RefGuard.php';
require_once $ROOT.'/src/RefRepo.php';
require_once $ROOT.'/src/RefSearch.php';
require_once $ROOT.'/src/RefRead.php';
require_once $ROOT.'/src/RefLibrary.php';

use ArchMcp\Git;
use ArchMcp\RefGuard;
use ArchMcp\RefLibrary;
use ArchMcp\RefRead;
use ArchMcp\RefSearch;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function sh(string $dir, string $cmd): int {
    exec('cd '.escapeshellarg($dir).' && '.$cmd.' >/dev/null 2>&1', $out, $code);
    return $code;
}
function put(string $path, string $body): void {
    @mkdir(\dirname($path), 0775, true);
    file_put_contents($path, $body);
}
/** @param array<string, mixed> $over */
function manifest(string $slug, array $over = []): string {
    return json_encode($over + [
        'slug' => $slug, 'name' => 'Name '.$slug, 'summary' => 'Does a thing.', 'version' => '1.0.0', 'status' => 'draft',
        'sanitizedOn' => '2026-11-10', 'tags' => ['a', 'b'], 'requires' => ['php>=8.1'],
        'files' => [['path' => 'main.php', 'role' => 'php', 'note' => 'x'], ['path' => 'main.sql', 'role' => 'sql']],
    ], JSON_PRETTY_PRINT);
}

echo "never-copy paths\n";
foreach (['src/functions/x/arch_node_publish.php', 'arch-gate.json', 'src/functions/x/project.config.json', 'src/demos/a.php', 'src/functions/x/src/demos/a.php',
    'db/migrations/0001.sql', 'src/functions/x/your_page.php', 'src/functions/your_dir/a.php', 'ARCH_NODE_PUBLISH.PHP'] as $p) {
    check("refused: $p", true === RefGuard::neverCopy($p));
}
foreach (['src/functions/live-chat/chat.php', 'src/functions/live-chat/chat_messages.sql', 'src/refs/NOTES.md', 'src/demonstration.php', 'src/functions/x/mine_your.php'] as $p) {
    check("allowed: $p", false === RefGuard::neverCopy($p));
}
check('other projects are not subject to the library paths', true === RefGuard::readableIn('some-app', 'src/demos/a.php', ['src']));
check('...but arch-library is', false === RefGuard::readableIn('arch-library', 'src/demos/a.php', ['src']));
check('config.php and secret names stay refused everywhere', false === RefGuard::readableIn('some-app', 'src/config.php', ['src']) && false === RefGuard::readableIn('arch-library', 'src/password.txt', ['src']));

echo "\ncontent warnings\n";
$f = RefGuard::copyFlags("ok line\n-----BEGIN RSA PRIVATE KEY-----\n\$password = 'hunter22';\n\$k = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';\n\$d = '/home/bob/site/';\nmail me@example.com\n\$db = 'sqlite:/var/data/app.db';\nplain");
$rules = array_column($f, 'rule', 'line');
check('private key block flagged', 'private key block' === ($rules[2] ?? null));
check('secret-looking assignment flagged', 'secret-looking assignment' === ($rules[3] ?? null));
check('long key-like string flagged', 'long key-like string' === ($rules[4] ?? null));
check('server path flagged', 'server path' === ($rules[5] ?? null));
check('email flagged', 'email address' === ($rules[6] ?? null));
check('absolute sqlite path flagged', 'absolute sqlite path' === ($rules[7] ?? null));
check('clean lines are not flagged', !isset($rules[1]) && !isset($rules[8]));
check('relative sqlite path is fine', [] === RefGuard::copyFlags("\$db = 'sqlite:data/app.db';"));
check('flags are capped', RefGuard::MAX_FLAGS === count(RefGuard::copyFlags(str_repeat("a@b.co\n", 100))));

echo "\nthe library repository\n";
$base = sys_get_temp_dir().'/archreflib'.getmypid();
$lib = "$base/lib";
put("$lib/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'metadata']]]]));
put("$lib/src/functions/good/function.json", manifest('good'));
put("$lib/src/functions/good/main.php", "<?php\n\$password = 'realsecret99';\n");
put("$lib/src/functions/good/main.sql", "CREATE TABLE t (id INTEGER);\n");
put("$lib/src/functions/bad-slug/function.json", manifest('other'));
put("$lib/src/functions/bad-slug/main.php", "<?php\n");
put("$lib/src/functions/bad-slug/main.sql", "-- x\n");
put("$lib/src/functions/missing-file/function.json", manifest('missing-file'));
put("$lib/src/functions/missing-file/main.php", "<?php\n");
put("$lib/src/functions/no-manifest/main.php", "<?php\n");
put("$lib/src/functions/broken/function.json", "{not json");
put("$lib/src/functions/bad-fields/function.json", manifest('bad-fields', ['version' => '1.0', 'status' => 'great', 'files' => [['path' => '../x.php', 'role' => 'php'], ['path' => 'main.php', 'role' => 'cobol']], 'tags' => 'nope']));
put("$lib/src/functions/bad-fields/main.php", "<?php\n");
put("$lib/src/functions/withpub/function.json", manifest('withpub', ['files' => [['path' => 'main.php', 'role' => 'php'], ['path' => 'arch_node_publish.php', 'role' => 'php'], ['path' => 'your_page.php', 'role' => 'php']]]));
put("$lib/src/functions/withpub/main.php", "<?php\n");
put("$lib/src/functions/withpub/arch_node_publish.php", "<?php\n");
put("$lib/src/functions/withpub/your_page.php", "<?php\n");
put("$lib/src/demos/demo.php", "<?php\n// needle\n");
put("$lib/src/refs/README.md", "# Refs\nabout refs\n");
put("$lib/src/refs/PUSH.md", "# Push guide\n\nRead this when you add push alerts.\n\nMore text.\n");
sh($lib, 'git init -q');
sh($lib, 'git symbolic-ref HEAD refs/heads/main');
sh($lib, 'git add -A');
sh($lib, 'git -c user.name=t -c user.email=t@t commit -qm init');
$git = Git::in($lib);

$r = RefLibrary::list($git);
check('list succeeds and is labelled library', true === $r['success'] && 'library' === $r['tag']);
$bySlug = array_column($r['functions'], null, 'slug');
check('only valid manifests are functions', ['good', 'withpub'] === array_keys($bySlug));
check('each function carries slug, name, summary, status, version, requires, tags, sanitizedOn', [] === array_diff(['slug', 'name', 'summary', 'status', 'version', 'requires', 'tags', 'sanitizedOn', 'files'], array_keys($bySlug['good'])) && 'draft' === $bySlug['good']['status'] && '1.0.0' === $bySlug['good']['version'] && ['php>=8.1'] === $bySlug['good']['requires']);
check('files are path and role only', [['path' => 'main.php', 'role' => 'php'], ['path' => 'main.sql', 'role' => 'sql']] === $bySlug['good']['files']);
check('never-copy files in a manifest are marked', !isset($bySlug['withpub']['files'][0]['not_copyable']) && true === $bySlug['withpub']['files'][1]['not_copyable'] && true === $bySlug['withpub']['files'][2]['not_copyable']);
$problems = array_column($r['problems'], 'reasons', 'folder');
check('five folders with problems, listed apart', ['bad-fields', 'bad-slug', 'broken', 'missing-file', 'no-manifest'] === array_keys($problems));
check('slug that differs from the folder is a problem', in_array('slug must equal the folder name', $problems['bad-slug'], true));
check('a listed file that does not exist is a problem', (bool) array_filter($problems['missing-file'], static fn (string $s): bool => str_contains($s, "'main.sql' does not exist")));
check('bad version, status, role, path and tags are all reported', 5 <= count($problems['bad-fields']));
check('invalid JSON and a missing manifest are explained', str_contains($problems['broken'][0], 'not valid JSON') && str_contains($problems['no-manifest'][0], 'no readable function.json'));
check('refs listed with title and read-when line, README skipped', [['path' => 'src/refs/PUSH.md', 'title' => 'Push guide', 'read_when' => 'Read this when you add push alerts.']] === $r['refs']);
check('the listing never carries file text', !str_contains(json_encode($r), 'realsecret99') && !str_contains(json_encode($r), 'CREATE TABLE'));

echo "\nread and search in arch-library\n";
$stage = ['src', 'metadata'];
$read = RefRead::read($git, 'arch-library', 'src/functions/good/main.php');
check('a normal library file reads, tagged library with a copy note', 'library' === $read['tag'] && str_contains($read['copy_note'], 'metadata/notes/'));
check('secret-like lines are still withheld', str_contains($read['content'], RefGuard::WITHHELD) && !str_contains($read['content'], 'realsecret99'));
check('...and the warning names the line', [['line' => 2, 'rule' => 'secret-looking assignment']] === $read['copy_warnings']);
$clean = RefRead::read($git, 'arch-library', 'src/functions/good/main.sql');
check('a clean file has no warnings key', !isset($clean['copy_warnings']));
foreach (['src/functions/withpub/arch_node_publish.php', 'src/functions/withpub/your_page.php', 'src/demos/demo.php', 'arch-gate.json'] as $p) {
    $x = RefRead::read($git, 'arch-library', $p);
    check("read refused: $p", false === $x['success']);
}
$other = RefRead::read($git, 'some-app', 'src/demos/demo.php');
check('the same path in another project is not subject to the library rule', true === ($other['success'] ?? false) && 'project' === $other['tag'] && !isset($other['copy_note']));
$s = RefSearch::search(['arch-library' => $git], 'needle');
check('search in the library does not see src/demos', 0 === $s['match_count']);
$s = RefSearch::search(['some-app' => $git], 'needle');
check('search in another project still does', 1 === $s['match_count']);
$s = RefSearch::search(['arch-library' => $git], '<?php');
check('search in the library finds ordinary files', $s['match_count'] > 0 && [] === array_filter($s['matches'], static fn (array $m): bool => str_contains($m['path'], 'arch_node_publish') || str_contains($m['path'], 'your_')));

echo "\nlist failures\n";
$empty = "$base/empty";
mkdir($empty, 0775, true);
sh($empty, 'git init -q');
sh($empty, 'git symbolic-ref HEAD refs/heads/main');
check('no committed main: a clear error', false === RefLibrary::list(Git::in($empty))['success']);
$nofn = "$base/nofn";
put("$nofn/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['public']]]]));
sh($nofn, 'git init -q');
sh($nofn, 'git symbolic-ref HEAD refs/heads/main');
sh($nofn, 'git add -A');
sh($nofn, 'git -c user.name=t -c user.email=t@t commit -qm init');
check('stage paths without src/functions: a clear error', false === RefLibrary::list(Git::in($nofn))['success']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
