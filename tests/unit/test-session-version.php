<?php
// Offline proof of SessionVersion: the default lane follows the project, and a
// commit that carries metadata/ is versioned whichever lane it ran in, while
// two-lane and framework projects (no metadata/ in the code lane) are not.
// Throwaway git repos with a stub arch.py that behaves like the real
// `session commit` for a non-versioned lane (merge, retire, print COMMITTED).
// No network, no secrets.
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
$STUB = <<<'PY'
import json, subprocess, sys
from pathlib import Path
REPO = Path(__file__).parent
def sh(*a):
    subprocess.run(["git", *a], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
args = sys.argv[1:]
(REPO / ".stub-args").write_text(" ".join(args))
mode = (REPO / ".stub-mode").read_text().strip() if (REPO / ".stub-mode").exists() else ""
if args[:2] == ["session", "start"]:
    print("started")
    sys.exit(0)
if args[:2] == ["session", "commit"]:
    s = json.loads((REPO / ".arch-session.json").read_text())
    b = s["branch"]
    lane = s.get("lane", "metadata")
    print("Validating session '%s' (lane: %s) before commit...\n" % (b, lane))
    print("-- gate: lint")
    if mode == "fail":
        print("COMMIT ABORTED at gate 'lint'.")
        sys.exit(1)
    sh("checkout", "main")
    sh("merge", "--no-ff", b, "-m", "Session commit (%s): %s" % (lane, b))
    sh("branch", "-d", b)
    (REPO / ".arch-session.json").unlink()
    if mode == "versioned":
        print("\nCOMMITTED as v9.9.9. Session '%s' merged to main and retired." % b)
    else:
        print("\nCOMMITTED. Session '%s' (lane: %s) merged to main and retired." % (b, lane))
    sys.exit(0)
sys.exit(2)
PY;

$base = sys_get_temp_dir().'/archversion'.getmypid();
/** A repo on main; $stage is the code lane's stage paths (null = no arch-gate.json). */
$make = static function (string $name, ?array $stage) use ($base, $STUB): string {
    $d = "$base/$name";
    mkdir("$d/src", 0775, true);
    mkdir("$d/metadata", 0775, true);
    git($d, 'init -q -b main');
    git($d, 'config user.email t@example.com');
    git($d, 'config user.name t');
    if (null !== $stage) {
        file_put_contents("$d/arch-gate.json", json_encode(['code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => $stage]]]));
    }
    file_put_contents("$d/arch.py", $STUB);
    file_put_contents("$d/.gitignore", ".stub-args\n.stub-mode\n.arch-session.json\n");
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
check('needsBump: never for the metadata lane or no lane', !SessionVersion::needsBump($d, 'metadata') && !SessionVersion::needsBump($d, ''));

echo "\narchSessionStart() default lane\n";
$one = $make('start-one', ['public', 'src', 'db', 'metadata', 'arch-gate.json']);
$two = $make('start-two', ['public', 'src', 'db']);
$none = $make('start-none', null);
$lane = static fn (string $d): string => trim((string) file_get_contents("$d/.stub-args"));
$tools($one)->archSessionStart();
check('one-lane project, no lane: code', str_contains($lane($one), '--lane=code'));
$tools($one)->archSessionStart('mine');
check('...with a name too', str_contains($lane($one), 'start mine --lane=code'));
$tools($one)->archSessionStart('', 'metadata');
check('...explicit metadata still honoured', str_contains($lane($one), '--lane=metadata'));
$tools($two)->archSessionStart();
check('two-lane project, no lane: metadata', str_contains($lane($two), '--lane=metadata'));
$tools($two)->archSessionStart('', 'code');
check('...explicit code still honoured', str_contains($lane($two), '--lane=code'));
$tools($none)->archSessionStart();
check('no arch-gate.json, no lane: metadata', str_contains($lane($none), '--lane=metadata'));
$r = $tools($one)->archSessionStart('', 'nope');
check('a lane the token does not have is still refused', 1 === $r['exit_code'] && str_contains($r['stderr'], "no 'nope' lane"));

echo "\narchSessionCommit() on a one-lane project\n";
$d = $make('commit-one', ['public', 'src', 'db', 'metadata', 'arch-gate.json']);
$t = $tools($d);
$session($d, 'code');
$r = $t->archSessionCommit();
check('commit succeeds', 0 === $r['exit_code']);
check('VERSION.json written, same bytes as arch.py', "{\n  \"major\": 0,\n  \"minor\": 1,\n  \"patch\": 1\n}" === file_get_contents("$d/VERSION.json"));
check('tag v0.1.1 created on the bump commit', git($d, 'rev-parse v0.1.1') === git($d, 'rev-parse HEAD') && 'Bump version to v0.1.1' === git($d, 'log -1 --format=%s'));
check('the commit carries both the metadata file and the code file', str_contains(git($d, 'ls-tree -r --name-only HEAD'), 'metadata/note1.json') && str_contains(git($d, 'ls-tree -r --name-only HEAD'), 'src/b1.php'));
check('the reply says it was versioned', str_contains($r['stdout'], 'Versioned as v0.1.1') && true === $r['version']['ok']);
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
check('framework-style project, code lane: no VERSION.json, no tag', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !str_contains($r['stdout'], 'Versioned'));
$d = $make('commit-nogate', null);
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('no arch-gate.json: no version', !is_file("$d/VERSION.json") && '' === git($d, 'tag -l'));
$d = $make('commit-meta', ['src', 'metadata']);
file_put_contents("$d/.stub-mode", 'versioned');
$session($d, 'metadata');
$r = $tools($d)->archSessionCommit();
check('metadata lane: this server leaves it to arch.py', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('a newer arch.py that already versioned the commit is not bumped twice', 0 === $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
$d = $make('commit-fail', ['src', 'metadata']);
file_put_contents("$d/.stub-mode", 'fail');
$session($d, 'code');
$r = $tools($d)->archSessionCommit();
check('a failing commit is not versioned', 0 !== $r['exit_code'] && !is_file("$d/VERSION.json") && '' === git($d, 'tag -l') && !isset($r['version']));
check('invalid bump still refused', 1 === $tools($d)->archSessionCommit('huge')['exit_code']);

echo "\nSessionVersion::apply() failures leave nothing half done\n";
$d = $make('apply', ['src', 'metadata']);
git($d, 'tag v0.1.1');
$r = SessionVersion::apply($gitFn($d), $d, 'patch');
check('existing tag: refused, no VERSION.json', false === $r['ok'] && str_contains($r['error'], 'already exists') && !is_file("$d/VERSION.json") && '' === git($d, 'status --porcelain'));
file_put_contents("$d/VERSION.json", '{"major":"x"}');
git($d, 'add VERSION.json');
git($d, 'commit -q -m badversion');
$r = SessionVersion::apply($gitFn($d), $d, 'patch');
check('malformed VERSION.json: refused, file untouched', false === $r['ok'] && '{"major":"x"}' === file_get_contents("$d/VERSION.json"));
file_put_contents("$d/VERSION.json", "{\n  \"major\": 2,\n  \"minor\": 3,\n  \"patch\": 4\n}");
git($d, 'commit -q -am goodversion');
$r = SessionVersion::apply($gitFn($d), $d, 'minor');
check('existing VERSION.json is read and bumped: 2.4.0', true === $r['ok'] && 'v2.4.0' === $r['tag']);
git($d, 'checkout -q -b side');
$r = SessionVersion::apply($gitFn($d), $d, 'patch');
check('not on main: refused', false === $r['ok'] && str_contains($r['error'], 'not on main'));
check('invalid bump refused', false === SessionVersion::apply($gitFn($d), $d, 'huge')['ok']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
