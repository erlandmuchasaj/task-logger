#!/usr/bin/env bash
# PreToolUse (Write|Edit|Bash): protect the repository rules in CLAUDE.md.
#
#   deny  Nobody hand-writes these, whoever asks:
#         - a NEW file in database/migrations (Schema rule: edit the module's existing
#           create_*_tables migration instead)
#         - `artisan make:migration`, and `make:model` with -m / -a / --migration / --all
#         - composer.lock and package-lock.json (composer and npm own them)
#   ask   Allowed only when the owner says so, so the owner gets a prompt:
#         - .env and .env.* (but not .env.example)
#         - `artisan migrate:fresh|migrate:refresh|db:wipe` (erase the local database)
#         - `composer lint` (rewrites files across the whole repository)
#
# Limit: this sees the Write/Edit tools and the Bash command text. A file written from
# inside a shell command (`cat > database/migrations/x.php`, `sed -i .env`) is not caught.
#
# Needs jq. Without it the guard cannot read the call, so it fails closed.

if ! command -v jq >/dev/null 2>&1; then
    echo "guard.sh needs jq to read the tool call. Install it (brew install jq) or remove the hook from .claude/settings.json." >&2
    exit 2
fi

input=$(cat)
tool=$(jq -r '.tool_name // empty' <<<"$input")
root="${CLAUDE_PROJECT_DIR:-$PWD}"

decide() {
    jq -n --arg decision "$1" --arg reason "$2" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: $decision,
            permissionDecisionReason: $reason
        }
    }'
    exit 0
}

case "$tool" in
    Write | Edit)
        file=$(jq -r '.tool_input.file_path // empty' <<<"$input")
        [ -n "$file" ] || exit 0
        base="${file##*/}"
        rel="${file#"$root"/}"

        case "$rel" in
            database/migrations/*)
                if [ ! -e "$file" ]; then
                    decide deny "New migration files are not allowed while the schema is unreleased (CLAUDE.md, Schema). Edit the module's existing create_*_tables migration and apply the same change to the local database with SQL."
                fi
                ;;
        esac

        case "$base" in
            composer.lock | package-lock.json)
                decide deny "$base is generated. Change composer.json or package.json and let composer or npm update the lock file."
                ;;
            .env.example) ;;
            .env | .env.*)
                decide ask "$base holds local secrets and settings. Confirm that the owner asked for this edit."
                ;;
        esac
        ;;

    Bash)
        cmd=$(jq -r '.tool_input.command // empty' <<<"$input")
        [ -n "$cmd" ] || exit 0

        if grep -Eq 'artisan[[:space:]]+make:migration([[:space:]]|$)' <<<"$cmd"; then
            decide deny "make:migration is not used here (CLAUDE.md, Schema). Edit the module's existing create_*_tables migration and apply the same change to the local database with SQL."
        fi

        if grep -Eq 'artisan[[:space:]]+make:model[^;&|]*[[:space:]](-[a-zA-Z]*[ma][a-zA-Z]*|--migration|--all)([[:space:]]|$)' <<<"$cmd"; then
            decide deny "make:model with -m, -a, --migration or --all creates a migration file, which this repo does not use (CLAUDE.md, Schema). Run make:model without those flags."
        fi

        if grep -Eq 'artisan[[:space:]]+(migrate:fresh|migrate:refresh|db:wipe)([[:space:]]|$)' <<<"$cmd"; then
            decide ask "This erases the local database. Confirm that the owner asked for it."
        fi

        if grep -Eq '(^|[;&|(]|[[:space:]])composer[[:space:]]+((run|run-script)[[:space:]]+)?lint([[:space:]]|$|[;&|)])' <<<"$cmd"; then
            decide ask "composer lint rewrites files across the whole repository. Confirm that the owner asked for it, or use: vendor/bin/pint --dirty --format agent"
        fi
        ;;
esac

exit 0
