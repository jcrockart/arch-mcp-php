#!/usr/bin/env python3
"""
Mint tokens for every label declared in profiles.json that doesn't have
one yet.

    python3 mint-tokens.py                 # mint any missing tokens
    python3 mint-tokens.py --rotate LABEL   # replace one label's token
    python3 mint-tokens.py --url LABEL      # print a connector URL
    python3 mint-tokens.py --provision LABEL --root ROOT --lanes LANES
                            [--write-extensions ext,ext,...]
                            [--session-tools] [--read-only]
                            [--allow-pull [--pull-branch BRANCH]]
                            [--allow-push [--push-branch BRANCH]]
                                            # declare a brand-new profile in
                                            # profiles.json and mint its
                                            # token in one step
    python3 mint-tokens.py --provision-project SLUG --env staging|prod
                            --lanes LANES [--write-extensions ...]
                            [--session-tools] [--read-only]
                            [--allow-pull [--pull-branch BRANCH]]
                            [--allow-push [--push-branch BRANCH]]
                                            # same, but computes ROOT and
                                            # LABEL from SLUG+ENV per the
                                            # arch-projects convention
                                            # below -- no path/label typing

--allow-pull sets pull_allowed: true on the entry (needs a core lane),
letting this profile call the gated arch_core_git_pull tool -- a
fast-forward-only `git fetch` + `merge --ff-only origin/<branch>`, no
other git write capability, no caller-supplied arguments. --pull-branch
overrides the default target branch ("main") if a project uses another
name. See claude/proposal-arch-mcp-core-pull-lane.md -- applied and live
2026-09-13.

--allow-push sets push_allowed: true on the entry (needs a core lane),
letting this profile call the gated arch_core_git_push tool -- a plain
`git push origin <branch>` (never --force), no other new git write
capability, no caller-supplied branch/ref (the caller does still supply
an expected-HEAD confirmation value -- see the tool's own docs).
--push-branch overrides the default target branch ("main"). See
claude/proposal-arch-mcp-core-push-lane.md -- this profile field is
useless on its own until that repo also has a deploy key on this host
with write access to its GitHub origin; setting push_allowed=true does
not create one. Same silently-dropped-until-the-PHP-side-ships caveat
applied to pull_allowed/pull_branch before that shipped -- if this
document is out of date, check the proposal doc's own status line before
assuming push_allowed does anything live yet.
    python3 mint-tokens.py --apply-state PATH [--force-prune]
                                            # reconcile every Portal-managed
                                            # label to match a full desired
                                            # state document in one call --
                                            # see APPLY-STATE below

--lanes takes a comma-separated list of lane[:subpath] pairs, e.g.
"site" -> {"site": ""}, or "metadata:metadata,site:site,core" for all
three. A "site" or "metadata" lane needs --write-extensions (e.g.
--write-extensions php,css,sql,md) -- there is no safe default allow-list
-- unless --read-only is given, which forces write_extensions to an
explicit empty list ([]) instead: the lane's read/list tools stay
available, every write is refused regardless of extension. That's the
supported way to grant read-only site-lane access (e.g. so something can
browse/diff a production checkout without ever being able to write to
it) -- omitting --write-extensions entirely means "no write lane
declared for this profile at all," which is a different, more implicit
thing than "explicitly read-only."

ARCH-PROJECTS CONVENTION (2026-09-12): real ARCH-governed projects (as
opposed to ARCH-COLLAB's own core-asset repos -- core, mcp, arch-portal,
arch-ops, requests, which stay under arch-collab/arch-collab-staging
exactly as before) live at:

    ~/arch-projects/<slug>            (production checkout)
    ~/arch-projects-staging/<slug>    (staging checkout)

served by default at arch.crockart.com.au/<slug> and
arch-staging.crockart.com.au/<slug> respectively (a project can later
move production to its own <slug>.crockart.com.au -- that only changes
which document root is symlinked at the same checkout, nothing here).
profiles.json labels follow the slug: "<slug>" for the production
profile, "<slug>-staging" for staging -- see --provision-project above,
which encodes exactly this mapping so it never has to be typed by hand.
Both parent directories (~/arch-projects, ~/arch-projects-staging) are a
one-time manual `mkdir` -- this script has never created directories
outside ~/arch-mcp-secrets and doesn't start here either.

APPLY-STATE (2026-09-12): the bridge for arch-portal's admin CRUD screens.
Portal's own database is canonical for *editing* profiles (create, edit,
rotate, delete, through its UI) -- but ArchProfiles.php still only ever
reads profiles.json/tokens.json on this host, never Portal's database.
--apply-state is what keeps the two in sync: Portal calls it, synchronously,
after every CRUD write, passing the full current state of every label IT
manages as a JSON document:

    {
      "actor": "portal:<admin-username>",   -- optional, logged if given
      "profiles": {
        "<label>": {"root": "...", "lanes": {...}, "session_tools": bool,
                     "write_extensions": [...] | null-omitted},
        ...
      },
      "tokens": {
        "<label>": "<the label's current token value>",
        ...
      }
    }

This is NOT "replace profiles.json/tokens.json with exactly this" --
that would let one buggy or empty call from Portal silently delete every
hand-provisioned profile that has nothing to do with it (arch-core-prod,
rcp-dev, and so on). Every entry this command creates or updates is
tagged "managed_by": "arch-portal" internally; on each call, it only
ever touches labels already carrying that tag or newly present in the
supplied state -- everything else in profiles.json/tokens.json is
matched against its prior value and asserted byte-for-byte unchanged
before anything is written. A Portal-managed label that's present before
the call but missing from the new state is treated as a deletion and
requires --force-prune to confirm -- an empty or partial state document
without that flag is refused outright rather than silently pruning
everything Portal thinks it no longer owns.

Every entry is validated the same way --provision already validates one
(absolute root, a site/metadata lane needs write_extensions -- [] is a
valid, explicit "read-only"). Any single entry failing validation aborts
the whole call -- nothing partial is ever written, the same rule
--provision already follows.

Both files are written atomically (temp file in the same directory,
then a single os.replace()) -- true of every write this script makes
now, including --provision/--rotate/plain minting, not just --apply-state.

MOVED 2026-09-12: profiles.json now lives at ~/arch-mcp-secrets/profiles.json,
alongside tokens.json, instead of being git-tracked next to this script.
It was git-tracked from 2026-09-01 to 2026-09-12 so a `git pull` delivered
a code change to ArchProfiles.php and any profiles.json shape it newly
required in one atomic step. That stopped making sense once profile
creation needed to happen without a human pausing to run git for each one
(self-service / automated provisioning, e.g. from arch-portal) --
ArchProfiles.php::validate() already defaults every optional field, so
there is no code/data lockstep left to protect. This script is now the
only thing that edits profiles.json, the same way it was already the
only thing that edits tokens.json -- both live, both outside git.

profiles-changelog.jsonl (same directory) replaces the git history this
move gives up: one JSON line per provision/rotate/mint/apply-state, so
"who added what, when" is still answerable without needing blame on a
file nothing was reviewing per-line anyway.
"""

