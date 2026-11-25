#!/usr/bin/env bash
# PreToolUse (Write|Edit|Bash|merge and branch-writing MCP tools): protect the repository
# rules in AGENTS.md and the "never force-push, never merge" promise of the /ticket skill.
#
#   deny  Nobody hand-writes these, whoever asks:
#         - composer.lock and package-lock.json (composer and npm own them)
#         - `git push` with --force, -f (or a bundle such as -fu), a +refspec or --mirror
#         - merging a pull request: `gh pr merge`, `gh api .../merge`, a GraphQL
#           mergePullRequest, and the MCP tools merge_pull_request and merge_diff
#           (merge on GitHub, or let `gh pr merge --auto` do it once CI is green)
#   ask   Allowed only when the owner says so, so the owner gets a prompt:
#         - .env and .env.* (but not .env.example)
#         - editing a migration that git already tracks (Schema rule: a committed
#           migration is never edited; add a new one)
#         - `artisan migrate:fresh|migrate:refresh|db:wipe` (erase the local database)
#         - `composer lint` (rewrites files across the whole repository)
#         - `git push` to main or master, named or implied (a bare `git push`, `origin`
#           or `HEAD` while main is checked out), and `git push --all`
#         - `git push --force-with-lease` / --force-if-includes, and deleting a remote
#           branch (--delete, -d, :branch)
#         - `gh pr merge --auto`
#         - `git reset --hard`, `git clean -f`, `git checkout -- <path>` or `.`,
#           `git restore` (unless only --staged), `git branch -D`, `git stash drop|clear`
#         - the GitHub MCP tools push_files, create_or_update_file and delete_file when
#           they write to main or master (they commit on GitHub without a `git push`)
#
# Limit: this sees the Write/Edit tools and the Bash command text. A file written from
# inside a shell command (`sed -i .env`, `cat > composer.lock`) is not caught.
# For the git and gh rules, quoted strings are removed from the command first, so a
# commit message or PR body that mentions `git push -f` does not trigger them. The price:
# anything that hides in quotes is not seen either (`bash -c "git push -f"`, `eval`,
# `git push origin "main"`), nor is a git command run from a script file, an alias, or
# `git -C <other repo>`. An unquoted heredoc line can still match. GitHub API calls made
# some other way than the tools named above are not covered. A hook is a guardrail, not
# a security boundary: branch protection on GitHub is the real gate for main.
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

        case "$file" in
            */database/migrations/*)
                if git -C "$root" ls-files --error-unmatch -- "$file" >/dev/null 2>&1; then
                    decide ask "$base is a committed migration, and a committed migration is never edited (AGENTS.md, Schema). Add a new migration instead, or confirm that the owner asked for this edit."
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

        if grep -Eq 'artisan[[:space:]]+(migrate:fresh|migrate:refresh|db:wipe)([[:space:]]|$)' <<<"$cmd"; then
            decide ask "This erases the local database. Confirm that the owner asked for it."
        fi

        if grep -Eq '(^|[;&|(]|[[:space:]])composer[[:space:]]+((run|run-script)[[:space:]]+)?lint([[:space:]]|$|[;&|)])' <<<"$cmd"; then
            decide ask "composer lint rewrites files across the whole repository. Confirm that the owner asked for it, or use: vendor/bin/pint --dirty --format agent"
        fi

        # ---- git and gh rules -------------------------------------------------------
        # Match against the command with every quoted string emptied (see Limit above).
        # If jq cannot do it, fall back to the raw text rather than skip the rules.
        scrubbed=$(jq -rn --arg c "$cmd" --arg re "\"(\\\\.|[^\"\\\\])*\"|'[^']*'" '$c | gsub($re; "")' 2>/dev/null) || scrubbed="$cmd"
        [ -n "$scrubbed" ] || scrubbed="$cmd"

        sp='[[:space:]]'
        # "git", optionally with -c k=v / -C path / --long-option before the subcommand.
        git_re="(^|[;&|(]|${sp})git(${sp}+(-[cC]${sp}+[^[:space:]]+|--[a-z-]+(=[^[:space:]]*)?))*${sp}+"
        gh_re="(^|[;&|(]|${sp})gh${sp}+"

        # The part of a command line from the subcommand up to the next ; & or |.
        # Prints one line per occurrence, so `git push a && git push -f b` yields both.
        git_segments() { grep -Eo "${git_re}$1([[:space:]][^;&|]*)?" <<<"$scrubbed" || true; }

        current_branch=$(git -C "$root" symbolic-ref --short -q HEAD 2>/dev/null || true)
        is_trunk() { [ "$1" = "main" ] || [ "$1" = "master" ]; }

        while IFS= read -r seg; do
            [ -n "$seg" ] || continue
            args=$(sed -E "s/^.*${sp}push(${sp}|\$)//" <<<"$seg")

            if grep -Eq "(^|${sp})(-f|--force|--mirror)(${sp}|\$)|(^|${sp})-[a-zA-Z]*f[a-zA-Z]*(${sp}|\$)|(^|${sp})\\+[^[:space:]]" <<<"$args"; then
                decide deny "A force push (or --mirror, or a +refspec) can destroy history on the remote. /ticket never force-pushes. If it is really needed, the owner runs it in their own terminal."
            fi

            read -ra tokens <<<"$args"
            positional=()
            for token in "${tokens[@]}"; do
                case "$token" in
                    -*) ;;
                    *) positional+=("$token") ;;
                esac
            done

            pushes_trunk=0
            if [ "${#positional[@]}" -le 1 ] && is_trunk "$current_branch"; then
                pushes_trunk=1 # no refspec: pushes the checked-out branch
            fi
            if [ "${#positional[@]}" -ge 2 ]; then
                for ref in "${positional[@]:1}"; do
                    dest="${ref##*:}"
                    dest="${dest#refs/heads/}"
                    if is_trunk "$dest"; then
                        pushes_trunk=1
                    elif [ "$dest" = "HEAD" ] && is_trunk "$current_branch"; then
                        pushes_trunk=1
                    fi
                done
            fi
            if [ "$pushes_trunk" -eq 1 ]; then
                decide ask "This pushes main (or master) directly. Confirm that the owner asked for it; /ticket pushes a feature branch and opens a pull request."
            fi

            if grep -Eq "(^|${sp})--all(${sp}|\$)" <<<"$args"; then
                decide ask "git push --all pushes every local branch, main included. Confirm that the owner asked for it."
            fi
            if grep -Eq "(^|${sp})(--force-with-lease(=[^[:space:]]*)?|--force-if-includes)(${sp}|\$)" <<<"$args"; then
                decide ask "A force push with a lease still rewrites the remote branch. Confirm that the owner asked for it. /ticket never force-pushes."
            fi
            if grep -Eq "(^|${sp})(--delete|-d)(${sp}|\$)|(^|${sp}):[^[:space:]]" <<<"$args"; then
                decide ask "This deletes a branch on the remote. Confirm that the owner asked for it."
            fi
        done < <(git_segments push)

        if grep -Eq "${gh_re}pr${sp}+merge(${sp}|\$)" <<<"$scrubbed"; then
            if grep -Eq "(^|${sp})--disable-auto(${sp}|\$)" <<<"$scrubbed"; then
                :
            elif grep -Eq "(^|${sp})--auto(${sp}|\$)" <<<"$scrubbed"; then
                decide ask "gh pr merge --auto merges the pull request as soon as the required checks pass. Confirm that the owner asked for it."
            else
                decide deny "Claude does not merge pull requests (the /ticket skill). The owner merges on GitHub, or the owner asks for: gh pr merge --auto"
            fi
        fi
        if grep -Eq "${gh_re}api(${sp}|\$)" <<<"$scrubbed"; then
            if grep -Eq '/pulls/[0-9]+/merge|/merge([[:space:]]|$)' <<<"$scrubbed" \
                || grep -Eq 'mergePullRequest|enablePullRequestAutoMerge' <<<"$cmd"; then
                decide deny "Claude does not merge pull requests, whatever API it goes through. The owner merges on GitHub."
            fi
        fi

        if grep -Eq "$git_re"'reset([[:space:]][^;&|]*)?[[:space:]]--hard([[:space:]]|$)' <<<"$scrubbed"; then
            decide ask "git reset --hard throws away uncommitted work, and the working tree may hold changes that are not yours. Confirm that the owner asked for it."
        fi
        if grep -Eq "$git_re"'clean([[:space:]][^;&|]*)?[[:space:]](-[a-zA-Z]*f[a-zA-Z]*|--force)([[:space:]]|$)' <<<"$scrubbed"; then
            decide ask "git clean -f deletes untracked files for good. Confirm that the owner asked for it."
        fi
        if grep -Eq "$git_re"'checkout([[:space:]][^;&|]*)?[[:space:]](--|\.)([[:space:]]|$)' <<<"$scrubbed"; then
            decide ask "This discards uncommitted changes in the working tree. Confirm that the owner asked for it."
        fi
        while IFS= read -r seg; do
            [ -n "$seg" ] || continue
            if ! grep -Eq "(^|${sp})(--staged|-S)(${sp}|\$)" <<<"$seg" \
                || grep -Eq "(^|${sp})(--worktree|-W)(${sp}|\$)" <<<"$seg"; then
                decide ask "git restore discards uncommitted changes in the working tree (only --staged is harmless). Confirm that the owner asked for it."
            fi
        done < <(git_segments restore)
        if grep -Eq "$git_re"'branch([[:space:]][^;&|]*)?[[:space:]]-[a-zA-Z]*D[a-zA-Z]*([[:space:]]|$)' <<<"$scrubbed"; then
            decide ask "git branch -D deletes a branch even if it is not merged. Confirm that the owner asked for it."
        fi
        if grep -Eq "$git_re"'stash[[:space:]]+(drop|clear)([[:space:]]|$)' <<<"$scrubbed"; then
            decide ask "This drops stashed work for good. Confirm that the owner asked for it."
        fi
        ;;

    mcp__*merge_pull_request | mcp__*merge_diff)
        decide deny "Claude does not merge pull requests (the /ticket skill). The owner merges on GitHub."
        ;;

    mcp__plugin_github_github__push_files | mcp__plugin_github_github__create_or_update_file | mcp__plugin_github_github__delete_file)
        branch=$(jq -r '.tool_input.branch // empty' <<<"$input")
        # No branch given means the repository's default branch, which is main.
        if [ -z "$branch" ] || [ "$branch" = "main" ] || [ "$branch" = "master" ]; then
            decide ask "This commits to ${branch:-the default branch (main)} on GitHub directly, without a pull request. Confirm that the owner asked for it."
        fi
        ;;
esac

exit 0
