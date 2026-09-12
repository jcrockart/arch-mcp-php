#!/usr/bin/env python3
"""
Mint tokens for every label declared in profiles.json that doesn't have
one yet.

    python3 mint-tokens.py                 # mint any missing tokens
    python3 mint-tokens.py --rotate LABEL   # replace one label's token
    python3 mint-tokens.py --url LABEL      # print a connector URL

No PROFILES list here any more — profiles.json (git-tracked, alongside
this script) is the only declaration of what a label is allowed to touch.
This script's only job is secrets: which labels have a token, and minting
one for a label that doesn't.
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


if __name__ == "__main__":
    args = sys.argv[1:]
    if "--url" in args:
        sys.exit(show_url(args[args.index("--url") + 1]))
    if "--rotate" in args:
        sys.exit(rotate(args[args.index("--rotate") + 1]))
    sys.exit(mint_missing())
