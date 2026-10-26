#!/usr/bin/env bash
# deploy-window.sh — production deploys run outside business hours unless the owner allowed one.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/scripts/ops/deploy-window.sh — identical in every product
# at scripts/ops/deploy-window.sh. The production deploy workflow runs it as its FIRST step:
#
#   bash scripts/ops/deploy-window.sh production "$BUSINESS_HOURS_REASON"
#
# Owner's rule (2026-10-26): production deploys after 20:00 IST; during business hours only with the
# owner's explicit permission, for an exigency. A deploy is a window of risk for the people using the app
# (it used to rsync over the live code), so it happens when they are not.
#
#  - environment other than "production": always allowed (sandbox first, any time)
#  - 20:00-08:00 IST (DEPLOY_WINDOW_OPEN / DEPLOY_WINDOW_CLOSE, HHMM): allowed
#  - 08:00-20:00 IST: refused (exit 1) unless the second argument says who allowed it and why (at least
#    15 characters); the reason goes into the job summary and the log
#
# DEPLOY_WINDOW_NOW=HHMM overrides the clock (tests).
set -uo pipefail
environment="${1:-}"
reason="$(printf '%s' "${2:-}" | tr -s '[:space:]' ' ' | sed 's/^ //; s/ $//')"
open="${DEPLOY_WINDOW_OPEN:-2000}"
close="${DEPLOY_WINDOW_CLOSE:-0800}"
now="${DEPLOY_WINDOW_NOW:-$(TZ=Asia/Kolkata date +%H%M)}"
summary="${GITHUB_STEP_SUMMARY:-/dev/null}"
for v in "$open" "$close" "$now"; do
  case "$v" in [0-2][0-9][0-5][0-9]) ;; *) echo "::error::deploy-window: bad time '$v' (HHMM)"; exit 2 ;; esac
done
if [ "$environment" != production ]; then
  echo "deploy-window: $environment deploys are allowed at any time"
  exit 0
fi
n=$((10#$now)) o=$((10#$open)) c=$((10#$close))
if [ "$o" -gt "$c" ]; then inside=$(( n >= o || n < c )); else inside=$(( n >= o && n < c )); fi
pretty="${now:0:2}:${now:2:2} IST"
if [ "$inside" = 1 ]; then
  echo "deploy-window: $pretty is inside the production deploy window (${open:0:2}:${open:2:2}-${close:0:2}:${close:2:2} IST)"
  exit 0
fi
if [ "${#reason}" -ge 15 ]; then
  echo "::warning::Production deploy during business hours ($pretty), allowed by: $reason"
  {
    echo "### Production deploy during business hours"
    echo ""
    echo "- Time: $pretty"
    echo "- Started by: ${GITHUB_ACTOR:-unknown}"
    echo "- Permission and reason: $reason"
  } >> "$summary"
  exit 0
fi
echo "::error::Production deploys run between ${open:0:2}:${open:2:2} and ${close:0:2}:${close:2:2} IST; it is $pretty. For an exigency the owner can allow one: re-run with business_hours_reason saying who allowed it and why (at least 15 characters)."
exit 1
