#!/bin/bash
# Install files from ~/arch-mcp-staging into the live MCP app, with review.
#
#   bash ~/bin/deploy-from-staging.sh            # diff + confirm + install
#   bash ~/bin/deploy-from-staging.sh --diff     # show the diff and stop
#
# THIS FILE MUST LIVE IN ~/bin AND NEVER INSIDE ~/arch-mcp-staging.
# The staging directory is agent-writable. If the deployer were reachable
# from there, an agent could rewrite the thing that reviews its own
# changes and the gate would be decorative.
#
# Staging holds inert bytes: it is outside every web root, on no include
# path, and nothing there is ever executed. Making them live is this
# script, run by a human, after reading a diff.

set -euo pipefail

APP="${ARCH_MCP_APP:-$HOME/arch-mcp-php}"
STAGE="${ARCH_MCP_STAGE:-$HOME/arch-mcp-staging}"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP="$HOME/arch-mcp-backups/$STAMP"
DIFF_ONLY=0
[ "${1:-}" = "--diff" ] && DIFF_ONLY=1

[ -d "$APP/src" ]  || { echo "ERROR: $APP/src not found"; exit 1; }
[ -d "$STAGE" ]    || { echo "ERROR: $STAGE not found"; exit 1; }

# Only these prefixes may be installed. Anything else staged is reported
# and skipped — a new file appearing somewhere unexpected is exactly the
# thing a human should notice rather than silently deploy.
ALLOWED_PREFIXES="src/ public/ tests/"

is_allowed() {
    local rel="$1"
    for p in $ALLOWED_PREFIXES; do
        case "$rel" in "$p"*) return 0 ;; esac
    done
    return 1
}

# Staged name -> live name. The dot-component rule means an agent can
# never write a file starting with "." so .htaccess is staged as
# public/htaccess.txt and renamed here.
live_name() {
    case "$1" in
        public/htaccess.txt) echo "public/.htaccess" ;;
        *) echo "$1" ;;
    esac
}

echo "app:     $APP"
echo "staging: $STAGE"
echo

# Deliberately no process substitution and no bash arrays. A jailed cPanel
# shell may have no /dev/fd, which makes "< <(...)" fail — and it fails
# QUIETLY: the loop simply never runs, the list stays empty, and the script
# reports "nothing staged" instead of an error. Temp files work everywhere.
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

find "$STAGE" -type f | sort > "$WORK/all"
: > "$WORK/candidates"
: > "$WORK/skipped"

while IFS= read -r abs; do
    [ -n "$abs" ] || continue
    rel="${abs#$STAGE/}"
    if is_allowed "$rel"; then
        echo "$rel" >> "$WORK/candidates"
    else
        echo "$rel" >> "$WORK/skipped"
    fi
done < "$WORK/all"

if [ -s "$WORK/skipped" ]; then
    echo "SKIPPED — outside $ALLOWED_PREFIXES:"
    sed 's/^/  /' "$WORK/skipped"
    echo
fi

if [ ! -s "$WORK/candidates" ]; then
    if [ ! -s "$WORK/all" ]; then
        echo "Nothing in $STAGE at all."
    else
        echo "Nothing staged under $ALLOWED_PREFIXES."
    fi
    exit 0
fi

echo "=== DIFF (staged vs live) ==================================="
: > "$WORK/changed"
while IFS= read -r rel; do
    target=$(live_name "$rel")
    if [ -f "$APP/$target" ] && diff -q "$APP/$target" "$STAGE/$rel" >/dev/null 2>&1; then
        continue
    fi
    echo "$rel" >> "$WORK/changed"
    echo
    if [ -f "$APP/$target" ]; then
        diff -u --label "live/$target" --label "staged/$target" \
            "$APP/$target" "$STAGE/$rel" || true
    else
        echo "NEW FILE: $target ($(wc -l < "$STAGE/$rel") lines)"
    fi
done < "$WORK/candidates"
echo
echo "============================================================="

if [ ! -s "$WORK/changed" ]; then
    echo "No differences. Nothing to do."
    exit 0
fi

echo "$(wc -l < "$WORK/changed") file(s) would change:"
while IFS= read -r rel; do echo "  $(live_name "$rel")"; done < "$WORK/changed"

[ "$DIFF_ONLY" -eq 1 ] && { echo; echo "(--diff: stopping here)"; exit 0; }

echo
read -r -p "Install these? Type 'yes' to proceed: " ANSWER
[ "$ANSWER" = "yes" ] || { echo "Aborted."; exit 1; }

# --- gates: nothing installs unless it parses and the suite passes -----
TMP="$WORK/probe"
mkdir -p "$TMP"
cp -r "$APP/." "$TMP/"
while IFS= read -r rel; do
    target=$(live_name "$rel")
    mkdir -p "$TMP/$(dirname "$target")"
    cp "$STAGE/$rel" "$TMP/$target"
done < "$WORK/changed"

FAIL=0
for f in $(find "$TMP/src" "$TMP/public" -name '*.php' 2>/dev/null); do
    php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; FAIL=1; }
done
[ "$FAIL" -eq 0 ] || { echo "Refusing to install."; exit 1; }
echo "all PHP files parse"

if [ -f "$TMP/tests/unit/test-scoping.php" ]; then
    if php "$TMP/tests/unit/test-scoping.php" > "$WORK/test.log" 2>&1; then
        tail -1 "$WORK/test.log"
    else
        echo "OFFLINE TESTS FAILED:"; cat "$WORK/test.log"
        echo "Refusing to install."; exit 1
    fi
fi

# --- backup, install ---------------------------------------------------
while IFS= read -r rel; do
    target=$(live_name "$rel")
    if [ -f "$APP/$target" ]; then
        mkdir -p "$BACKUP/$(dirname "$target")"
        cp -p "$APP/$target" "$BACKUP/$target"
    fi
done < "$WORK/changed"
echo "backed up to $BACKUP"

while IFS= read -r rel; do
    target=$(live_name "$rel")
    mkdir -p "$APP/$(dirname "$target")"
    install -m 644 "$STAGE/$rel" "$APP/$target"
    echo "  installed $target  $(md5sum "$APP/$target" | cut -d' ' -f1)"
done < "$WORK/changed"

# --- post-install ------------------------------------------------------
echo
echo "static files must NOT be served:"
for u in index.php composer.json; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "https://mcp.crockart.com.au/$u" || echo ERR)
    if [ "$code" = "404" ]; then note=""; else note="   <-- CHECK THIS"; fi
    printf '  %-16s %s%s\n' "$u" "$code" "$note"
done

echo
echo "Next:  python3 $APP/tests/acceptance/verify-scoping.py"
echo "Roll back:  cp -pr $BACKUP/. $APP/"
echo
echo "Staged files are left in place. Clear them when satisfied:"
echo "  rm -rf $STAGE/src $STAGE/public $STAGE/tests"
