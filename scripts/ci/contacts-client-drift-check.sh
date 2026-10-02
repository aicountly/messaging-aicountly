#!/usr/bin/env bash
# Drift check for a vendored copy of the Contacts client.
#
#   clients/drift-check.sh <vendored file> [--latest]
#
# The vendored file's first line must be:
#   // source: contacts-react-app@<sha> clients/<php|js>/<file>
# The script fetches that file at <sha> from contacts-react-app and fails (exit 1) when the
# vendored copy differs from it (the header line itself is ignored). --latest additionally
# reports (exit 3) when the client on the integration branch / main is newer than <sha>.
#
# Source of contacts-react-app: $CONTACTS_REPO_DIR (a local clone) if set, otherwise a
# blob-less clone of https://github.com/aicountly/contacts-react-app into a temp dir
# (uses your normal git credentials; the repository is private).
set -euo pipefail

FILE="${1:?usage: drift-check.sh <vendored file> [--latest]}"
LATEST="${2:-}"
[ -f "$FILE" ] || { echo "no such file: $FILE" >&2; exit 2; }

HEADER="$(head -n 1 "$FILE")"
if ! [[ "$HEADER" =~ source:\ contacts-react-app@([0-9a-f]{7,40})\ (clients/[A-Za-z0-9_./-]+) ]]; then
  echo "first line must be: // source: contacts-react-app@<sha> clients/<path>  (got: $HEADER)" >&2
  exit 2
fi
SHA="${BASH_REMATCH[1]}"
SRC="${BASH_REMATCH[2]}"

REPO="${CONTACTS_REPO_DIR:-}"
CLEANUP=""
if [ -z "$REPO" ]; then
  REPO="$(mktemp -d)"
  CLEANUP="$REPO"
  git clone -q --filter=blob:none --no-checkout https://github.com/aicountly/contacts-react-app "$REPO"
fi
trap '[ -n "$CLEANUP" ] && rm -rf "$CLEANUP"' EXIT

git -C "$REPO" cat-file -e "$SHA^{commit}" 2>/dev/null || git -C "$REPO" fetch -q origin "$SHA" 2>/dev/null || true
UPSTREAM="$(git -C "$REPO" show "$SHA:$SRC")" || { echo "cannot read $SRC at $SHA" >&2; exit 2; }

if ! diff <(tail -n +2 "$FILE") <(printf '%s\n' "$UPSTREAM" | tail -n +2) >/dev/null; then
  echo "DRIFT: $FILE differs from contacts-react-app@$SHA:$SRC" >&2
  diff <(tail -n +2 "$FILE") <(printf '%s\n' "$UPSTREAM" | tail -n +2) | head -40 >&2 || true
  exit 1
fi
echo "ok: $FILE matches contacts-react-app@$SHA:$SRC"

if [ "$LATEST" = "--latest" ]; then
  for ref in origin/claude/youthful-curie-jmyecp origin/main; do
    git -C "$REPO" fetch -q origin "${ref#origin/}" 2>/dev/null || continue
    if ! git -C "$REPO" diff --quiet "$SHA" "$ref" -- "$SRC"; then
      echo "NEWER: $SRC changed on $ref since $SHA — re-vendor" >&2
      exit 3
    fi
  done
fi
