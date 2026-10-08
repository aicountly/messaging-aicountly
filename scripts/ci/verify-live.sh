#!/usr/bin/env bash
# verify-live.sh — a post-deploy check that passes only on the app's real answer.
#
# The cPanel server's anti-bot splash ("One moment, please..." / "Please wait while your
# request is being verified...") answers some requests from GitHub's runners with HTTP 200 and
# an HTML challenge. A check that looks only at the status code passes on it without ever
# reaching the app; a check that looks at the body fails on it although the app is fine.
#
# So every check here needs the real answer: JSON that matches a jq filter, a page that
# contains a string only this build has, or — for a path that must not be served — a refusal.
# When the runner is shown the splash — or cannot connect at all (the server's firewall may
# refuse GitHub's addresses) — the same request is made again from the server itself over SSH;
# the splash never challenges the server's own requests. A real but wrong answer (a 5xx, the
# wrong JSON, a file that should have been refused) is not retried from the server: it is the
# answer. A check that gets the real answer from neither place fails.
#
# The checks run after the files are live: a failed check is an alarm about what is serving
# now, not a gate that kept a bad build off the server.
#
# Usage:
#   verify-live.sh json <label> <url> <jq-filter> [<jq-filter-for-a-warning>]
#       The first filter must hold, or the check fails ("is this really the app?", e.g.
#       '.app == "Sales"'). The optional second one only warns when false ("is it healthy?",
#       e.g. '.usable == true') — for health that depends on configuration, not the deploy.
#   verify-live.sh page <label> <url> <fixed-string>
#       The page must contain the string, e.g. the hashed entry asset of the build just
#       deployed (grep -o 'assets/index-[^"]*\.js' web/dist/index.html).
#   verify-live.sh absent <label> <url> [<fixed-string>]
#       The path must NOT be served (api/.env, error_log, SQL, tests/, .git/ ...). Passes when
#       the real answer refuses it — HTTP 401, 403, 404 or 410, whatever the body (the
#       server's error page or the app's own not-found JSON) — or when a 2xx body carries
#       the optional string: the app's own page answered in place of a missing file, e.g.
#       the SPA's <title> from its history fallback. Anything else fails: a 2xx without the
#       string (the file itself, a directory listing), a redirect, a 5xx. It asks for the
#       first 64 KB only, and the log shows the status, type and size of what was served,
#       never its content, which may be a secret. Never point it at a .php file under
#       tests/, bin/ or scripts/: on a host that serves them, a GET runs them.
#       With VERIFY_SSH set it is asked from the server ONLY, never from the runner: the
#       host's WAF (Imunify360) logs a runner asking for api/.env, .git/HEAD, error_log ...
#       as "direct access to sensitive file" and graylists the runner's address, which then
#       silently drops its SSH, so every ssh the job makes after the probes times out. The
#       server's own addresses are on the WAF's whitelist. Same request, same verdict.
#       Without VERIFY_SSH (a job with no SSH to lose) it is asked from the runner.
#
# Environment:
#   VERIFY_SSH           command prefix that runs one shell command on the server, e.g.
#                        "ssh deploy-target" or "ssh -p 22 user@host". Unset: no retry from
#                        the server, so a splash fails the check. Set: absent checks are
#                        asked from the server only (see absent above).
#   VERIFY_FORCE_SERVER  1: ask the server as well when the runner did get the real answer,
#                        and fail unless the server's answer passes too. Proves the fallback
#                        works (SSH, curl on the server, the server reaching its own site)
#                        before a deploy has to rely on it; needs VERIFY_SSH.
#   VERIFY_SEVERITY      error (default: a failed check exits 1) or warning (annotate, exit 0).
#   VERIFY_ATTEMPTS      tries per place (default 3); VERIFY_TIMEOUT seconds per request
#                        (default 20).
#
# Canonical copy; keep every repository's scripts/ci/verify-live.sh identical to it.
# shellcheck disable=SC2329 # the fetchers run as "fetch_${place}"; the helpers from inside them
set -uo pipefail

