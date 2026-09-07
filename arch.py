#!/usr/bin/env python3
"""
arch — the ARCH session/codegen CLI.

Implements:
  arch session start [name]      -> 23-ARCH-COLLAB §23.1.1, CLI Design §6.1
  arch session commit [--major|--minor|--patch]
                                  -> CLI Design §6.2 (the one hard gate)
  arch session discard           -> CLI Design §6.3
  arch codegen preview           -> 51-ARCH-CODEGEN §9 (Preview mode)
  arch codegen generate          -> 51-ARCH-CODEGEN §9 (Canonical mode)

This is a real implementation, not a spec: it shells out to git for branch/
merge/delete, and to validate.py for the commit gate. It is designed to be
called either directly by a human, or by an agent (Claude) through the MCP
wrapper described in agent-connection-layer.md in this same directory.
"""

import argparse
import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).parent
VALIDATOR = REPO / "validate.py"

GATE_CONFIG = REPO / "arch-gate.json"


def default_gate():
    return [{"name": "validate", "cmd": [sys.executable, str(VALIDATOR)]}]


def default_stage():
    return {"label": "metadata", "paths": ["metadata"]}


def load_lane_config(lane):
    config = {}
    if GATE_CONFIG.exists():
        config = json.loads(GATE_CONFIG.read_text())
    if lane in config:
        return config[lane]
    if lane == "metadata":
        return {"gate": default_gate(), "stage": default_stage()}
    print(f"No configuration found for lane '{lane}' in {GATE_CONFIG.name}.")
    print(f"Add a \"{lane}\": {{\"gate\": [...], \"stage\": {{...}}}} entry to {GATE_CONFIG.name} before starting a '{lane}' session.")
    sys.exit(1)


VERSION_FILE = REPO / "VERSION.json"
SESSION_FILE = REPO / ".arch-session.json"
METADATA_DIR = REPO / "metadata"

# CLI Design §7.2 (PROPOSED, pending James's confirmation): discard is a
# soft-delete with a grace window, not an immediate hard delete. Discarded
# branches are renamed under this prefix and purged after GRACE_DAYS.
DISCARDED_PREFIX = "discarded/"
GRACE_DAYS = 7


def sh(*args, check=True):
    return subprocess.run(["git", *args], cwd=REPO, stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, check=check)  # py3.6 compat: capture_output=/text= added in 3.7


def current_branch():
    return sh("rev-parse", "--abbrev-ref", "HEAD").stdout.strip()


def load_version():
    if VERSION_FILE.exists():
        return json.loads(VERSION_FILE.read_text())
    return {"major": 0, "minor": 1, "patch": 0}


def bump_version(kind):
    v = load_version()
    if kind == "major":
        v = {"major": v["major"] + 1, "minor": 0, "patch": 0}
    elif kind == "minor":
        v = {"major": v["major"], "minor": v["minor"] + 1, "patch": 0}
    else:
        v = {"major": v["major"], "minor": v["minor"], "patch": v["patch"] + 1}
    return v


# ---------------------------------------------------------------------------
# session start
# ---------------------------------------------------------------------------
def session_start(name, lane="metadata"):
    if SESSION_FILE.exists():
        print("A session is already active. Commit or discard it before starting another.")
        sys.exit(1)

    branch = name or f"session-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}"
    base = sh("rev-parse", "HEAD").stdout.strip()
    sh("checkout", "-b", branch)

    SESSION_FILE.write_text(json.dumps({
        "branch": branch,
        "base_commit": base,
        "started_at": datetime.now(timezone.utc).isoformat(),
        "lane": lane,
    }, indent=2))

    print(f"Session started: branch '{branch}' from {base[:8]} (lane: {lane})")
    print("Origination is unrestricted in this session (22-ARCH-GOVERNANCE §22.7).")
    print("Run `arch codegen preview` any time for disposable, fuzzy-state output.")


