<?php

namespace ArchMcp;

/**
 * Shortens the text a successful session commit returns, added 2026-10-08.
 *
 * A passing commit used to hand back every line of every test file in the
 * gate (hundreds of [PASS] lines) before the three lines that matter: which
 * gates ran, and COMMITTED. That is pure context cost for the chat reading
 * it. compact() keeps what a person needs after a good commit and drops the
 * rest: one line with the number of checks that passed, then everything from
 * "Validating session" on, with the "-- gate:" lines joined into one line.
 *
 * It only ever shortens a SUCCESSFUL commit. A failed commit is returned
 * exactly as arch.py printed it, because the failing check's output is the
 * whole point. Text it does not recognise is returned unchanged.
 */
final class CommitOutput
{
    /**
     * @param string $stdout what arch.py printed for `session commit`
     */
    public static function compact(string $stdout): string
    {
        $marker = strpos($stdout, 'Validating session');
        if (false === $marker || false === strpos($stdout, 'COMMITTED')) {
            return $stdout;
        }
        $before = substr($stdout, 0, $marker);
        $tail = substr($stdout, $marker);

        $passed = 0;
        $failed = 0;
        if (preg_match_all('/^(\d+) passed, (\d+) failed\s*$/m', $before, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $passed += (int) $row[1];
                $failed += (int) $row[2];
            }
        }
        // A failure line anywhere means this is not a clean pass: show it all.
        if ($failed > 0 || false !== strpos($before, '[FAIL]')) {
            return $stdout;
        }

        $gates = [];
        $kept = [];
        foreach (explode("\n", $tail) as $line) {
            if (1 === preg_match('/^-- gate: (.+)$/', $line, $g)) {
                $gates[] = trim($g[1]);
                continue;
            }
            if ('' === trim($line)) {
                continue;
            }
            $kept[] = $line;
        }

        $out = [];
        if ($passed > 0) {
            $out[] = "Checks: {$passed} passed, 0 failed.";
        }
        // Keep "Validating ..." first, then the gate list, then the rest.
        $first = array_shift($kept);
        if (null !== $first) {
            $out[] = $first;
        }
        if ([] !== $gates) {
            $out[] = 'Gates passed ('.\count($gates).'): '.implode(', ', $gates);
        }

        return implode("\n", array_merge($out, $kept))."\n";
    }
}