import json
import os
import secrets
import sys
import tempfile
from datetime import datetime, timezone

SECRETS_DIR = os.path.expanduser("~/arch-mcp-secrets")
PROFILES = os.path.join(SECRETS_DIR, "profiles.json")
MAP = os.path.join(SECRETS_DIR, "tokens.json")
CHANGELOG = os.path.join(SECRETS_DIR, "profiles-changelog.jsonl")
BASE = "https://mcp.crockart.com.au"

PROJECTS_ROOT = os.path.expanduser("~/arch-projects")
PROJECTS_STAGING_ROOT = os.path.expanduser("~/arch-projects-staging")

# The one value this script ever writes into an entry's "managed_by"
# field. --apply-state's prune-safety check is built entirely around
# this tag -- see the module docstring's APPLY-STATE section.
MANAGED_BY = "arch-portal"


def atomic_write(path, content, mode):
    """Write `content` to `path` without ever leaving it partially
    written: write to a temp file in the same directory (so the final
    rename is on the same filesystem, hence atomic) and os.replace() it
    into place. Neither profiles.json nor tokens.json had this before
    2026-09-12 -- both used a plain direct overwrite."""
    directory = os.path.dirname(path)
    os.makedirs(directory, exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(dir=directory, prefix=".tmp-")
    try:
        with os.fdopen(fd, "w") as fh:
            fh.write(content)
        os.chmod(tmp_path, mode)
        os.replace(tmp_path, path)
    except Exception:
        try:
            os.unlink(tmp_path)
        except OSError:
            pass
        raise


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
    text = json.dumps(tokens, indent=2) + "\n"
    old = os.umask(0o077)
    try:
        atomic_write(MAP, text, 0o600)
    finally:
        os.umask(old)


def log_change(action, label, detail=None):
    """Append one line to the change log. Best-effort: a logging failure
    must never block the actual mint/provision/rotate/apply-state it's
    recording."""
    entry = {
        "ts": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "action": action,
        "label": label,
    }
    if detail is not None:
        entry["detail"] = detail
    try:
        os.makedirs(os.path.dirname(CHANGELOG), exist_ok=True)
        with open(CHANGELOG, "a") as fh:
            fh.write(json.dumps(entry) + "\n")
    except OSError as exc:
        print("Warning: couldn't append to {}: {}".format(CHANGELOG, exc))


def token_label(val):
    """tokens.json values are either a bare label string (current shape)
    or a dict with a "label" key (old shape, still tolerated). This is
    the one place both are read the same way."""
    return val if isinstance(val, str) else val.get("label")


def label_for(tokens, label):
    for tok, val in tokens.items():
        if token_label(val) == label:
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
    have = {token_label(v) for v in tokens.values()}
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
    for label in minted:
        log_change("mint", label)
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
    tokens = {t: v for t, v in tokens.items() if token_label(v) != label}
    tokens[secrets.token_hex(32)] = label
    save_tokens(tokens)
    log_change("rotate", label)
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
    if entry.get("pull_allowed"):
        fields.append('{}  "pull_allowed": true'.format(indent))
    if "pull_branch" in entry:
        fields.append('{}  "pull_branch": "{}"'.format(indent, entry["pull_branch"]))
    if entry.get("push_allowed"):
        fields.append('{}  "push_allowed": true'.format(indent))
    if "push_branch" in entry:
        fields.append('{}  "push_branch": "{}"'.format(indent, entry["push_branch"]))
    body = ",\n".join(fields)
    return '{}"{}": {{\n{}\n{}}}'.format(indent, label, body, indent)


def project_root_and_label(slug, env):
    """Map a project slug + environment to (root, label) per the
    arch-projects convention (see module docstring). The one place this
    mapping is expressed -- --provision-project is a thin wrapper around
    provision() using exactly this, so a hand-rolled --provision call can
    still override any part of it if a project ever needs to."""
    if env == "staging":
        return os.path.join(PROJECTS_STAGING_ROOT, slug), "{}-staging".format(slug)
    if env == "prod":
        return os.path.join(PROJECTS_ROOT, slug), slug
    raise ValueError("--env must be 'staging' or 'prod', got {!r}".format(env))


def provision(label, root, lanes, session_tools, write_extensions, read_only=False,
              allow_pull=False, pull_branch=None, allow_push=False, push_branch=None):
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

    if allow_pull and "core" not in lanes:
        print("--allow-pull needs a core lane (e.g. --lanes core:,site:) --")
        print("the pull mechanism runs against the core lane's checkout.")
        return 1

    if allow_push and "core" not in lanes:
        print("--allow-push needs a core lane (e.g. --lanes core:,site:) --")
        print("the push mechanism runs against the core lane's checkout.")
        return 1

    if read_only:
        # Explicit empty list, not "no write_extensions declared" (None).
        # ArchProfiles/ArchTools treat None as *unrestricted* writes and
        # [] as *always refused* -- see extensionAllowed() in
        # ArchTools.php. --read-only exists so that distinction is a
        # named flag instead of something you have to already know.
        write_extensions = []
    elif any(lane in ("site", "metadata") for lane in lanes) and not write_extensions:
        print("A site or metadata lane needs --write-extensions (e.g.")
        print("--write-extensions php,css,sql,md) — there's no safe default.")
        print("Or pass --read-only if this profile should never write at all.")
        return 1

    entry = {"root": root, "lanes": lanes, "session_tools": session_tools}
    if write_extensions is not None:
        entry["write_extensions"] = write_extensions
    if allow_pull:
        entry["pull_allowed"] = True
        if pull_branch and pull_branch != "main":
            entry["pull_branch"] = pull_branch
    if allow_push:
        entry["push_allowed"] = True
        if push_branch and push_branch != "main":
            entry["push_branch"] = push_branch

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

    atomic_write(PROFILES, new_text, 0o644)

    tokens = load_tokens()
    tok = secrets.token_hex(32)
    tokens[tok] = label
    save_tokens(tokens)
    log_change("provision", label, detail=entry)

    print("Added {!r} to {}.".format(label, PROFILES))
    if read_only:
        print("write_extensions is [] — every write tool this profile's")
        print("lanes expose is refused; read/list tools work normally.")
    if allow_pull:
        print("pull_allowed is true — this profile can run the gated")
        print("fast-forward pull against origin/{}.".format(pull_branch or "main"))
    if allow_push:
        print("push_allowed is true — this profile can run the gated push")
        print("against origin/{}, once a deploy key with write access to".format(
            push_branch or "main"))
        print("this repo's GitHub origin is set up on this host. Setting this")
        print("flag alone does not create that key.")
    print("Minted its token and wrote it to {}.".format(MAP))
    print("{}/project/{}/  (send as X-Api-Key: {})".format(BASE, label, tok))
    print("This took effect immediately — profiles.json is no longer")
    print("git-tracked, so there is nothing left to commit or promote for")
    print("this step. Just register the connector with the URL above.")
    return 0


def validate_entry_dict(label, entry):
    """Same rules provision() enforces via its CLI flags, applied to an
    already-assembled entry dict instead -- used by --apply-state, where
    entries come from a JSON document rather than --root/--lanes/etc.
    Returns an error string, or None if the entry is valid."""
    if not isinstance(entry, dict):
        return "{!r}: entry must be an object".format(label)

    root = entry.get("root")
    if not isinstance(root, str) or not root.startswith("/"):
        return "{!r}: root must be an absolute path".format(label)

    lanes = entry.get("lanes")
    if not isinstance(lanes, dict):
        return "{!r}: lanes must be an object".format(label)

    write_extensions = entry.get("write_extensions")
    if write_extensions is not None and not isinstance(write_extensions, list):
        return "{!r}: write_extensions must be a list (or omitted)".format(label)
    if any(lane in ("site", "metadata") for lane in lanes) and write_extensions is None:
        return ("{!r}: a site or metadata lane needs write_extensions -- pass "
                "[] explicitly for read-only, there's no safe default").format(label)

    session_tools = entry.get("session_tools", False)
    if not isinstance(session_tools, bool):
        return "{!r}: session_tools must be a boolean".format(label)

    pull_allowed = entry.get("pull_allowed", False)
    if not isinstance(pull_allowed, bool):
        return "{!r}: pull_allowed must be a boolean".format(label)
    if pull_allowed and "core" not in lanes:
        return "{!r}: pull_allowed needs a core lane".format(label)

    pull_branch = entry.get("pull_branch")
    if pull_branch is not None and (not isinstance(pull_branch, str) or not pull_branch
                                     or pull_branch.startswith("-")):
        return "{!r}: pull_branch must be a non-empty string not starting with '-'".format(label)

    push_allowed = entry.get("push_allowed", False)
    if not isinstance(push_allowed, bool):
        return "{!r}: push_allowed must be a boolean".format(label)
    if push_allowed and "core" not in lanes:
        return "{!r}: push_allowed needs a core lane".format(label)

    push_branch = entry.get("push_branch")
    if push_branch is not None and (not isinstance(push_branch, str) or not push_branch
                                     or push_branch.startswith("-")):
        return "{!r}: push_branch must be a non-empty string not starting with '-'".format(label)

    # "managed_by" is this script's own bookkeeping field -- an incoming
    # entry setting it itself would be spoofing which labels apply-state
    # is allowed to prune later, so it's rejected here rather than
    # silently overwritten.
    allowed_keys = {"root", "lanes", "session_tools", "write_extensions",
                    "pull_allowed", "pull_branch", "push_allowed", "push_branch"}
    extra_keys = set(entry) - allowed_keys
    if extra_keys:
        return "{!r}: unexpected field(s): {}".format(label, ", ".join(sorted(extra_keys)))

    return None


def apply_state(path, force_prune=False):
    try:
        with open(path) as fh:
            desired = json.load(fh)
    except OSError as exc:
        print("Couldn't read {}: {}".format(path, exc))
        return 1
    except json.JSONDecodeError as exc:
        print("Invalid JSON in {}: {}".format(path, exc))
        return 1

    if not isinstance(desired, dict) or "profiles" not in desired or "tokens" not in desired:
        print('Desired-state document must be an object with "profiles" and "tokens" keys.')
        return 1

    desired_profiles = desired["profiles"]
    desired_tokens = desired["tokens"]  # {label: token}
    actor = desired.get("actor")

    if not isinstance(desired_profiles, dict) or not isinstance(desired_tokens, dict):
        print('"profiles" and "tokens" must both be objects.')
        return 1

    profile_labels = set(desired_profiles)
    token_labels = set(desired_tokens)
    if profile_labels != token_labels:
        missing_tokens = profile_labels - token_labels
        missing_profiles = token_labels - profile_labels
        if missing_tokens:
            print("No token supplied for: {}".format(", ".join(sorted(missing_tokens))))
        if missing_profiles:
            print("Token supplied with no matching profile for: {}".format(
                ", ".join(sorted(missing_profiles))))
        print("Aborting -- nothing written.")
        return 1

    for label, entry in desired_profiles.items():
        error = validate_entry_dict(label, entry)
        if error:
            print("Validation failed: {}".format(error))
            print("Aborting -- nothing written.")
            return 1
        token = desired_tokens[label]
        if not isinstance(token, str) or len(token) < 32:
            print("{!r}: token looks wrong -- expected a long hex string.".format(label))
            print("Aborting -- nothing written.")
            return 1

    current_profiles = load_profiles()
    current_tokens = load_tokens()

    currently_managed = {
        label for label, entry in current_profiles.items()
        if isinstance(entry, dict) and entry.get("managed_by") == MANAGED_BY
    }

    to_remove = currently_managed - profile_labels
    to_upsert = profile_labels

    if to_remove and not force_prune:
        print("This call would remove {} Portal-managed profile(s) not present".format(len(to_remove)))
        print("in the new state: {}".format(", ".join(sorted(to_remove))))
        print("Pass --force-prune to confirm removals. Aborting -- nothing written.")
        return 1

    new_profiles = dict(current_profiles)
    for label in to_remove:
        del new_profiles[label]
    for label in to_upsert:
        entry = dict(desired_profiles[label])
        entry["managed_by"] = MANAGED_BY
        new_profiles[label] = entry

    # The actual safety guarantee: every label this call doesn't own is
    # provably unaffected -- not "probably," checked.
    untouched = set(current_profiles) - to_remove - to_upsert
    for label in untouched:
        assert new_profiles[label] == current_profiles[label], (
            "apply-state would have altered {!r}, which it doesn't manage "
            "-- aborting, nothing written".format(label)
        )

    touched_labels = to_remove | to_upsert
    new_tokens = {tok: val for tok, val in current_tokens.items()
                  if token_label(val) not in touched_labels}
    for label in to_upsert:
        new_tokens[desired_tokens[label]] = label

    profiles_text = json.dumps(new_profiles, indent=2, sort_keys=True) + "\n"
    reparsed_profiles = json.loads(profiles_text)
    assert reparsed_profiles == new_profiles, \
        "profiles.json round-trip mismatch -- aborting, nothing written"

    tokens_text = json.dumps(new_tokens, indent=2) + "\n"
    reparsed_tokens = json.loads(tokens_text)
    assert reparsed_tokens == new_tokens, \
        "tokens.json round-trip mismatch -- aborting, nothing written"

    atomic_write(PROFILES, profiles_text, 0o644)
    old = os.umask(0o077)
    try:
        atomic_write(MAP, tokens_text, 0o600)
    finally:
        os.umask(old)

    for label in sorted(to_remove):
        log_change("apply-state-remove", label, detail={"actor": actor})
    for label in sorted(to_upsert):
        log_change("apply-state-upsert", label, detail={"actor": actor, "entry": new_profiles[label]})

    print("Applied state: {} upserted, {} removed.".format(len(to_upsert), len(to_remove)))
    if to_upsert:
        print("Upserted: {}".format(", ".join(sorted(to_upsert))))
    if to_remove:
        print("Removed: {}".format(", ".join(sorted(to_remove))))
    return 0


if __name__ == "__main__":
    args = sys.argv[1:]

    def opt(flag, default=None):
        return args[args.index(flag) + 1] if flag in args else default

    if "--apply-state" in args:
        state_path = opt("--apply-state")
        if not state_path:
            print("--apply-state needs a path to a JSON desired-state document.")
            sys.exit(1)
        sys.exit(apply_state(state_path, force_prune="--force-prune" in args))
    if "--provision-project" in args:
        slug = opt("--provision-project")
        env = opt("--env")
        lanes_spec = opt("--lanes")
        if not slug or not env or not lanes_spec:
            print("--provision-project needs a slug, --env (staging or prod),")
            print("and --lanes (e.g. --lanes core:,site:).")
            sys.exit(1)
        try:
            root, label = project_root_and_label(slug, env)
        except ValueError as exc:
            print(str(exc))
            sys.exit(1)
        write_ext_spec = opt("--write-extensions")
        write_extensions = write_ext_spec.split(",") if write_ext_spec else None
        session_tools = "--session-tools" in args
        read_only = "--read-only" in args
        allow_pull = "--allow-pull" in args
        pull_branch = opt("--pull-branch")
        allow_push = "--allow-push" in args
        push_branch = opt("--push-branch")
        sys.exit(provision(label, root, parse_lanes(lanes_spec), session_tools,
                            write_extensions, read_only=read_only,
                            allow_pull=allow_pull, pull_branch=pull_branch,
                            allow_push=allow_push, push_branch=push_branch))
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
        read_only = "--read-only" in args
        allow_pull = "--allow-pull" in args
        pull_branch = opt("--pull-branch")
        allow_push = "--allow-push" in args
        push_branch = opt("--push-branch")
        sys.exit(provision(label, root, parse_lanes(lanes_spec), session_tools,
                            write_extensions, read_only=read_only,
                            allow_pull=allow_pull, pull_branch=pull_branch,
                            allow_push=allow_push, push_branch=push_branch))
    if "--url" in args:
        sys.exit(show_url(opt("--url")))
    if "--rotate" in args:
        sys.exit(rotate(opt("--rotate")))
    sys.exit(mint_missing())
