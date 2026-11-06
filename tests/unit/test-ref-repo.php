<?php
// Offline proof of the committed-code reads behind arch_ref (RefRepo,
// RefSearch, RefRead, ARCH-80), using throwaway real git repositories.
// Needs the git binary. No network, no secrets.
//
//     php tests/unit/test-ref-repo.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/RefRepo.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/RefRepo.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require_once $ROOT.'/src/Git.php';
require_once $ROOT.'/src/RefGuard.php';
require_once $ROOT.'/src/RefRepo.php';
require_once $ROOT.'/src/RefSearch.php';
require_once $ROOT.'/src/RefRead.php';

use ArchMcp\Git;
use ArchMcp\RefGuard;
use ArchMcp\RefRead;
use ArchMcp\RefRepo;
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

$base = sys_get_temp_dir().'/archref'.getmypid();
$p1 = "$base/p1";
$p2 = "$base/p2";
mkdir("$p1/src", 0775, true);
mkdir("$p1/other", 0775, true);
mkdir($p2, 0775, true);

file_put_contents("$p1/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'README.md']]]]));
file_put_contents("$p1/README.md", "readme needle\n");
file_put_contents("$p1/src/login.php", "<?php\n// avatar login needle\n\$password = 'supersecret1';\n");
file_put_contents("$p1/src/config.php", "<?php\n// needle in config\n");
file_put_contents("$p1/src/bin.php", "<?php\n\0needle\n");
file_put_contents("$p1/src/many.php", "<?php\n".str_repeat("manyhit\n", 15));
file_put_contents("$p1/other/o.php", "<?php\n// needle outside the stage paths\n");

sh($p1, 'git init -q');
sh($p1, 'git symbolic-ref HEAD refs/heads/main');
sh($p1, 'git add -A');
sh($p1, 'git -c user.name=t -c user.email=t@t commit -qm init');
// A second repo with no commits at all: no main branch.
sh($p2, 'git init -q');
sh($p2, 'git symbolic-ref HEAD refs/heads/main');

// Work that is not committed on main must never be visible: an edit in the
// working copy, an untracked file, and a commit on another (session) branch.
file_put_contents("$p1/src/login.php", "<?php\n// avatar login needle\n// uncommitted needle\n");
file_put_contents("$p1/src/untracked.php", "<?php\n// untracked needle\n");
sh($p1, 'git checkout -q -b sess');
file_put_contents("$p1/src/sessonly.php", "<?php\n// sessonly needle\n");
sh($p1, 'git add src/sessonly.php');
sh($p1, 'git -c user.name=t -c user.email=t@t commit -qm sess');

$g1 = Git::in($p1);
$g2 = Git::in($p2);

echo "RefRepo\n";
check('hasRef true for a repo with main', RefRepo::hasRef($g1));
check('hasRef false for a repo with no commits', !RefRepo::hasRef($g2));
$stage = RefRepo::stagePaths($g1);
check('stage paths read from the committed arch-gate.json', ['src', 'README.md'] === $stage);
check('no stage paths from a repo with no main', null === RefRepo::stagePaths($g2));
$files = RefRepo::files($g1, $stage ?? []);
check('files: committed, inside stage paths, not excluded', ['README.md', 'src/bin.php', 'src/login.php', 'src/many.php'] === $files);
check('...never the config file, other folders, untracked or session-only files', !\in_array('src/config.php', $files, true) && !\in_array('other/o.php', $files, true) && !\in_array('src/untracked.php', $files, true) && !\in_array('src/sessonly.php', $files, true));
check('files of an empty stage list is empty', [] === RefRepo::files($g1, []));
$shown = RefRepo::show($g1, 'src/login.php');
check('show returns the committed text, not the working copy', is_string($shown) && str_contains($shown, 'supersecret1') && !str_contains($shown, 'uncommitted'));
check('show refuses a binary file', null === RefRepo::show($g1, 'src/bin.php'));
check('show refuses traversal', null === RefRepo::show($g1, '../x'));
check('show of a missing file is null', null === RefRepo::show($g1, 'src/missing.php'));
check('show does not see a file that exists only on the session branch', null === RefRepo::show($g1, 'src/sessonly.php'));

echo "\nRefSearch\n";
$r = RefSearch::search(['p1' => $g1, 'p2' => $g2], 'needle');
check('search succeeds', true === $r['success']);
$paths = array_column($r['matches'], 'path');
sort($paths);
check('finds only committed, readable, in-stage matches', ['README.md', 'src/login.php'] === $paths);
check('every match is tagged project and carries its project', ['project'] === array_values(array_unique(array_column($r['matches'], 'tag'))) && ['p1'] === array_values(array_unique(array_column($r['matches'], 'project'))));
check('the project with no main is reported as skipped', isset($r['skipped_projects']) && str_contains(implode(',', $r['skipped_projects']), 'p2') && ['p1'] === $r['projects_searched']);
check('the binary file is counted as skipped', 1 === ($r['skipped_files'] ?? 0));
$r = RefSearch::search(['p1' => $g1], 'uncommitted');
check('uncommitted text is never found', 0 === $r['match_count']);
$r = RefSearch::search(['p1' => $g1], 'sessonly');
check('session-branch text is never found', 0 === $r['match_count']);
$r = RefSearch::search(['p1' => $g1], 'supersecret');
check('a secret-like line is found but its text is withheld', 1 === $r['match_count'] && RefGuard::WITHHELD === $r['matches'][0]['text']);
$r = RefSearch::search(['p1' => $g1], 'needle', 'src/');
check('path prefix narrows the search', 1 === $r['match_count'] && 'src/login.php' === $r['matches'][0]['path']);
$r = RefSearch::search(['p1' => $g1], 'NEEDLE');
check('case-sensitive by default', 0 === $r['match_count']);
$r = RefSearch::search(['p1' => $g1], 'NEEDLE', '', true);
check('ignore-case finds them', 2 === $r['match_count']);
$r = RefSearch::search(['p1' => $g1], 'needle', '', false, 1);
check('result cap truncates and says so', 1 === $r['match_count'] && true === $r['truncated'] && isset($r['note']));
$r = RefSearch::search(['p1' => $g1], 'manyhit', '', false, 100);
check('one project cannot fill the reply: per-project cap', RefSearch::PER_PROJECT === $r['match_count'] && true === $r['truncated']);
$r = RefSearch::search(['p1' => $g1], '');
check('empty search text refused', false === $r['success']);
$r = RefSearch::search([], 'needle');
check('no projects: success with an explanation', true === $r['success'] && 0 === $r['match_count'] && isset($r['note']));

echo "\nRefRead\n";
$r = RefRead::read($g1, 'p1', 'src/login.php', 1, 2);
check('reads a committed line range, tagged', true === $r['success'] && "<?php\n// avatar login needle" === $r['content'] && 'project' === $r['tag'] && 'main' === $r['ref'] && 'p1' === $r['project']);
$r = RefRead::read($g1, 'p1', 'src/login.php', 1, 3);
check('a secret-like line is withheld, line numbers unchanged', true === $r['success'] && 3 === $r['total_lines'] && str_ends_with($r['content'], RefGuard::WITHHELD) && !str_contains($r['content'], 'supersecret1'));
$r = RefRead::read($g1, 'p1', 'src/login.php');
check('no range reads from line 1', true === $r['success'] && 1 === $r['start']);
foreach (['src/config.php' => 'excluded config file', 'other/o.php' => 'file outside the stage paths', 'src/missing.php' => 'missing file', 'src/sessonly.php' => 'session-branch-only file', 'src/bin.php' => 'binary file', '../etc/passwd' => 'traversal'] as $path => $what) {
    $r = RefRead::read($g1, 'p1', $path);
    check("refused: {$what}", false === $r['success']);
}
$a = RefRead::read($g1, 'p1', 'src/config.php');
$b = RefRead::read($g1, 'p1', 'src/missing.php');
check('an excluded file and a missing file get the same kind of answer', false === $a['success'] && false === $b['success'] && str_replace('src/config.php', 'X', $a['error']) === str_replace('src/missing.php', 'X', $b['error']));
$r = RefRead::read($g2, 'p2', 'README.md');
check('a project with no main refuses', false === $r['success']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
