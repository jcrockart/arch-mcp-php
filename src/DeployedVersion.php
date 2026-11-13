<?php

namespace ArchMcp;

/**
 * What is deployed in a checkout, for arch_project_info (ARCH-83): the version
 * and when it arrived, so a chat can tell whether production runs the change it
 * just made without asking the owner.
 *
 * Worked out from the checkout alone (git plus VERSION.json), so no Portal field
 * is needed. Reported:
 *   version         VERSION.json "version" when the file exists, else the newest tag at HEAD
 *   tag             the tag at HEAD, or null
 *   head            short commit id
 *   committed_at    when HEAD was committed (ISO 8601)
 *   checked_out_at  when the checkout's HEAD last moved (a pull or fast-forward on
 *                   production, so the best available "promoted at"), from the HEAD reflog
 * Read-only; fails soft to {"available": false}.
 */
final class DeployedVersion
{
    /**
     * @return array<string, mixed>
     */
    public static function forCheckout(string $dir): array
    {
        if ('' === $dir || !is_dir($dir)) {
            return ['available' => false];
        }
        $head = self::git($dir, ['rev-parse', '--short', 'HEAD']);
        if (null === $head) {
            return ['available' => false];
        }
        $tag = self::git($dir, ['describe', '--tags', '--exact-match', 'HEAD']);
        $version = null;
        $file = $dir.'/VERSION.json';
        if (is_file($file)) {
            $v = json_decode((string) file_get_contents($file), true);
            if (\is_array($v) && isset($v['version']) && \is_string($v['version'])) {
                $version = $v['version'];
            }
        }
        $committed = self::git($dir, ['log', '-1', '--format=%cI', 'HEAD']);
        $moved = self::git($dir, ['reflog', '-1', '--format=%cI', 'HEAD']);

        return [
            'available' => true,
            'version' => $version ?? $tag,
            'tag' => $tag,
            'head' => $head,
            'committed_at' => $committed,
            'checked_out_at' => $moved,
        ];
    }

    /**
     * @param list<string> $args
     */
    private static function git(string $dir, array $args): ?string
    {
        $p = @proc_open(array_merge(['git', '-C', $dir], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['GIT_TERMINAL_PROMPT' => '0', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp']);
        if (!\is_resource($p)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $t = \is_string($out) ? trim($out) : '';

        return 0 === proc_close($p) && '' !== $t ? $t : null;
    }
}
