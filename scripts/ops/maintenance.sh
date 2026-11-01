#!/usr/bin/env bash
# maintenance.sh — switch this product's site into or out of maintenance, from the server.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/scripts/ops/maintenance.sh — identical in every
# product at scripts/ops/maintenance.sh. COPY, DON'T EDIT.
#
#   bash scripts/ops/maintenance.sh on  <docroot> [--reason=…] [--until=…] [--because=…] [--allow=IP,IP]
#   bash scripts/ops/maintenance.sh off <docroot>
#   bash scripts/ops/maintenance.sh status <docroot>
#
# The switch is the file <docroot>/.maintenance. Apache stats it on every request (see the
# maintenance block in .htaccess), so nothing here needs PHP, the database or a deploy — which is
# the point: this is for the window when those are the things that are broken.
#
#   --reason=   one line shown as "Status" on the page. Default: planned maintenance.
#   --until=    when service is expected back. ISO 8601 ("2026-11-01T20:00:00Z") renders as a local
#               time in the visitor's own zone; anything else is shown verbatim.
#   --because=  the NAME of the upstream service that forced this one down, e.g. --because=Inventory.
#               Use it on a product you are pausing only because something it depends on is down:
#               the page then says so instead of implying this product is at fault.
#   --allow=    comma-separated IPs that still get the real site, so you can verify a release before
#               switching maintenance off rather than after.
#
# Exit: 0 done · 2 bad usage or unwritable docroot.
set -uo pipefail

action="${1:-}"
root="${2:-}"
shift 2 2>/dev/null || true

usage() {
  sed -n '3,30p' "$0" >&2
  exit 2
}

case "$action" in on | off | status) ;; *) usage ;; esac
[ -n "$root" ] || usage
[ -d "$root" ] || { echo "maintenance: no such document root: $root" >&2; exit 2; }

flag="$root/.maintenance"
notice="$root/.maintenance.json"
allow="$root/.maintenance-allow"

reason=""; until_=""; because=""; allow_ips=""
for arg in "$@"; do
  case "$arg" in
    --reason=*)  reason="${arg#--reason=}" ;;
    --until=*)   until_="${arg#--until=}" ;;
    --because=*) because="${arg#--because=}" ;;
    --allow=*)   allow_ips="${arg#--allow=}" ;;
    *) echo "maintenance: unknown option: $arg" >&2; usage ;;
  esac
done

# JSON-escape: the reason is free text typed by a human under time pressure. A stray quote would
# produce a malformed notice, the page's fetch would throw, and it would silently fall back to the
# generic copy — losing exactly the message someone bothered to write.
esc() { printf '%s' "${1:-}" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/ /g' | tr -d '\n\r'; }

case "$action" in
  status)
    if [ -f "$flag" ]; then
      echo "maintenance: ON  ($root)"
      [ -f "$notice" ] && { echo "  notice:"; sed 's/^/    /' "$notice"; }
      [ -f "$allow" ]  && { echo "  allowed IPs: $(tr '\n' ' ' < "$allow")"; }
    else
      echo "maintenance: off ($root)"
    fi
    ;;

  on)
    : > "$flag" || { echo "maintenance: cannot write $flag" >&2; exit 2; }

    {
      printf '{\n'
      printf '  "status": "maintenance",\n'
      printf '  "product": "%s",\n' "$(esc "Messaging")"
      printf '  "domain": "%s",\n'  "$(esc "messaging.aicountly.com")"
      printf '  "started": "%s"'    "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      [ -n "$reason" ]  && printf ',\n  "reason": "%s"'  "$(esc "$reason")"
      [ -n "$until_" ]  && printf ',\n  "until": "%s"'   "$(esc "$until_")"
      [ -n "$because" ] && printf ',\n  "because": "%s"' "$(esc "$because")"
      printf '\n}\n'
    } > "$notice" || { echo "maintenance: cannot write $notice" >&2; exit 2; }

    if [ -n "$allow_ips" ]; then
      printf '%s\n' "$allow_ips" | tr ',' '\n' | sed '/^[[:space:]]*$/d' > "$allow"
    else
      rm -f "$allow"
    fi

    # 0644: Apache must be able to read the notice to serve it, and other products' pages read it
    # cross-origin. The flag itself is denied in .htaccess, so readable is not servable.
    chmod 0644 "$flag" "$notice" 2>/dev/null || true
    [ -f "$allow" ] && chmod 0644 "$allow" 2>/dev/null || true

    echo "maintenance: ON  ($root)"
    [ -n "$because" ] && echo "  reported as caused by: $because"
    echo "  verify: curl -sS -o /dev/null -w '%{http_code}\\n' https://messaging.aicountly.com/   # expect 503"
    ;;

  off)
    rm -f "$flag" "$notice" "$allow"
    echo "maintenance: off ($root)"
    echo "  verify: curl -sS -o /dev/null -w '%{http_code}\\n' https://messaging.aicountly.com/   # expect 200"
    ;;
esac
