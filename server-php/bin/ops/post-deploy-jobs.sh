#!/usr/bin/env bash
# post-deploy-jobs.sh — the deploy's last server-side step for a product's scheduled jobs (E8-OPS, F160).
#
# CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/post-deploy-jobs.sh — identical in every product
# at server-php/bin/ops/post-deploy-jobs.sh, beside install-jobs.sh and jobs.txt. For products that do not
# run the full bin/ops/release.sh; release.sh products install their jobs there.
#
#   printf '%s' "$CONSOLE_CRON_MONITOR_KEY" | ssh host "bash <api>/bin/ops/post-deploy-jobs.sh [--php=PATH]"
#
# In order, on the server, from the api folder this script lives in:
#   1. the Cron Job Monitor key read from STDIN (may be empty) is written into .env as
#      CONSOLE_CRON_MONITOR_KEY — replaced when present, appended when not; an empty STDIN leaves .env alone.
#      The key is never an argument, so it never shows in a process list or a workflow log;
#   2. every '#! preview: <command>' line of jobs.txt runs once, READ-ONLY, and its output is printed — e.g.
#      the company-lifecycle worker's --status, so the deploy log shows the purges Manage has queued BEFORE
#      the jobs that carry them out are installed;
#   3. bin/ops/install-jobs.sh --apply, then --check.
#
# Exit: 0 installed and the key is in .env · 20 installed, but .env has no CONSOLE_CRON_MONITOR_KEY (the jobs
# run, every heartbeat is skipped and Console shows them OVERDUE) · 21 the crontab could not be installed ·
# 2 bad usage / no PHP.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_dir="$(cd "$here/../.." && pwd)"
jobs_file="$here/jobs.txt"
php_bin=""
for arg in "$@"; do
  case "$arg" in
    --php=*) php_bin="${arg#--php=}" ;;
    -h | --help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done
cd "$api_dir" || exit 2
[ -f .env ] || { echo "No .env in $api_dir" >&2; exit 2; }
[ -f "$jobs_file" ] || { echo "No jobs file at $jobs_file" >&2; exit 2; }

# 1. The key, from STDIN only.
key=""
if [ ! -t 0 ]; then
  key="$(cat | tr -d '\r\n')"
fi
if [ -n "$key" ]; then
  tmp="$(mktemp "$api_dir/.env.XXXXXX")" || exit 2
  K="$key" awk '
    /^[[:space:]]*CONSOLE_CRON_MONITOR_KEY[[:space:]]*=/ { if (!done) print "CONSOLE_CRON_MONITOR_KEY=" ENVIRON["K"]; done = 1; next }
    { print }
    END { if (!done) print "CONSOLE_CRON_MONITOR_KEY=" ENVIRON["K"] }' .env > "$tmp" || { rm -f "$tmp"; exit 2; }
  if cmp -s "$tmp" .env; then
    echo "CONSOLE_CRON_MONITOR_KEY: already current in .env."
  else
    cat "$tmp" > .env   # rewritten in place: keeps the file's owner and mode
    echo "CONSOLE_CRON_MONITOR_KEY: written to .env."
  fi
  rm -f "$tmp"
fi
key_set=0
grep -qE '^[[:space:]]*CONSOLE_CRON_MONITOR_KEY[[:space:]]*=[[:space:]]*["'"'"']?[^"'"'"'[:space:]]' .env && key_set=1

# The PHP CLI (the same search install-jobs.sh makes).
php_ext="$(sed -n 's/^#![[:space:]]*php-ext:[[:space:]]*\([a-z0-9_]*\).*/\1/p' "$jobs_file" | head -n 1)"
php_ext="${php_ext:-pdo_pgsql}"
case "$php_bin" in */* | '') ;; *) php_bin="$(command -v "$php_bin" 2>/dev/null || true)" ;; esac
if [ -z "$php_bin" ]; then
  for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
           /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php \
           /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    if [ -n "$c" ] && [ -x "$c" ] && mods="$("$c" -m 2>/dev/null)" && printf '%s\n' "$mods" | grep -qix "$php_ext"; then php_bin="$c"; break; fi
  done
fi
[ -n "$php_bin" ] && [ -x "$php_bin" ] || { echo "No PHP CLI with ${php_ext} was found. Name one with --php=PATH." >&2; exit 2; }

# 2. Read-only previews.
while IFS= read -r line; do
  cmd="${line#*preview:}"
  cmd="$(printf '%s' "$cmd" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  [ -n "$cmd" ] || continue
  echo "::group::preview: ${cmd}"
  bash -c "${cmd//\{php\}/\'$php_bin\'}" || echo "(preview exited $? — shown for information; nothing was changed)"
  echo "::endgroup::"
done < <(grep -E '^#![[:space:]]*preview:' "$jobs_file")

# 3. The crontab block.
if ! bash "$here/install-jobs.sh" --apply --php="$php_bin" || ! bash "$here/install-jobs.sh" --check --php="$php_bin"; then
  exit 21
fi
[ "$key_set" -eq 1 ] || exit 20
exit 0
