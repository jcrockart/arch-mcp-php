<?php
// Offline proof of the versioning rule and the default lane: the default lane
// follows the project, and a commit is versioned by one rule (GateConfig): the
// lane's "versioned" key, else the metadata lane, else a lane whose stage
// paths include metadata/. Two-lane and framework projects are not versioned.
// Throwaway git repos driven through ArchTools end to end. No network, no
// secrets.
//
//     php tests/unit/test-session-version.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/SessionVersion.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/SessionVersion.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (is_file($ROOT.'/src/ArchConfig.php')) {
    require $ROOT.'/src/ArchConfig.php';
} elseif (!class_exists('ArchMcp\\ArchConfig')) {
    eval('namespace ArchMcp; final class ArchConfig { public const REPO_PATH = "/nonexistent"; }');
}
ini_set('display_errors', '1');
error_reporting(E_ALL);
require $ROOT.'/src/ArchTools.php';
require_once $ROOT.'/src/CommitOutput.php'; // ArchTools.php does not load it; composer does in production

use ArchMcp\ArchTools;
use ArchMcp\SessionFile;
use ArchMcp\SessionLaneGuard;
use ArchMcp\SessionVersion;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function git(string $dir, string $args): string {
    exec('git -C '.escapeshellarg($dir).' '.$args.' 2>&1', $o, $rc);
    if (0 !== $rc) {
        fwrite(STDERR, "git $args failed in $dir:\n".implode("\n", $o)."\n");
        exit(2);
    }
    return implode("\n", $o);
}

$base = sys_get_temp_dir().'/archversion'.getmypid();
/**
 * A repo on main. $stage is the code lane's stage paths (null = no arch-gate.json);
 * $codeExtra is merged into the code lane's entry; $other adds more lanes.
 */
$make = static function (string $name, ?array $stage, array $codeExtra = [], array $other = []) use ($base): string {
    $d = "$base/$name";
    mkdir("$d/src", 0775, true);
    mkdir("$d/metadata", 0775, true);
    git($d, 'init -q -b main');
    git($d, 'config user.email t@example.com');
    git($d, 'config user.name t');
    if (null !== $stage) {
        $gate = ['code' => array_merge(['gate' => [], 'stage' => ['label' => 'code', 'paths' => $stage]], $codeExtra)] + $other;
        file_put_contents("$d/arch-gate.json", json_encode($gate));
    }
    file_put_contents("$d/.gitignore", ".arch-session.json\n");
    file_put_contents("$d/src/a.php", "<?php\n\$a = 1;\n");
    file_put_contents("$d/metadata/project.config.json", "{}\n");
    git($d, 'add -A');
    git($d, 'commit -q -m init');
    return $d;
};
$tools = static fn (string $d): ArchTools => new ArchTools($logger, ['label' => 't', 'root' => $d, 'lanes' => ['metadata' => 'metadata', 'core' => '', 'code' => ''], 'session_tools' => true]);
$n = 0;
/** An open session on its own branch, with a metadata file and a code file already committed there. */
$session = static function (string $d, string $lane) use (&$n): void {
    $b = 'work'.(++$n);
    git($d, "checkout -q -b $b");
    file_put_contents("$d/metadata/note$n.json", "{\"n\":$n}\n");
    file_put_contents("$d/src/b$n.php", "<?php\n// $n\n");
    git($d, 'add -A');
    git($d, 'commit -q -m session-work');
    file_put_contents("$d/.arch-session.json", json_encode(['branch' => $b, 'base_commit' => 'x', 'started_at' => '2026-10-16T00:00:00+00:00', 'lane' => $lane]));
};
$gitFn = static fn (string $d): callable => static function (array $args) use ($d): array {
    $p = proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $d);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($p), 'stdout' => (string) $out, 'stderr' => (string) $err];
};
/** Close a session started by a tool call so the next start can run. */
$reset = static function (string $d): void {
    $s = SessionFile::read($d);
    git($d, 'checkout -q main');
    if (null !== $s) {
        git($d, 'branch -q -D '.escapeshellarg($s['branch']));
    }
    SessionFile::delete($d);
};

