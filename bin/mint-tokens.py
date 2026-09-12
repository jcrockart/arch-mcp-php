#!/usr/bin/env python3
"""
Mint tokens for every label declared in profiles.json that doesn't have
one yet.

    python3 mint-tokens.py                 # mint any missing tokens
    python3 mint-tokens.py --rotate LABEL   # replace one label's token
    python3 mint-tokens.py --url LABEL      # print a connector URL
    python3 mint-tokens.py --provision LABEL --root ROOT --lanes LANES
                            [--write-extensions ext,ext,...] [--session-tools]
                                            # declare a brand-new profile in
                                            # profiles.json and mint its
                                            # token in one step

--lanes takes a comma-separated list of lane[:subpath] pairs, e.g.
"site" -> {"site": ""}, or "metadata:metadata,site:site,core" for all
three. A "site" or "metadata" lane needs --write-extensions (e.g.
--write-extensions php,css,sql,md) -- there is no safe default allow-list.

profiles.json (git-tracked, alongside this script) is the only
declaration of what a label is allowed to touch. This script's job is
secrets (which labels have a token, minting one for a label that
doesn't) plus, now, adding the profiles.json entry itself for a new
label so provisioning a new profile is one command instead of a hand
edit followed by a mint run.
"""

import json
import os
import secrets
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROFILES = os.path.join(REPO_ROOT, "profiles.json")
MAP = os.path.expanduser("~/arch-mcp-secrets/tokens.json")
BASE = "https://mcp.crockart.com.au"


def load_profiles():
    with open(PROFILES) as fh:
        return json.load(fh)


def load_tokens():
    if not os.path.exists(MAP):
        return {}
    with open(MAP) as fh:
        return json.load(fh)


def save_tokens(tokens):
    os.makedirs(os.path.dirname(MAP), exist_ok=True)
    os.chmod(os.path.dirname(MAP), 0o700)
    old = os.umask(0o077)
    try:
        with open(MAP, "w") as fh:
            json.dump(tokens, fh, indent=2)
            fh.write("\n")
    finally:
        os.umask(old)
    os.chmod(MAP, 0o600)


def label_for(tokens, label):
    for tok, val in tokens.items():
        if val == label or (isinstance(val, dict) and val.get("label") == label):
            return tok
    return None


def show_url(label):
    tokens = load_tokens()
    tok = label_for(tokens, label)
    if tok is None:
        print("No token for label {!r} yet.".format(label))
        return 1
    print("{}/project/{}/  (send as X-Api-Key: {})".format(BASE, label, tok))
    return 0


def mint_missing():
    profiles = load_profiles()
    tokens = load_tokens()
    have = {v if isinstance(v, str) else v.get("label") for v in tokens.values()}
    minted = []
    for label in profiles:
        if label in have:
            continue
        tok = secrets.token_hex(32)
        tokens[tok] = label
        minted.append(label)
    if not minted:
        print("Every profile in profiles.json already has a token.")
        return 0
    save_tokens(tokens)
    print("Minted tokens for: {}".format(", ".join(sorted(minted))))
    print("Register each new connector now — the token was just written to")
    print("{}, never printed above.".format(MAP))
    return 0


def rotate(label):
    profiles = load_profiles()
    if label not in profiles:
        print("{!r} is not declared in {}.".format(label, PROFILES))
        return 1
    tokens = load_tokens()
    tokens = {t: v for t, v in tokens.items()
              if not (v == label or (isinstance(v, dict) and v.get("label") == label))}
    tokens[secrets.token_hex(32)] = label
    save_tokens(tokens)
    print("Rotated {}'s token. Its connector must be re-registered; every".format(label))
    print("other label's token is untouched.")
    return 0


def parse_lanes(spec):
    lanes = {}
    for part in spec.split(","):
        part = part.strip()
        if not part:
            continue
        if ":" in part:
            name, sub = part.split(":", 1)
        else:
            name, sub = part, ""
        lanes[name] = sub
    return lanes


def format_lanes(lanes):
    inner = ", ".join('"{}": "{}"'.format(k, v) for k, v in lanes.items())
    return "{{ {} }}".format(inner) if inner else "{}"


