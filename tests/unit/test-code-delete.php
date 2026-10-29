<?php
// Offline proof of arch_code_delete_file: the delete policy (CodeDelete), the guarded
// deletion (ArchTools::archCodeDeleteFile) and, in a real temporary git repository, that a
// deleted file is gone from the commit a session commit makes (not just emptied).
//
//     php tests/unit/test-code-delete.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CodeDelete.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CodeDelete.php')) {
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
foreach (['Git', 'GateConfig', 'GateRunner', 'SessionFile', 'SessionResult', 'SessionStart', 'SessionVersion', 'SessionCommit', 'ArchTools', 'CodeDelete'] as $c) {
    require_once $ROOT.'/src/'.$c.'.php';
}

use ArchMcp\ArchTools;
use ArchMcp\CodeDelete;
use ArchMcp\Git;
use ArchMcp\SessionCommit;
use ArchMcp\SessionStart;

// No identity from the machine: the session engine supplies its own fallback.
putenv('GIT_CONFIG_GLOBAL=/dev/null');
putenv('GIT_CONFIG_NOSYSTEM=1');

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function sh(string $dir, string ...$args): string {
    return rtrim(Git::run($dir, $args)['stdout']);
}

echo "CodeDelete::protectedRefusal()\n";
check('arch-gate.json refused', null !== CodeDelete::protectedRefusal('arch-gate.json'));
check('...whatever the case', null !== CodeDelete::protectedRefusal('ARCH-Gate.JSON'));
check('...with a leading slash', null !== CodeDelete::protectedRefusal('/arch-gate.json'));
check('metadata/project.config.json refused', null !== CodeDelete::protectedRefusal('metadata/project.config.json'));
check('...with a doubled slash', null !== CodeDelete::protectedRefusal('metadata//project.config.json'));
check('...with backslashes', null !== CodeDelete::protectedRefusal('metadata\\project.config.json'));
check('the refusal says what to do instead', str_contains((string) CodeDelete::protectedRefusal('arch-gate.json'), 'arch_code_edit_file'));
check('a file of the same name in another folder is fine', null === CodeDelete::protectedRefusal('src/arch-gate.json'));
check('an ordinary file is fine', null === CodeDelete::protectedRefusal('src/a.php'));

echo "\nCodeDelete::stageEntryRefusal()\n";
check('a file that is itself a stage path is refused', null !== CodeDelete::stageEntryRefusal('README.md', ['src', 'README.md']));
check('...and the message says what can be deleted', str_contains((string) CodeDelete::stageEntryRefusal('README.md', ['src', 'README.md']), 'inside a stage folder'));
check('a file inside a stage folder is fine', null === CodeDelete::stageEntryRefusal('src/a.php', ['src', 'README.md']));
check('no stage paths: nothing refused', null === CodeDelete::stageEntryRefusal('README.md', []));
check('non-string entries are ignored', null === CodeDelete::stageEntryRefusal('README.md', [7, null, ['x']]));

echo "\nArchTools::archCodeDeleteFile(): the code-lane rules\n";
function repo(array $stage): string {
    $d = sys_get_temp_dir().'/codedelete-'.bin2hex(random_bytes(6));
    mkdir($d, 0777, true);
    Git::run($d, ['init', '-q', '-b', 'main']);
    file_put_contents($d.'/.gitignore', ".arch-session.json\n");
    file_put_contents($d.'/arch-gate.json', json_encode(['code' => [
        'gate' => [['name' => 'ok', 'cmd' => ['sh', '-c', 'echo fine']]],
        'stage' => ['label' => 'code', 'paths' => $stage],
    ]]));
    $files = [
        'src/a.php' => "<?php\n\$a = 1;\n",
        'src/b.php' => "<?php\n\$b = 2;\n",
        'src/notes.md' => "notes\n",
        'src/sub/only.php' => "<?php\n\$only = 1;\n",
        'README.md' => "readme\n",
        'other/o.php' => "<?php\n\$o = 1;\n",
    ];
    foreach ($files as $rel => $content) {
        @mkdir(\dirname($d.'/'.$rel), 0777, true);
        file_put_contents($d.'/'.$rel, $content);
    }
    Git::run($d, ['add', '-A']);
    Git::run($d, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'init']);

    return $d;
}

$d = repo(['src', 'README.md', 'arch-gate.json']);
$g = Git::in($d);
$tools = new ArchTools($logger, ['label' => 't', 'root' => $d, 'lanes' => ['code' => ''], 'session_tools' => true]);

$r = $tools->archCodeDeleteFile('src/b.php');
check('no session: refused', false === $r['success'] && str_contains($r['error'], 'no active session'));
check('...and the file is still there', is_file($d.'/src/b.php'));

$s = SessionStart::run($d, 'work', 'code', $g);
check('session started on the code lane', 0 === $s['exit_code']);