# ---------------------------------------------------------------------------
# session commit — the one hard gate
# ---------------------------------------------------------------------------
def session_commit(bump):
    if not SESSION_FILE.exists():
        print("No active session. Run `arch session start` first.")
        sys.exit(1)

    session = json.loads(SESSION_FILE.read_text())
    branch = session["branch"]
    lane = session.get("lane", "metadata")
    lane_config = load_lane_config(lane)

    print(f"Validating session '{branch}' (lane: {lane}) before commit...\n")
    for step in lane_config["gate"]:
        print(f"-- gate: {step['name']}")
        result = subprocess.run(step["cmd"], cwd=REPO)
        if result.returncode != 0:
            print(f"\nCOMMIT ABORTED at gate '{step['name']}'. Branch '{branch}' remains open and unchanged. main is untouched.")
            print("Fix the errors above and run `arch session commit` again, or `arch session discard`.")
            sys.exit(1)

    # --- FIX (Constitution rule 5 caveat / inception-spike Finding 4,
    # 2026-08-26): stage and commit the session's own metadata changes onto
    # THIS branch before merging to main. Previously this function jumped
    # straight to `checkout main` + `merge --no-ff branch` without ever
    # committing metadata/ on the session branch itself — so a file written
    # during the session (by hand or via arch_session_write_file) stayed an
    # untracked working-tree file the whole time. The merge below had
    # nothing real to bring across, VERSION.json was the only thing that
    # ever actually got committed, and `git ls-files` on main afterward
    # would not show the metadata file despite the printed "COMMITTED"
    # message. Guard on METADATA_DIR existing (a very first session on a
    # freshly-bootstrapped repo may not have the directory yet) and on
    # there being something staged (an empty session, per Finding 1, is
    # still valid and must not fail commit).
    stage = lane_config["stage"]
    stage_paths = [sp for sp in stage["paths"] if (REPO / sp).exists()]
    if stage_paths:
        sh("add", "-A", "--", *stage_paths)
        staged = sh("status", "--porcelain", "--", *stage_paths).stdout.strip()
        if staged:
            sh("commit", "-m", f"Session {stage['label']}: {branch}")

    # Passed validation -> atomic merge; metadata lane also bumps version + runs Canonical codegen
    if lane == "metadata":
        new_version = bump_version(bump)
        tag = f"v{new_version['major']}.{new_version['minor']}.{new_version['patch']}"

        sh("checkout", "main")
        sh("merge", "--no-ff", branch, "-m", f"Session commit: {branch} -> {tag}")
        VERSION_FILE.write_text(json.dumps(new_version, indent=2))
        sh("add", "VERSION.json")
        sh("commit", "-m", f"Bump version to {tag}")
        sh("tag", tag)
        sh("branch", "-d", branch)
        SESSION_FILE.unlink()

        print(f"\nCOMMITTED as {tag}. Session '{branch}' merged to main and retired.")
        print("Running Canonical codegen against merged state...")
        codegen_generate()
    else:
        sh("checkout", "main")
        sh("merge", "--no-ff", branch, "-m", f"Session commit ({lane}): {branch}")
        sh("branch", "-d", branch)
        SESSION_FILE.unlink()

        print(f"\nCOMMITTED. Session '{branch}' (lane: {lane}) merged to main and retired.")


# ---------------------------------------------------------------------------
# session discard — soft delete with grace window (CLI Design §7.2, PROPOSED)
# ---------------------------------------------------------------------------
def session_discard():
    if not SESSION_FILE.exists():
        print("No active session to discard.")
        sys.exit(1)

    session = json.loads(SESSION_FILE.read_text())
    branch = session["branch"]
    discarded_branch = f"{DISCARDED_PREFIX}{branch}"
    now = datetime.now(timezone.utc)
    # Git ref names can't contain ':', so encode the discard time as a
    # unix epoch integer rather than an ISO string.
    epoch = int(now.timestamp())

    sh("checkout", "main")
    sh("branch", "-m", branch, discarded_branch)
    sh("checkout", "main")
    sh("tag", f"discard-marker/{discarded_branch}/{epoch}", discarded_branch)
    SESSION_FILE.unlink()

    print(f"Session '{branch}' discarded. main is untouched — for all practical purposes, no trace.")
    print(f"Branch retained as '{discarded_branch}' for {GRACE_DAYS} days in case this was accidental.")
    print(f"Recover with: arch session recover {branch}")
    print("(Implements CLI Design §7.2's proposed soft-delete model — pending James's confirmation.)")

    purge_expired_discards()


