<?php
// Offline proof of the managed-file rules (ManagedFile): prefix naming and
// the per-type header, including that JSON stays valid and the rest of each
// file stays as it was. Pure functions, no network, no files.
//
//     php tests/unit/test-managed-file.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ManagedFile.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ManagedFile.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ManagedFile.php';

use ArchMcp\ManagedFile;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "names\n";
check('target name carries the prefix', 'arch-bootstrap--entity.schema.json' === ManagedFile::targetName('entity.schema.json'));
check('a prefixed name is managed', ManagedFile::isManagedName('arch-bootstrap--x.md'));
check('an ordinary name is not', !ManagedFile::isManagedName('x.md') && !ManagedFile::isManagedName('notes-arch-bootstrap--x.md'));
check('the bare prefix is not a managed name', !ManagedFile::isManagedName('arch-bootstrap--'));
check('source name round-trips', 'a.md' === ManagedFile::sourceName(ManagedFile::targetName('a.md')));
check('source name of an ordinary file is null', null === ManagedFile::sourceName('a.md'));
check('sha256 is the plain hex digest', hash('sha256', 'abc') === ManagedFile::sha256('abc'));

echo "\nmarkdown\n";
$r = ManagedFile::withHeader('A.md', "# Title\n\ntext\n");
check('starts with an HTML comment saying it is managed', str_starts_with($r, '<!-- MANAGED FRAMEWORK FILE.') && str_contains($r, '(A.md)'));
check('the original follows the header unchanged', str_ends_with($r, "-->\n\n# Title\n\ntext\n"));
$r = ManagedFile::withHeader('F.md', "---\ntitle: x\n---\n# Body\n");
check('front matter stays first, header goes after it', str_starts_with($r, "---\ntitle: x\n---\n<!-- MANAGED") && str_ends_with($r, "-->\n\n# Body\n"));
$r = ManagedFile::withHeader('a--b.md', 'x');
preg_match('/^<!-- (.*) -->/', $r, $m);
check('a double hyphen in the file name cannot end the comment early', isset($m[1]) && !str_contains($m[1], '--'));

echo "\njson\n";
$src = '{"a": 1, "b": [1,2]}';
$r = ManagedFile::withHeader('s.json', $src);
$d = json_decode((string) $r, true);
check('result is valid JSON with the original keys intact', \is_array($d) && 1 === $d['a'] && [1, 2] === $d['b']);
check('$comment is the first key and says it is managed', \is_array($d) && '$comment' === array_key_first($d) && str_contains($d['$comment'], 'MANAGED FRAMEWORK FILE'));
check('the rest of the text is untouched', str_contains((string) $r, '"a": 1, "b": [1,2]}'));
$src = "{\n  \"x\": true\n}\n";
$r = ManagedFile::withHeader('m.json', $src);
check('multi-line file stays valid and keeps its trailing newline', null !== json_decode((string) $r) && str_ends_with((string) $r, "\n}\n"));
$r = ManagedFile::withHeader('e.json', '{}');
check('empty object works', null !== $r && isset(json_decode($r, true)['$comment']));
$r = ManagedFile::withHeader('e.json', "  { }\n");
check('empty object with whitespace works', null !== $r && isset(json_decode($r, true)['$comment']));
check('JSON array: no header, not installed', null === ManagedFile::withHeader('l.json', '[1,2]'));
check('invalid JSON: not installed', null === ManagedFile::withHeader('bad.json', '{nope'));
check('file with its own $comment: not installed rather than overridden', null === ManagedFile::withHeader('c.json', '{"$comment": "mine", "a": 1}'));

echo "\nother types\n";
check('yaml gets a # line first', str_starts_with((string) ManagedFile::withHeader('c.yaml', "a: 1\n"), '# MANAGED FRAMEWORK FILE.') && str_ends_with((string) ManagedFile::withHeader('c.yaml', "a: 1\n"), "\na: 1\n"));
check('yml and txt too', null !== ManagedFile::withHeader('c.yml', 'a') && null !== ManagedFile::withHeader('n.txt', 'a'));
check('csv has no safe header: not installed', null === ManagedFile::withHeader('d.csv', 'a,b'));
check('unknown type: not installed', null === ManagedFile::withHeader('x.php', '<?php'));
check('extension is matched case-insensitively', null !== ManagedFile::withHeader('A.MD', 'x'));
check('same input gives the same output', ManagedFile::withHeader('a.md', 'x') === ManagedFile::withHeader('a.md', 'x'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