$r = $tools->archCodeDeleteFile('src/b.php');
check('delete succeeds', true === $r['success'] && true === $r['deleted'] && 'src/b.php' === $r['path'] && 14 === $r['bytes']);
check('the file is gone from disk', !is_file($d.'/src/b.php'));
$r = $tools->archCodeDeleteFile('src/b.php');
check('deleting it again: refused, does not exist', false === $r['success'] && str_contains($r['error'], 'does not exist'));
$r = $tools->archCodeDeleteFile('src/sub');
check('a folder is refused with the way forward', false === $r['success'] && str_contains($r['error'], 'folder') && is_dir($d.'/src/sub'));
$r = $tools->archCodeDeleteFile('other/o.php');
check('outside the stage paths: refused, file untouched', false === $r['success'] && is_file($d.'/other/o.php'));
$r = $tools->archCodeDeleteFile('../escape.php');
check('traversal refused', false === $r['success']);
$r = $tools->archCodeDeleteFile('src/.hidden');
check('dot component refused', false === $r['success']);
$r = $tools->archCodeDeleteFile('arch-gate.json');
check('arch-gate.json refused even though it is a stage path', false === $r['success'] && str_contains($r['error'], 'protected') && is_file($d.'/arch-gate.json'));
$r = $tools->archCodeDeleteFile('README.md');
check('a stage-path file refused, file untouched', false === $r['success'] && str_contains($r['error'], 'stage path') && is_file($d.'/README.md'));

$restricted = new ArchTools($logger, ['label' => 't', 'root' => $d, 'lanes' => ['code' => ''], 'session_tools' => true, 'write_extensions' => ['md']]);
$r = $restricted->archCodeDeleteFile('src/a.php');
check('write-extension allowlist applies to deleting', false === $r['success'] && str_contains($r['error'], 'not permitted') && is_file($d.'/src/a.php'));
$r = $restricted->archCodeDeleteFile('src/notes.md');
check('...and an allowed type is deleted', true === $r['success'] && !is_file($d.'/src/notes.md'));

$noLane = new ArchTools($logger, ['label' => 't', 'root' => $d, 'lanes' => ['metadata' => 'metadata'], 'session_tools' => true]);
$r = $noLane->archCodeDeleteFile('src/a.php');
check('a profile without a code lane: refused', false === $r['success'] && is_file($d.'/src/a.php'));

echo "\nrename = write the new path, delete the old one; a folder's last file\n";
$w = $tools->archCodeWriteFile('src/c.php', "<?php\n\$a = 1;\n");
check('new file written', true === $w['success']);
$r = $tools->archCodeDeleteFile('src/a.php');
check('old file deleted', true === $r['success'] && !is_file($d.'/src/a.php'));
$r = $tools->archCodeDeleteFile('src/sub/only.php');
check('the only file of a sub-folder deleted', true === $r['success'] && !is_file($d.'/src/sub/only.php'));

echo "\nthe deletions reach git on session commit\n";
$r = SessionCommit::run($d, 'patch', $g);
check('commit succeeds', 0 === $r['exit_code'] && str_contains($r['stdout'], 'COMMITTED'));
check('checkout is back on main', 'main' === sh($d, 'rev-parse', '--abbrev-ref', 'HEAD'));
foreach (['src/a.php', 'src/b.php', 'src/notes.md', 'src/sub/only.php'] as $gone) {
    check("$gone is not in main", 0 !== Git::run($d, ['cat-file', '-e', 'main:'.$gone])['exit_code'] && '' === sh($d, 'ls-files', '--', $gone));
}
check('the new file is in main', 0 === Git::run($d, ['cat-file', '-e', 'main:src/c.php'])['exit_code']);
check('files that were not deleted are still in main', 0 === Git::run($d, ['cat-file', '-e', 'main:README.md'])['exit_code'] && 0 === Git::run($d, ['cat-file', '-e', 'main:other/o.php'])['exit_code']);
$diff = sh($d, 'diff', '--no-renames', '--name-status', 'HEAD^1', 'HEAD');
check('the merge shows the four deletions and the addition', 1 === preg_match('/^D\s+src\/b\.php$/m', $diff) && 1 === preg_match('/^D\s+src\/a\.php$/m', $diff) && 1 === preg_match('/^D\s+src\/notes\.md$/m', $diff) && 1 === preg_match('/^D\s+src\/sub\/only\.php$/m', $diff) && 1 === preg_match('/^A\s+src\/c\.php$/m', $diff));
check('the working tree is clean after the commit', '' === sh($d, 'status', '--porcelain'));
check('none of the deleted files is back on disk', !is_file($d.'/src/a.php') && !is_file($d.'/src/b.php') && !is_file($d.'/src/notes.md') && !is_file($d.'/src/sub/only.php'));

exec('rm -rf '.escapeshellarg($d));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
