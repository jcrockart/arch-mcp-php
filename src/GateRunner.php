<?php

namespace ArchMcp;

require_once __DIR__.'/MetadataValidator.php';

/**
 * Runs a lane's gate steps, in order, from the project root. The first step
 * that fails stops the run: nothing after it runs and nothing is changed.
 *
 * The transcript keeps the layout arch.py printed ("Validating session ...",
 * "-- gate: NAME", "COMMIT ABORTED at gate ..."), because CommitOutput reads
 * those lines to shorten the report.
 */
final class GateRunner
{
    /**
     * @param list<array{name: string, builtin?: string, cmd?: list<string>}> $steps
     *
     * 'output' is the readable transcript, each step's text under its "-- gate:"
     * line; 'steps' is what each step that ran printed.
     *
     * @return array{ok: bool, failedStep: ?string, output: string, steps: list<array{name: string, text: string}>}
     */
    public static function run(string $repoPath, array $steps, string $branch, string $lane): array
    {
        $out = "Validating session '{$branch}' (lane: {$lane}) before commit...\n\n";
        $ran = [];

        foreach ($steps as $step) {
            $out .= "-- gate: {$step['name']}\n";
            [$passed, $text] = self::runStep($repoPath, $step);
            if ('' !== $text && !str_ends_with($text, "\n")) {
                $text .= "\n";
            }
            $ran[] = ['name' => $step['name'], 'text' => $text];
            $out .= $text;
            if (!$passed) {
                $out .= "\nCOMMIT ABORTED at gate '{$step['name']}'. Branch '{$branch}' remains open and unchanged. main is untouched.\n";
                $out .= "Fix the errors above and run arch_session_commit again, or arch_session_discard.\n";

                return ['ok' => false, 'failedStep' => $step['name'], 'output' => $out, 'steps' => $ran];
            }
        }

        return ['ok' => true, 'failedStep' => null, 'output' => $out, 'steps' => $ran];
    }

    /**
     * The transcript of a passing gate in the layout CommitOutput::compact
     * reads: everything the steps printed first, then the "Validating session"
     * line and one "-- gate:" line per step (the order arch.py's output had).
     *
     * @param list<array{name: string, text: string}> $ran
     */
    public static function passTranscript(array $ran, string $branch, string $lane): string
    {
        $out = '';
        foreach ($ran as $r) {
            $out .= $r['text'];
        }
        $out .= "Validating session '{$branch}' (lane: {$lane}) before commit...\n\n";
        foreach ($ran as $r) {
            $out .= "-- gate: {$r['name']}\n";
        }

        return $out;
    }

    /**
     * Runs the built-in validator and nothing else: what a commit's validate
     * step would say, without committing.
     *
     * @return array{ok: bool, output: string}
     */
    public static function validateOnly(string $repoPath): array
    {
        $r = MetadataValidator::run($repoPath);

        return ['ok' => $r['ok'], 'output' => $r['output']];
    }

    /**
     * @param array{name: string, builtin?: string, cmd?: list<string>} $step
     *
     * @return array{0: bool, 1: string} passed, and what the step printed
     */
    private static function runStep(string $repoPath, array $step): array
    {
        if (isset($step['builtin'])) {
            $r = MetadataValidator::run($repoPath);

            return [$r['ok'], $r['output']];
        }

        $cmd = $step['cmd'] ?? [];
        $proc = @proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $repoPath,
        );
        if (!\is_resource($proc)) {
            return [false, 'could not start: '.implode(' ', $cmd)."\n"];
        }
        $text = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);

        return [0 === $code, $text];
    }
}