def format_write_extensions(exts, indent):
    single = "[{}]".format(", ".join('"{}"'.format(e) for e in exts))
    if len(indent) + len('"write_extensions": ') + len(single) <= 78:
        return single
    lines = ",\n".join('{}  "{}"'.format(indent, e) for e in exts)
    return "[\n{}\n{}]".format(lines, indent)


def format_entry(label, entry):
    indent = "  "
    fields = [
        '{}  "root": "{}"'.format(indent, entry["root"]),
        '{}  "lanes": {}'.format(indent, format_lanes(entry["lanes"])),
        '{}  "session_tools": {}'.format(indent, json.dumps(entry.get("session_tools", False))),
    ]
    if "write_extensions" in entry:
        fields.append('{}  "write_extensions": {}'.format(
            indent, format_write_extensions(entry["write_extensions"], indent + "  ")))
    body = ",\n".join(fields)
    return '{}"{}": {{\n{}\n{}}}'.format(indent, label, body, indent)


def provision(label, root, lanes, session_tools, write_extensions):
    with open(PROFILES) as fh:
        raw = fh.read()
    profiles = json.loads(raw)

    if label in profiles:
        print("{!r} already exists in {}.".format(label, PROFILES))
        print("Use --rotate to reissue its token, or edit profiles.json by")
        print("hand if its access needs to change.")
        return 1

    if not root.startswith("/"):
        print("--root should be an absolute path (e.g. /home/crockart/...).")
        return 1

    if any(lane in ("site", "metadata") for lane in lanes) and not write_extensions:
        print("A site or metadata lane needs --write-extensions (e.g.")
        print("--write-extensions php,css,sql,md) — there's no safe default.")
        return 1

    entry = {"root": root, "lanes": lanes, "session_tools": session_tools}
    if write_extensions:
        entry["write_extensions"] = write_extensions

    if not os.path.isdir(root):
        print("Warning: {} doesn't exist on disk yet.".format(root))
        print("ArchProfiles fails closed on a missing root, so this profile")
        print("will 404 until the directory exists.")

    text = raw.rstrip()
    assert text.endswith("}"), "profiles.json doesn't end with a closing brace as expected"
    insert_at = text.rfind("\n}")
    assert insert_at != -1, "couldn't find profiles.json's closing brace"
    body, closing = text[:insert_at], text[insert_at:]
    new_text = body + ",\n" + format_entry(label, entry) + closing + "\n"

    reparsed = json.loads(new_text)
    assert label in reparsed and reparsed[label] == entry, \
        "rewritten profiles.json didn't round-trip the new entry correctly — aborting, nothing written"
    assert set(reparsed) == set(profiles) | {label}, \
        "rewritten profiles.json lost or gained an unrelated profile — aborting, nothing written"

    with open(PROFILES, "w") as fh:
        fh.write(new_text)

    tokens = load_tokens()
    tok = secrets.token_hex(32)
    tokens[tok] = label
    save_tokens(tokens)

    print("Added {!r} to {}.".format(label, PROFILES))
    print("Minted its token and wrote it to {}.".format(MAP))
    print("{}/project/{}/  (send as X-Api-Key: {})".format(BASE, label, tok))
    print("Review and commit profiles.json on this checkout, then register")
    print("the connector with the URL above.")
    return 0


if __name__ == "__main__":
    args = sys.argv[1:]

    def opt(flag, default=None):
        return args[args.index(flag) + 1] if flag in args else default

    if "--provision" in args:
        label = opt("--provision")
        root = opt("--root")
        lanes_spec = opt("--lanes")
        if not label or not root or not lanes_spec:
            print("--provision needs a label, --root, and --lanes (e.g.")
            print("--lanes site or --lanes metadata:metadata,site:site,core).")
            sys.exit(1)
        write_ext_spec = opt("--write-extensions")
        write_extensions = write_ext_spec.split(",") if write_ext_spec else None
        session_tools = "--session-tools" in args
        sys.exit(provision(label, root, parse_lanes(lanes_spec), session_tools, write_extensions))
    if "--url" in args:
        sys.exit(show_url(opt("--url")))
    if "--rotate" in args:
        sys.exit(rotate(opt("--rotate")))
    sys.exit(mint_missing())
