#!/usr/bin/env bash
# verify-live.sh — a post-deploy check that passes only on the app's real answer.
#
# The cPanel server's anti-bot splash ("One moment, please..." / "Please wait while your
# request is being verified...") answers some requests from GitHub's runners with HTTP 200 and
# an HTML challenge. A check that looks only at the status code passes on it without ever
# reaching the app; a check that looks at the body fails on it although the app is fine.
#
# So every check here needs the real answer: JSON that matches a jq filter, or a page that
# contains a string only this build has. When the runner is shown the splash — or cannot connect
# at all (the server's firewall may refuse GitHub's addresses) — the same request is made again
# from the server itself over SSH; the splash never challenges the server's own requests. A real
# but wrong answer (a 5xx, the wrong JSON) is not retried from the server: it is the answer.
# A check that gets the real answer from neither place fails.
#
# Usage:
#   verify-live.sh json <label> <url> <jq-filter> [<jq-filter-for-a-warning>]
#       The first filter must hold, or the check fails ("is this really the app?", e.g.
#       '.app == "Sales"'). The optional second one only warns when false ("is it healthy?",
#       e.g. '.usable == true') — for health that depends on configuration, not the deploy.
#   verify-live.sh page <label> <url> <fixed-string>
#       The page must contain the string, e.g. the hashed entry asset of the build just
#       deployed (grep -o 'assets/index-[^"]*\.js' web/dist/index.html).
#
# Environment:
#   VERIFY_SSH       command prefix that runs one shell command on the server, e.g.
#                    "ssh deploy-target" or "ssh -p 22 user@host". Unset: no retry from the
#                    server, so a splash fails the check.
#   VERIFY_SEVERITY  error (default: a failed check exits 1) or warning (annotate, exit 0).
#   VERIFY_ATTEMPTS  tries per place (default 3); VERIFY_TIMEOUT seconds per request (default 20).
#
# Canonical copy; keep every repository's scripts/ci/verify-live.sh identical to it.
# shellcheck disable=SC2329 # the fetchers run as "fetch_${place}"; the helpers from inside them
set -uo pipefail

mode="${1:-}" label="${2:-}" url="${3:-}" expect="${4:-}" warn="${5:-}"
case "$mode" in
  json | page) ;;
  *) echo "usage: $0 json <label> <url> <jq-filter> [<warn-filter>] | page <label> <url> <string>" >&2; exit 2 ;;
esac
if [ -z "$label" ] || [ -z "$url" ] || [ -z "$expect" ]; then
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
splash='request is being verified|<title>One moment, please'
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# The first 200 characters of a body, on one line, for the log.
snippet() {
  tr '\r\n\t' '   ' < "$1" | cut -c1-200
}

# Sets result (ok | warn | splash | bad) and reason from $tmp/body and HTTP code $1.
classify() {
  local code="$1"
  if grep -qiE "$splash" "$tmp/body" 2>/dev/null; then
    result=splash reason="got the host's anti-bot page (HTTP ${code}), not the app"
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
  local code
  code="$(curl -sS --max-time "$timeout" -o "$tmp/body" -w '%{http_code}' \
    -H 'Accept: application/json, text/html;q=0.9' -H 'Cache-Control: no-cache' \
    -A 'aicountly-deploy-verify/1 (runner)' "$url" 2>"$tmp/err")" || code=000
  [ -f "$tmp/body" ] || : > "$tmp/body"
  if [ "$code" = 000 ]; then
    result=unreachable reason="no answer: $(snippet "$tmp/err")"
    return
  fi
  classify "$code"
}

# The same request, made by the server itself.
fetch_server() {
  local code remote
  remote="curl -sS --max-time ${timeout} -H 'Accept: application/json, text/html;q=0.9' -H 'Cache-Control: no-cache' -A 'aicountly-deploy-verify/1 (server)' -w '\n__VERIFY_HTTP_CODE__%{http_code}' $(printf '%q' "$url")"
  # shellcheck disable=SC2086 # the prefix is a command and its arguments
  if ! $ssh_prefix "$remote" > "$tmp/raw" 2>"$tmp/err"; then
    : # curl's own failure still prints the trailer; judged below
  fi
  code="$(sed -n 's/^__VERIFY_HTTP_CODE__//p' "$tmp/raw" | tail -n 1)"
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

runner_splash=0 # 1 once the runner was shown the splash or could not connect
for place in runner server; do
  if [ "$place" = server ]; then
    [ -n "$ssh_prefix" ] || break
  fi
  for try in $(seq 1 "$attempts"); do
    "fetch_${place}"
    case "$result" in
      ok)
        if [ "$place" = server ] && [ "$runner_splash" = 1 ]; then
          annotate notice "the runner could not reach the app (anti-bot page or no connection); the server's own request did (${reason})"
        fi
        echo "${label}: OK from the ${place} — ${reason}"
        exit 0
        ;;
      warn)
        [ "$place" = server ] && [ "$runner_splash" = 1 ] && echo "${label}: the runner could not reach the app; checked from the server"
        annotate warning "$reason"
        exit 0
        ;;
      splash)
        [ "$place" = runner ] && runner_splash=1
        echo "${label}: ${place} attempt ${try}: ${reason}"
        # The runner is not let through on a retry; ask the server at once.
        [ "$place" = runner ] && break
        ;;
      unreachable)
        [ "$place" = runner ] && runner_splash=1
        echo "${label}: ${place} attempt ${try}: ${reason}"
        ;;
      bad)
        echo "${label}: ${place} attempt ${try}: ${reason}"
        ;;
    esac
    [ "$try" -lt "$attempts" ] && sleep $((try * 3))
  done
  # A real but wrong answer from the runner is the answer; asking the server only helps when the
  # runner never reached the app.
  [ "$place" = runner ] && [ "$result" != splash ] && [ "$result" != unreachable ] && break
done

if { [ "$result" = splash ] || [ "$result" = unreachable ]; } && [ -z "$ssh_prefix" ]; then
  reason="${reason}; no VERIFY_SSH to ask from the server"
fi
if [ "$severity" = warning ]; then
  annotate warning "$reason"
  exit 0
fi
annotate error "$reason"
exit 1
