#!/usr/bin/env bash
# Auto-sync the Otherworld repo with origin/master.
# Designed to run every minute from cron on the DigitalOcean droplet.
#
# Crontab entry:
#   * * * * * /path/to/repo/scripts/autosync.sh
#
# Requirements on the host:
#   - git in PATH (the script also sets a safe default PATH for cron)
#   - flock (Linux: util-linux; pre-installed on Debian/Ubuntu)
#   - The cron user has push access to origin (deploy key or PAT)
#   - The repo's git remote 'origin' is set and reachable
#
# Behavior:
#   - Skipped silently if a previous run is still in flight (flock)
#   - Pulls with --rebase --autostash so local edits ride along into the
#     next commit; aborts cleanly if the rebase can't apply
#   - Stages everything; exits without committing if nothing changed
#   - Commit message: "Auto sync: <files> [+ N more] (YYYY-MM-DD HH:MM)"
#   - Logs to admin/logs/autosync.log (gitignored)

set -uo pipefail

# Cron starts with a minimal PATH; make sure git and friends are findable.
export PATH="/usr/local/bin:/usr/bin:/bin"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${REPO_DIR}/admin/logs/autosync.log"
LOCK_FILE="/tmp/otherworld-autosync.lock"
BRANCH="master"
GIT_USER_NAME="${AUTOSYNC_GIT_NAME:-Magic autosync}"
GIT_USER_EMAIL="${AUTOSYNC_GIT_EMAIL:-autosync@magic.local}"

mkdir -p "$(dirname "$LOG_FILE")"

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"
}

# Single-instance guard: bail silently if another run holds the lock.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

cd "$REPO_DIR" || { log "ERROR: cd $REPO_DIR failed"; exit 1; }

# Pull first. --rebase keeps history linear; --autostash bundles any
# uncommitted edits over the rebase so we can fold them into our commit.
if ! git pull --rebase --autostash origin "$BRANCH" >>"$LOG_FILE" 2>&1; then
  log "ERROR: pull/rebase failed; rolling back"
  git rebase --abort >>"$LOG_FILE" 2>&1 || true
  git stash pop >>"$LOG_FILE" 2>&1 || true
  exit 1
fi

git add -A

# Nothing to commit → done.
if git diff --cached --quiet; then
  exit 0
fi

# Build summary: first 3 changed paths, plus "+ N more" if needed.
mapfile -t CHANGED_FILES < <(git diff --cached --name-only)
COUNT="${#CHANGED_FILES[@]}"
SUMMARY="$(printf '%s, ' "${CHANGED_FILES[@]:0:3}" | sed 's/, $//')"
if [ "$COUNT" -gt 3 ]; then
  SUMMARY="${SUMMARY} + $((COUNT - 3)) more"
fi
TIMESTAMP="$(date '+%Y-%m-%d %H:%M')"
MSG="Auto sync: ${SUMMARY} (${TIMESTAMP})"

if ! GIT_AUTHOR_NAME="$GIT_USER_NAME" GIT_AUTHOR_EMAIL="$GIT_USER_EMAIL" \
     GIT_COMMITTER_NAME="$GIT_USER_NAME" GIT_COMMITTER_EMAIL="$GIT_USER_EMAIL" \
     git commit -m "$MSG" >>"$LOG_FILE" 2>&1; then
  log "ERROR: commit failed"
  exit 1
fi

if ! git push origin "$BRANCH" >>"$LOG_FILE" 2>&1; then
  log "ERROR: push failed"
  exit 1
fi

log "Synced ($COUNT file$( [ "$COUNT" -ne 1 ] && echo s )): $MSG"
