#!/usr/bin/env python3
"""
Sanity-check ~/arch-mcp-secrets/tokens.json before running verify-lanes.py.

Run ON THE SERVER. Reports structure only — never prints a token.

    python3 check-tokens.py
"""
import json, os, re, sys

MAP = os.path.expanduser("~/arch-mcp-secrets/tokens.json")
TOKEN_RE = re.compile(r'^[A-Za-z0-9_-]{32,128}$')
LANE_RE = re.compile(r'^[a-z][a-z0-9_]*$')
EXT_RE = re.compile(r'^[a-z0-9]{1,8}$')


def main():
    try:
        with open(MAP) as fh:
            text = fh.read()
    except Exception as e:
        print("Cannot read {}: {}".format(MAP, e)); return 1

    # Top-level duplicate keys. JSON silently keeps the LAST one, so two
    # profiles sharing a key means one vanishes with no error anywhere.
    #
    # object_pairs_hook fires for EVERY object, innermost first, so the
    # ROOT object is the last one it sees. The first version of this check
    # tried to spot nested objects by looking for "label"/"root"/"lanes"
    # keys — which missed the "lanes" objects themselves, so two profiles
    # each having a lane called "site" was reported as a duplicate token.
    # A false alarm on the one check that exists to catch a silent data
    # loss is worse than no check, hence this rewrite.
    records = []

    def hook(pairs):
        records.append(pairs)
        return dict(pairs)

    try:
        data = json.loads(text, object_pairs_hook=hook)
    except ValueError as e:
        print("[FAIL] not valid JSON: {}".format(e)); return 1

    root_keys = [k for k, _ in records[-1]] if records else []
    dupes = sorted({k for k in root_keys if root_keys.count(k) > 1})

    problems = 0
    if dupes:
        problems += 1
        print("[FAIL] DUPLICATE top-level keys: {} — JSON keeps only the LAST.".format(", ".join(dupes)))
        print("       Two profiles sharing a token key means one is silently discarded.")

    print("\nmode: {}".format(oct(os.stat(MAP).st_mode & 0o777)))
    if os.stat(MAP).st_mode & 0o077:
        problems += 1
        print("[FAIL] readable by others — chmod 600")

    profiles = [(k, v) for k, v in data.items() if isinstance(v, dict)]
    print("profiles parsed: {}\n".format(len(profiles)))

    tokens = []
    for key, p in profiles:
        label = p.get("label", "?")
        print("  {}".format(label))
        tokens.append(key)

        if not TOKEN_RE.match(key):
            problems += 1
            print("    [FAIL] token key is not 32-128 chars of [A-Za-z0-9_-] "
                  "(len={}) — will never match".format(len(key)))
        else:
            print("    [ok] token format, length {}".format(len(key)))

        root = p.get("root")
        if not isinstance(root, str) or not os.path.isdir(root):
            problems += 1
            print("    [FAIL] root missing on disk: {}".format(root))
        else:
            entries = [e for e in os.listdir(root) if not e.startswith('.')]
            print("    [ok] root exists: {} ({} visible entries)".format(root, len(entries)))
            if not entries:
                print("    [warn] root has no non-dot files — listings will be empty")

        lanes = p.get("lanes")
        if not isinstance(lanes, dict) or not lanes:
            problems += 1; print("    [FAIL] lanes missing/empty")
        else:
            for name, sub in lanes.items():
                bad = not LANE_RE.match(name) or not isinstance(sub, str) or ".." in sub
                if bad:
                    problems += 1; print("    [FAIL] bad lane {!r}: {!r}".format(name, sub))
                else:
                    target = os.path.join(root, sub) if sub else root
                    ok = os.path.isdir(target)
                    print("    [{}] lane {!r} -> {}".format("ok" if ok else "warn", name, target))
                    if sub == "" and os.path.isdir(os.path.join(root, ".git")):
                        print("        note: lane is a git checkout root; .git/ is inside it")
                        print("        (blocked by the dot-component rule in round 2)")

        exts = p.get("write_extensions")
        if exts is None:
            print("    [ok] write_extensions: unrestricted")
        elif not isinstance(exts, list) or not exts or not all(
                isinstance(e, str) and EXT_RE.match(e) for e in exts):
            problems += 1; print("    [FAIL] write_extensions malformed — profile will be REJECTED")
        else:
            print("    [ok] write_extensions: {} types".format(len(exts)))
        print()

    if len(set(tokens)) != len(tokens):
        problems += 1
        print("[FAIL] two profiles share the same token value")

    print("PROBLEMS: {}".format(problems))
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