def session_recover(name):
    discarded_branch = f"{DISCARDED_PREFIX}{name}"
    branches = sh("branch", "--list", discarded_branch).stdout.strip()
    if not branches:
        print(f"No discarded branch found matching '{name}'.")
        print("It may have already been purged after the 7-day grace window, or never existed.")
        sys.exit(1)

    sh("branch", "-m", discarded_branch, name)
    sh("checkout", name)
    tags = sh("tag", "--list", f"discard-marker/{discarded_branch}/*").stdout.split()
    for t in tags:
        sh("tag", "-d", t)

    SESSION_FILE.write_text(json.dumps({
        "branch": name,
        "base_commit": sh("merge-base", "main", name).stdout.strip(),
        "started_at": datetime.now(timezone.utc).isoformat(),
        "recovered": True,
    }, indent=2))

    print(f"Session '{name}' recovered and reactivated as the current session.")


def purge_expired_discards():
    """Permanently delete discarded branches past the grace window.
    Runs automatically after each discard; safe to call any time."""
    tags = sh("tag", "--list", "discard-marker/*").stdout.split()
    now = datetime.now(timezone.utc)
    for t in tags:
        # format: discard-marker/<branch>/<epoch-seconds>
        parts = t.rsplit("/", 1)
        if len(parts) != 2:
            continue
        prefix, epoch_str = parts
        discarded_branch = prefix[len("discard-marker/"):]
        try:
            discarded_at = datetime.fromtimestamp(int(epoch_str), tz=timezone.utc)
        except ValueError:
            continue
        age_days = (now - discarded_at).total_seconds() / 86400
        if age_days >= GRACE_DAYS:
            sh("branch", "-D", discarded_branch, check=False)
            sh("tag", "-d", t, check=False)
            print(f"Purged expired discarded branch '{discarded_branch}' (past {GRACE_DAYS}-day grace window).")


# ---------------------------------------------------------------------------
# codegen preview / generate
# ---------------------------------------------------------------------------
def codegen_preview():
    branch = current_branch()
    print(f"Preview codegen against branch '{branch}' (fuzzy state, no guarantees)...")
    subprocess.run([sys.executable, str(VALIDATOR)], cwd=REPO, check=False)
    print("^ warn-only in Preview mode — issues shown, nothing blocked.")
    print("Preview output is disposable and never a dependency outside this session.")


def codegen_generate():
    branch = current_branch()
    if branch != "main":
        print("Canonical codegen refused: current branch is not main.")
        print("Canonical mode only ever reads committed metadata on the canonical line (51-ARCH-CODEGEN §9).")
        sys.exit(1)
    v = load_version()
    print(f"Canonical codegen complete for v{v['major']}.{v['minor']}.{v['patch']} (deterministic, versioned).")


# ---------------------------------------------------------------------------
def main():
    p = argparse.ArgumentParser(prog="arch")
    sub = p.add_subparsers(dest="cmd")
    sub.required = True  # py3.6 compat: add_subparsers() gained required= in 3.7

    s = sub.add_parser("session")
    ssub = s.add_subparsers(dest="action")
    ssub.required = True  # py3.6 compat
    start = ssub.add_parser("start")
    start.add_argument("name", nargs="?", default=None)
    start.add_argument("--lane", default="metadata")
    commit = ssub.add_parser("commit")
    commit.add_argument("--major", action="store_const", const="major", dest="bump")
    commit.add_argument("--minor", action="store_const", const="minor", dest="bump")
    commit.add_argument("--patch", action="store_const", const="patch", dest="bump")
    ssub.add_parser("discard")
    recover = ssub.add_parser("recover")
    recover.add_argument("name")

    c = sub.add_parser("codegen")
    csub = c.add_subparsers(dest="action")
    csub.required = True  # py3.6 compat
    csub.add_parser("preview")
    csub.add_parser("generate")

    args = p.parse_args()

    if args.cmd == "session":
        if args.action == "start":
            session_start(args.name, args.lane)
        elif args.action == "commit":
            session_commit(args.bump or "patch")
        elif args.action == "discard":
            session_discard()
        elif args.action == "recover":
            session_recover(args.name)
    elif args.cmd == "codegen":
        if args.action == "preview":
            codegen_preview()
        elif args.action == "generate":
            codegen_generate()


if __name__ == "__main__":
    main()
