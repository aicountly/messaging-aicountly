#!/usr/bin/env bash
# install-jobs.sh — install this product's scheduled jobs (bin/ops/jobs.txt) into THIS server user's
# crontab. Dry run unless --apply. (E8-OPS, F160: a README cron is not a worker.)
#
# CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/install-jobs.sh — identical in every product
# at server-php/bin/ops/install-jobs.sh. The product's own list is bin/ops/jobs.txt beside it.
#
#   bash bin/ops/install-jobs.sh                  show what would change; nothing is written
#   bash bin/ops/install-jobs.sh --apply          install or update the block (the deploy runs this)
#   bash bin/ops/install-jobs.sh --remove [--apply]
#   bash bin/ops/install-jobs.sh --check          exit 0 only when the installed block equals jobs.txt
#
# Options: --php=PATH  --api-dir=PATH (default: this script's api/ folder)  --log-dir=PATH (default ~/logs)
#
# jobs.txt format (one job per line; blank lines and # comments ignored):
#   #! app: <name>                      product slug used in markers, lock and log names (required)
#   #! legacy-marker: <text>            an older hand-installed block "# >>> <text>: <api-dir> >>>" this
#                                       block replaces (removed on --apply so the job never runs twice)
#   #! legacy-pattern: <ERE>            hand-installed single lines (outside any aicountly block) this block
#                                       replaces, e.g. "spark inventory:outbox-dispatch"; removed on --apply
#                                       and shown in the diff first
#   #! php-ext: <extension>             the PHP extension the jobs need (default pdo_pgsql; pgsql for a
#                                       CodeIgniter app on its Postgre driver)
#   <job-name> | <cron schedule> | <command, run from the api dir; {php} is the PHP CLI>
#
# WHAT IT WRITES: one block between marker lines that name the API folder, so production and sandbox
# under one cPanel user keep their own blocks and nothing else in the crontab is touched:
#   # >>> aicountly-<app> jobs: /home/u/public_html/api >>>
#   * * * * * cd '/home/u/public_html/api' && flock -n '/home/u/.aicountly-locks/<app>-<job>-<hash>.lock' '/opt/.../php' bin/worker.php >> '/home/u/logs/aicountly-<app>-<job>.log' 2>&1
#   # <<< aicountly-<app> jobs: /home/u/public_html/api <<<
# Safe to run again: the block is replaced, never duplicated, and an unchanged crontab is not rewritten.
# `flock -n` skips a run rather than stack overlapping runs (every job is also re-entrant).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
apply=0 remove=0 check=0 php_bin="" log_dir="${HOME}/logs" lock_dir="${HOME}/.aicountly-locks"
api_dir="$(cd "$here/../.." && pwd)"
jobs_file="${JOBS_FILE:-$here/jobs.txt}"
crontab_bin="${CRONTAB_BIN:-crontab}"

for arg in "$@"; do
  case "$arg" in
    --apply) apply=1 ;;
    --remove) remove=1 ;;
    --check) check=1 ;;
    --php=*) php_bin="${arg#--php=}" ;;
    --log-dir=*) log_dir="${arg#--log-dir=}" ;;
    --api-dir=*) api_dir="${arg#--api-dir=}" ;;
    -h | --help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg (see --help)" >&2; exit 2 ;;
  esac
done

[ -f "$jobs_file" ] || { echo "No jobs file at $jobs_file" >&2; exit 2; }
api_dir="$(cd "$api_dir" 2>/dev/null && pwd)" || { echo "No such API folder: $api_dir" >&2; exit 2; }
app="$(sed -n 's/^#![[:space:]]*app:[[:space:]]*\([a-z0-9_-]*\).*/\1/p' "$jobs_file" | head -n 1)"
[ -n "$app" ] || { echo "$jobs_file has no '#! app: <name>' line" >&2; exit 2; }
legacy="$(sed -n 's/^#![[:space:]]*legacy-marker:[[:space:]]*//p' "$jobs_file" | head -n 1)"
legacy_pattern="$(sed -n 's/^#![[:space:]]*legacy-pattern:[[:space:]]*//p' "$jobs_file" | paste -sd'|' -)"
# The PHP extension the jobs need: pdo_pgsql unless jobs.txt names another ('#! php-ext: pgsql' for a
# CodeIgniter app on its Postgre driver, whose host PHP need not carry pdo_pgsql).
php_ext="$(sed -n 's/^#![[:space:]]*php-ext:[[:space:]]*\([a-z0-9_]*\).*/\1/p' "$jobs_file" | head -n 1)"
php_ext="${php_ext:-pdo_pgsql}"

begin="# >>> aicountly-${app} jobs: ${api_dir} >>>"
end="# <<< aicountly-${app} jobs: ${api_dir} <<<"