mode="${1:-}" label="${2:-}" url="${3:-}" expect="${4:-}" warn="${5:-}"
case "$mode" in
  json | page | absent) ;;
  *)
    echo "usage: $0 json <label> <url> <jq-filter> [<warn-filter>] | page <label> <url> <string> | absent <label> <url> [<string>]" >&2
    exit 2
    ;;
esac
if [ -z "$label" ] || [ -z "$url" ] || { [ "$mode" != absent ] && [ -z "$expect" ]; }; then
  echo "usage: $0 $mode <label> <url> <expected>" >&2
  exit 2
fi
if [ "$mode" = json ] && ! command -v jq >/dev/null 2>&1; then
  echo "::error title=Post-deploy check::${label}: jq is not installed on this runner" >&2
  exit 1
fi

attempts="${VERIFY_ATTEMPTS:-3}"
timeout="${VERIFY_TIMEOUT:-20}"
severity="${VERIFY_SEVERITY:-error}"
ssh_prefix="${VERIFY_SSH:-}"
force_server="${VERIFY_FORCE_SERVER:-0}"
if [ "$force_server" = 1 ] && [ -z "$ssh_prefix" ]; then
  echo "::error title=Post-deploy check::${label}: VERIFY_FORCE_SERVER=1 needs VERIFY_SSH" >&2
  exit 2
fi
splash='request is being verified|<title>One moment, please'
range="" # absent: never fetch more of a file than it takes to judge it
[ "$mode" = absent ] && range=0-65535
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# The first 200 characters of a body, on one line, for the log.
snippet() {
  tr '\r\n\t' '   ' < "$1" | cut -c1-200
}

# What was served, without its content (absent mode).
served() {
  echo "$(wc -c < "$tmp/body" | tr -d ' ') bytes of ${ctype:-an unnamed type}; content withheld from this log"
}

# Sets result (ok | warn | splash | bad) and reason from $tmp/body, HTTP code $1 and $ctype.
classify() {
  local code="$1"
  if grep -qiE "$splash" "$tmp/body" 2>/dev/null; then
    result=splash reason="got the host's anti-bot page (HTTP ${code}), not the app"
    return
  fi
  if [ "$mode" = absent ]; then
    case "$code" in
      401 | 403 | 404 | 410)
        result=ok reason="HTTP ${code}, not served"
        ;;
      2??)
        if [ -n "$expect" ] && grep -qF -- "$expect" "$tmp/body" 2>/dev/null; then
          result=ok reason="HTTP ${code} with the app's own page (${expect}), not the file"
        else
          result=bad reason="HTTP ${code}: SERVED — $(served)"
        fi
        ;;
      3??)
        result=bad reason="HTTP ${code}, a redirect, is not a refusal (401, 403, 404 or 410)"
        ;;
      *)
        result=bad reason="HTTP ${code} is not a refusal (401, 403, 404 or 410): $(served)"
        ;;
    esac
    return
  fi
  if [ "$mode" = page ]; then
    if grep -qF -- "$expect" "$tmp/body" 2>/dev/null; then
      result=ok reason="HTTP ${code}, page carries ${expect}"
    else
      result=bad reason="HTTP ${code}, page does not carry ${expect}: $(snippet "$tmp/body")"
    fi
    return
  fi
  if ! jq -e . "$tmp/body" >/dev/null 2>&1; then
    result=bad reason="HTTP ${code}, not JSON: $(snippet "$tmp/body")"
    return
  fi
  if ! jq -e "$expect" "$tmp/body" >/dev/null 2>&1; then
    result=bad reason="HTTP ${code}, JSON does not satisfy ${expect}: $(snippet "$tmp/body")"
    return
  fi
  if [ -n "$warn" ] && ! jq -e "$warn" "$tmp/body" >/dev/null 2>&1; then
    result=warn reason="HTTP ${code}, reached the app but ${warn} is not true: $(snippet "$tmp/body")"
    return
  fi
  result=ok reason="HTTP ${code}"
}

