#!/usr/bin/env bash
# PostToolUse (Edit|Write): run Pint on the one PHP file Claude just wrote.
#
# Fails open: any problem (no jq, no vendor/bin/pint, odd path) exits 0 silently, so a
# formatter hiccup never blocks the session. `composer test:lint` in CI is the real gate.
# Per CLAUDE.md this runs `pint <file> --format agent`, never `composer lint`, which
# rewrites the whole repository.

command -v jq >/dev/null 2>&1 || exit 0

root="${CLAUDE_PROJECT_DIR:-$PWD}"
file=$(jq -r '.tool_response.filePath // .tool_input.file_path // empty')

[ -n "$file" ] && [ -f "$file" ] || exit 0

case "$file" in
    "$root"/vendor/* | "$root"/node_modules/* | "$root"/storage/* | "$root"/bootstrap/cache/*) exit 0 ;;
    "$root"/*.blade.php | "$root"/*/*.blade.php | *.blade.php) exit 0 ;;
    "$root"/*.php) ;;
    *) exit 0 ;;
esac

cd "$root" 2>/dev/null || exit 0
[ -x vendor/bin/pint ] || exit 0

vendor/bin/pint --format agent "$file" >/dev/null 2>&1
exit 0