if [ "$remove" -eq 0 ]; then
  # A bare name (--php=php, as a post-deploy hook resolves it) becomes the full path cron needs.
  case "$php_bin" in */* | '') ;; *) php_bin="$(command -v "$php_bin" 2>/dev/null || true)" ;; esac
  if [ -z "$php_bin" ]; then
    for c in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
             /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php \
             /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
      # php -m captured first: `php -m | grep -q` under pipefail can fail on SIGPIPE (see release.sh).
      if [ -n "$c" ] && [ -x "$c" ] && mods="$("$c" -m 2>/dev/null)" && printf '%s\n' "$mods" | grep -qix "$php_ext"; then php_bin="$c"; break; fi
    done
  fi
  [ -n "$php_bin" ] && [ -x "$php_bin" ] || { echo "No PHP CLI with ${php_ext} was found. Name one with --php=PATH." >&2; exit 2; }
fi

hash="$(printf '%s' "$api_dir" | cksum | cut -d' ' -f1)"
have_flock=0
command -v flock >/dev/null 2>&1 && have_flock=1

block=""
if [ "$remove" -eq 0 ]; then
  block="$begin"$'\n'
  n=0
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in '' | \#*) continue ;; esac
    name="$(printf '%s' "$line" | cut -d'|' -f1 | xargs)"
    sched="$(printf '%s' "$line" | cut -d'|' -f2 | xargs)"
    cmd="$(printf '%s' "$line" | cut -d'|' -f3- | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
    if [ -z "$name" ] || [ -z "$cmd" ] || [ "$(printf '%s' "$sched" | wc -w)" -ne 5 ]; then
      echo "Bad line in $jobs_file: $line" >&2; exit 2
    fi
    printf '%s' "$name" | grep -qE '^[a-z0-9-]+$' || { echo "Bad job name '$name' in $jobs_file" >&2; exit 2; }
    cmd="${cmd//\{php\}/\'$php_bin\'}"
    log="${log_dir}/aicountly-${app}-${name}.log"
    if [ "$have_flock" -eq 1 ]; then
      run="flock -n '${lock_dir}/${app}-${name}-${hash}.lock' ${cmd}"
    else
      run="${cmd}"
    fi
    block+="${sched} cd '${api_dir}' && ${run} >> '${log}' 2>&1"$'\n'
    n=$((n + 1))
  done < "$jobs_file"
  block+="$end"
  [ "$n" -gt 0 ] || { echo "$jobs_file lists no jobs" >&2; exit 2; }
fi

current="$("$crontab_bin" -l 2>/dev/null || true)"
# Drop our block and, when named, the legacy hand-installed block for this same API folder.
strip() {
  awk -v b="$begin" -v e="$end" -v lb="# >>> ${legacy}: ${api_dir} >>>" -v le="# <<< ${legacy}: ${api_dir} <<<" -v hasl="${legacy:+1}" '
    $0 == b || (hasl && $0 == lb) { skip = 1; next }
    $0 == e || (hasl && $0 == le) { skip = 0; next }
    !skip { print }'
}
rest="$(printf '%s\n' "$current" | strip | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
if [ -n "$legacy_pattern" ] && [ "$remove" -eq 0 ]; then
  # Hand-installed lines for the same jobs, outside any managed block (comments are kept).
  rest="$(printf '%s\n' "$rest" | awk -v pat="$legacy_pattern" '
    /^# >>> aicountly-/ { inb = 1 } /^# <<< aicountly-/ { inb = 0; print; next }
    !inb && $0 !~ /^[[:space:]]*#/ && $0 ~ pat { next } { print }' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
fi
if [ "$remove" -eq 1 ]; then
  new="$rest"
else
  new="${rest:+$rest$'\n'}$block"
fi

if [ "$check" -eq 1 ]; then
  installed="$(printf '%s\n' "$current" | awk -v b="$begin" -v e="$end" '$0==b{on=1} on{print} $0==e{on=0}')"
  if [ "$installed" = "$block" ]; then echo "installed block matches $jobs_file"; exit 0; fi
  echo "installed block differs from $jobs_file (run with --apply)"; exit 1
fi

if [ "$(printf '%s' "$current")" = "$(printf '%s' "$new")" ]; then
  echo "crontab already up to date for aicountly-${app} at ${api_dir}"
  exit 0
fi

echo "--- crontab change for aicountly-${app} (${api_dir}) ---"
diff <(printf '%s\n' "$current") <(printf '%s\n' "$new") | sed 's/^/    /' || true
if [ "$apply" -ne 1 ]; then
  echo "Dry run: nothing written. Re-run with --apply."
  exit 0
fi
mkdir -p "$log_dir" "$lock_dir"
chmod 700 "$lock_dir" 2>/dev/null || true
printf '%s\n' "$new" | "$crontab_bin" -
echo "crontab updated for aicountly-${app} at ${api_dir}"