# One request from the runner.
fetch_runner() {
  local out code
  out="$(curl -sS --max-time "$timeout" -o "$tmp/body" -w '%{http_code} %{content_type}' ${range:+-r "$range"} \
    -H 'Accept: application/json, text/html;q=0.9' -H 'Cache-Control: no-cache' \
    -A 'aicountly-deploy-verify/1 (runner)' "$url" 2>"$tmp/err")" || out=000
  code="${out%% *}" ctype=""
  [ "$out" = "$code" ] || ctype="${out#* }"
  [ -f "$tmp/body" ] || : > "$tmp/body"
  if [ "$code" = 000 ]; then
    result=unreachable reason="no answer: $(snippet "$tmp/err")"
    return
  fi
  classify "$code"
}

# The same request, made by the server itself.
fetch_server() {
  local code trailer remote
  remote="curl -sS --max-time ${timeout} ${range:+-r $range} -H 'Accept: application/json, text/html;q=0.9' -H 'Cache-Control: no-cache' -A 'aicountly-deploy-verify/1 (server)' -w '\n__VERIFY_HTTP_CODE__%{http_code} %{content_type}' $(printf '%q' "$url")"
  # shellcheck disable=SC2086 # the prefix is a command and its arguments
  if ! $ssh_prefix "$remote" > "$tmp/raw" 2>"$tmp/err"; then
    : # curl's own failure still prints the trailer; judged below
  fi
  trailer="$(sed -n 's/^__VERIFY_HTTP_CODE__//p' "$tmp/raw" | tail -n 1)"
  code="${trailer%% *}" ctype=""
  [ "$trailer" = "$code" ] || ctype="${trailer#* }"
  sed '/^__VERIFY_HTTP_CODE__/d' "$tmp/raw" > "$tmp/body"
  if [ -z "$code" ] || [ "$code" = 000 ]; then
    result=bad reason="no answer from the server's own request: $(snippet "$tmp/err")"
    return
  fi
  classify "$code"
}

annotate() { # level message
  echo "::$1 title=Post-deploy check::${label}: $2"
}

# Up to $attempts requests from one place; result and reason hold its last answer.
ask() { # place
  local try
  for try in $(seq 1 "$attempts"); do
    "fetch_$1"
    case "$result" in
      ok | warn) return ;;
      splash)
        echo "${label}: $1 attempt ${try}: ${reason}"
        # The runner is not let through on a retry; ask the server at once.
        [ "$1" = runner ] && return
        ;;
      unreachable | bad) echo "${label}: $1 attempt ${try}: ${reason}" ;;
    esac
    [ "$try" -lt "$attempts" ] && sleep $((try * 3))
  done
}

place=runner
if [ "$mode" = absent ] && [ -n "$ssh_prefix" ]; then
  # A path that must not be served: never asked from the runner (see absent above).
  place=server
fi
ask "$place"
if [ "$place" = server ]; then
  : # asked from the server only; its answer is the answer
elif [ "$result" = splash ] || [ "$result" = unreachable ]; then
  # The runner never reached the app; asking the server is the only way to get the answer.
  if [ -n "$ssh_prefix" ]; then
    place=server
    ask server
    if [ "$result" = ok ] || [ "$result" = warn ]; then
      annotate notice "the runner could not reach the app (anti-bot page or no connection); the server's own request did (${reason})"
    fi
  else
    reason="${reason}; no VERIFY_SSH to ask from the server"
  fi
elif [ "$force_server" = 1 ] && { [ "$result" = ok ] || [ "$result" = warn ]; }; then
  # A real answer from the runner, but the server must prove it can stand in for it.
  runner_result="$result" runner_reason="$reason"
  ask server
  if [ "$result" = ok ] || [ "$result" = warn ]; then
    echo "${label}: the server's own request got the real answer too (${reason})"
    result="$runner_result" reason="$runner_reason"
  else
    result=bad reason="the runner reached the app, but the server's own request, the fallback for when the runner is shown the anti-bot page, did not: ${reason}"
  fi
fi

case "$result" in
  ok)
    echo "${label}: OK from the ${place} — ${reason}"
    exit 0
    ;;
  warn)
    annotate warning "$reason"
    exit 0
    ;;
esac
if [ "$severity" = warning ]; then
  annotate warning "$reason"
  exit 0
fi
annotate error "$reason"
exit 1