echo "stagesMetadata() / defaultLane()\n";
$d = $make('rule', ['src', 'metadata', 'arch-gate.json']);
check('stage path "metadata" counts', SessionLaneGuard::stagesMetadata($d, 'code'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'metadata/']]]]));
check('stage path "metadata/" counts', SessionLaneGuard::stagesMetadata($d, 'code'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'metadata/entities']]]]));
check('a path under metadata/ counts', SessionLaneGuard::stagesMetadata($d, 'code'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'metadata-old', 'metadatax']]]]));
check('a name that only starts with "metadata" does not', !SessionLaneGuard::stagesMetadata($d, 'code'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src']]]]));
check('no metadata in the stage paths: no', !SessionLaneGuard::stagesMetadata($d, 'code'));
check('a lane with no entry: no', !SessionLaneGuard::stagesMetadata($d, 'nope'));
file_put_contents("$d/arch-gate.json", '{bad');
check('invalid arch-gate.json: no', !SessionLaneGuard::stagesMetadata($d, 'code'));
unlink("$d/arch-gate.json");
check('no arch-gate.json: no', !SessionLaneGuard::stagesMetadata($d, 'code'));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['metadata']]]]));
$all = ['metadata' => 'metadata', 'code' => '', 'core' => ''];
check('default lane is code when the code lane stages metadata', 'code' === SessionVersion::defaultLane($d, $all));
check('...but metadata when the caller has no code lane', 'metadata' === SessionVersion::defaultLane($d, ['metadata' => 'metadata']));
file_put_contents("$d/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src']]]]));
check('default lane stays metadata when the code lane does not', 'metadata' === SessionVersion::defaultLane($d, $all));

echo "\narchSessionStart() default lane\n";
$one = $make('start-one', ['public', 'src', 'db', 'metadata', 'arch-gate.json']);
$two = $make('start-two', ['public', 'src', 'db']);
$none = $make('start-none', null);
$lane = static fn (string $d): string => (string) (SessionFile::read($d)['lane'] ?? '');
$r = $tools($one)->archSessionStart();
check('one-lane project, no lane: code', 0 === $r['exit_code'] && 'code' === $lane($one));
$reset($one);
$r = $tools($one)->archSessionStart('mine');
check('...with a name too', 'code' === $lane($one) && 'mine' === SessionFile::read($one)['branch'] && 'mine' === git($one, 'rev-parse --abbrev-ref HEAD'));
$reset($one);
$tools($one)->archSessionStart('', 'metadata');
check('...explicit metadata still honoured', 'metadata' === $lane($one));
$reset($one);
$tools($two)->archSessionStart();
check('two-lane project, no lane: metadata', 'metadata' === $lane($two));
$reset($two);
$tools($two)->archSessionStart('', 'code');
check('...explicit code still honoured', 'code' === $lane($two));
$reset($two);
$tools($none)->archSessionStart();
check('no arch-gate.json, no lane: metadata', 'metadata' === $lane($none));
$reset($none);
$r = $tools($one)->archSessionStart('', 'nope');
check('a lane the token does not have is still refused', 1 === $r['exit_code'] && str_contains($r['stderr'], "no 'nope' lane") && !SessionFile::exists($one));

echo "\narchSessionCommit() on a one-lane project\n";
$d = $make('commit-one', ['public', 'src', 'db', 'metadata', 'arch-gate.json']);
$t = $tools($d);
$session($d, 'code');
$r = $t->archSessionCommit();
check('commit succeeds', 0 === $r['exit_code']);
check('VERSION.json written, same bytes as arch.py wrote', "{\n  \"major\": 0,\n  \"minor\": 1,\n  \"patch\": 1\n}" === file_get_contents("$d/VERSION.json"));
check('tag v0.1.1 created on the bump commit', git($d, 'rev-parse v0.1.1') === git($d, 'rev-parse HEAD') && 'Bump version to v0.1.1' === git($d, 'log -1 --format=%s'));
check('the commit carries both the metadata file and the code file', str_contains(git($d, 'ls-tree -r --name-only HEAD'), 'metadata/note1.json') && str_contains(git($d, 'ls-tree -r --name-only HEAD'), 'src/b1.php'));
check('the reply says it was versioned', str_contains($r['stdout'], 'v0.1.1') && true === $r['version']['ok'] && 'v0.1.1' === $r['version']['tag']);
check('working tree is clean on main', '' === git($d, 'status --porcelain') && 'main' === git($d, 'rev-parse --abbrev-ref HEAD'));
$session($d, 'code');
$r = $t->archSessionCommit('minor');
check('minor bump: v0.2.0', 0 === $r['exit_code'] && '0.2.0' === (static function ($d) { $v = json_decode(file_get_contents("$d/VERSION.json"), true); return "{$v['major']}.{$v['minor']}.{$v['patch']}"; })($d) && '' !== git($d, 'tag -l v0.2.0'));
$session($d, 'code');
$r = $t->archSessionCommit('major');
check('major bump: v1.0.0', 0 === $r['exit_code'] && '' !== git($d, 'tag -l v1.0.0') && str_contains($r['stdout'], 'v1.0.0'));
$session($d, 'code');
$t->archSessionCommit();
check('patch after that: v1.0.1', '' !== git($d, 'tag -l v1.0.1'));
check('four tags, no strays', 4 === count(array_filter(explode("\n", git($d, 'tag -l')))));

echo "\narchSessionCommit() where it must not version\n";
$d = $make('commit-two', ['public', 'src', 'db']);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('two-lane project, code lane: committed, no VERSION.json, no tag', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
$d = $make('commit-fw', ['src', 'public', 'tests', 'arch-gate.json', 'composer.json']);
$session($d, 'code');
$r = $tools($d)->archSessionCommit('minor');
check('framework-style project, code lane: no VERSION.json, no tag', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
$d = $make('commit-nogate', null);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('no arch-gate.json: a code session cannot commit (no gate for the lane), nothing versioned', 0 !== $r['exit_code'] && str_contains($r['stderr'], "No configuration found for lane 'code'") && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l'));
$d = $make('commit-false', ['src', 'metadata'], ['versioned' => false]);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('"versioned": false beats a metadata stage path', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
$d = $make('commit-true', ['src'], ['versioned' => true]);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('"versioned": true versions a lane with no metadata path', 0 === $r['exit_code'] && is_file("$d/VERSION.json") && '' !== git($d, 'tag -l v0.1.1'));
$d = $make('commit-fail', ['src', 'metadata'], ['gate' => [['name' => 'lint', 'cmd' => ['sh', '-c', 'echo broken; exit 1']]]]);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('a failing gate is not versioned and not merged', 0 !== $r['exit_code'] && str_contains($r['stdout'], "COMMIT ABORTED at gate 'lint'") && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']) && SessionFile::exists($d));
check('invalid bump still refused', 1 === $tools($d)->archSessionCommit('huge')['exit_code']);

echo "\nThe metadata lane is versioned by the same rule\n";
$d = $make('commit-meta', ['src'], [], ['metadata' => ['gate' => [], 'stage' => ['label' => 'metadata', 'paths' => ['metadata']]]]);
$session($d, 'metadata');
$r = $tools($d)->archSessionCommit();
check('metadata lane: versioned by this server', 0 === $r['exit_code'] && is_file("$d/VERSION.json") && '' !== git($d, 'tag -l v0.1.1') && true === $r['version']['ok']);
check('metadata lane merge message carries the tag', str_contains(git($d, 'log --format=%s'), 'Session commit: work'.$n.' -> v0.1.1'));

echo "\nSessionVersion::plan() and write(): failures leave nothing half done\n";
$d = $make('plan', ['src', 'metadata']);
git($d, 'tag v0.1.1');
$r = SessionVersion::plan($gitFn($d), $d, 'patch');
check('existing tag: refused, no VERSION.json', false === $r['ok'] && str_contains($r['error'], 'already exists') && !is_file("$d/VERSION.json") && '' === git($d, 'status --porcelain'));
file_put_contents("$d/VERSION.json", '{"major":"x"}');
git($d, 'add VERSION.json');
git($d, 'commit -q -m badversion');
$r = SessionVersion::plan($gitFn($d), $d, 'patch');
check('malformed VERSION.json: refused, file untouched', false === $r['ok'] && '{"major":"x"}' === file_get_contents("$d/VERSION.json"));
file_put_contents("$d/VERSION.json", "{\n  \"major\": 2,\n  \"minor\": 3,\n  \"patch\": 4\n}");
git($d, 'commit -q -am goodversion');
$r = SessionVersion::plan($gitFn($d), $d, 'minor');
check('existing VERSION.json is read and bumped: 2.4.0', true === $r['ok'] && 'v2.4.0' === $r['tag'] && ['major' => 2, 'minor' => 4, 'patch' => 0] === $r['version']);
check('plan() writes nothing', "{\n  \"major\": 2,\n  \"minor\": 3,\n  \"patch\": 4\n}" === file_get_contents("$d/VERSION.json") && '' === git($d, 'status --porcelain'));
$w = SessionVersion::write($gitFn($d), $d, $r['version'], $r['tag']);
check('write() commits and tags', true === $w['ok'] && 'v2.4.0' === $w['tag'] && git($d, 'rev-parse v2.4.0') === git($d, 'rev-parse HEAD'));
check('invalid bump refused', false === SessionVersion::plan($gitFn($d), $d, 'huge')['ok']);
$failAdd = static fn (array $args): array => 'add' === $args[0] ? ['exit_code' => 1, 'stdout' => '', 'stderr' => 'no add'] : $gitFn($d)($args);
$w = SessionVersion::write($failAdd, $d, ['major' => 3, 'minor' => 0, 'patch' => 0], 'v3.0.0');
check('a failing git add restores VERSION.json', false === $w['ok'] && "{\n  \"major\": 2,\n  \"minor\": 4,\n  \"patch\": 0\n}" === file_get_contents("$d/VERSION.json") && '' === git($d, 'tag -l v3.0.0'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
