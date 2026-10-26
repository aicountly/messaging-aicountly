#!/usr/bin/env bash
# find-ref.sh — the log lines for a support reference ("Ref: …" on a user's error), read-only.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/scripts/ops/find-ref.sh — identical in every product at
# scripts/ops/find-ref.sh. The product's find-ref workflow pipes it over SSH; nothing is installed:
#
#   ssh host 'bash -s' -- <ref> <days> <dir>... < scripts/ops/find-ref.sh
#
# A Ref is the request id the screen sent (X-Request-Id) and the app logs beside the failure. This lists
# every log line carrying it in the given folders (the app's writable/logs, logs/, error_log, php error
# logs, boot-*.log), with the lines that follow (a stack trace), newest files first.
#
#  - read-only: grep over log files modified in the last <days> (default 7, at most 31)
#  - the ref must be 6-64 characters of [A-Za-z0-9._-]; folders must be absolute and under $HOME (a folder
#    given as a symlink, such as the atomic api/, is searched where it points, which must be under $HOME too)
#  - output capped (MAX_LINES, default 400); e-mail addresses, bearer/session tokens, JWTs and
#    password/secret/key values are masked before anything is printed
set -uo pipefail
ref="${1:-}"
days="${2:-7}"
shift 2 2>/dev/null || true
MAX_LINES="${MAX_LINES:-400}"
case "$ref" in '' | *[!A-Za-z0-9._-]*) echo "find-ref: the ref must be letters, digits, . _ - only"; exit 2 ;; esac
[ "${#ref}" -ge 6 ] && [ "${#ref}" -le 64 ] || { echo "find-ref: the ref must be 6-64 characters"; exit 2; }
case "$days" in '' | *[!0-9]*) days=7 ;; esac
[ "$days" -ge 1 ] && [ "$days" -le 31 ] || days=7
[ "$#" -gt 0 ] || { echo "find-ref: name at least one folder"; exit 2; }

mask() {
  sed -E \
    -e 's/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/\1***@\2/g' \
    -e 's/([Bb]earer[[:space:]]+)[A-Za-z0-9._~+\/=-]{8,}/\1***/g' \
    -e 's/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/***jwt***/g' \
    -e 's/((ses_?key|auth_?token|token|password|passwd|secret|api_?key|signature)["'"'"']?[[:space:]]*[:=][[:space:]]*["'"'"']?)[^"'"'"'&[:space:],}]{4,}/\1***/Ig'
}

files=()
home_real="$(cd "$HOME" 2>/dev/null && pwd -P)" || home_real="$HOME"
for dir in "$@"; do
  case "$dir" in "$HOME"/* | "$HOME") ;; *) echo "find-ref: skipping $dir (not under the account's home)"; continue ;; esac
  [ -d "$dir" ] || { echo "find-ref: no folder $dir"; continue; }
  # Searched where it really is: find does not descend into a folder given as a symlink (the atomic api/ ->
  # its current release), and a folder that resolves outside the account's home is not read.
  real="$(cd "$dir" 2>/dev/null && pwd -P)" || { echo "find-ref: cannot enter $dir"; continue; }
  case "$real" in "$home_real"/* | "$home_real") ;; *) echo "find-ref: skipping $dir (resolves outside the account's home)"; continue ;; esac
  while IFS= read -r -d '' f; do files+=("$f"); done < <(
    find "$real" -maxdepth 5 -type f \( -name '*.log' -o -name '*.log.[0-9]*' -o -name 'error_log' -o -name '*error*.log' -o -name '*.log.gz' \) \
      -mtime "-$days" -size -512M -print0 2>/dev/null)
done
if [ "${#files[@]}" = 0 ]; then echo "find-ref: no log files modified in the last $days days in: $*"; exit 1; fi

hits=0
out="$(mktemp)"
trap 'rm -f "$out"' EXIT
# Newest first.
while IFS= read -r f; do
  if case "$f" in *.gz) zgrep -qF -- "$ref" "$f" 2>/dev/null ;; *) grep -qF -- "$ref" "$f" 2>/dev/null ;; esac; then
    hits=$((hits + 1))
    {
      echo "==== $f ($(date -r "$f" '+%Y-%m-%d %H:%M' 2>/dev/null))"
      case "$f" in *.gz) zgrep -F -n -A 25 -- "$ref" "$f" ;; *) grep -F -n -A 25 -- "$ref" "$f" ;; esac
    } >> "$out" 2>/dev/null
  fi
done < <(ls -1t "${files[@]}" 2>/dev/null)
if [ "$hits" = 0 ]; then
  echo "find-ref: '$ref' is in none of ${#files[@]} log files modified in the last $days days"
  exit 1
fi
echo "find-ref: '$ref' found in $hits file(s); first $MAX_LINES lines, secrets and e-mail addresses masked"
mask < "$out" | head -n "$MAX_LINES"
exit 0
