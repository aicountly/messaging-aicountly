#!/usr/bin/env bash
# atomic-switch.sh — put a release live with ONE rename, so no request ever runs a mix of old and new code.
#
# CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/atomic-switch.sh — identical in every product at
# server-php/bin/ops/atomic-switch.sh (release.sh calls it when release.conf sets ATOMIC=1; products without
# release.sh call it from their deploy workflow over SSH).
#
# Why: deploys used to rsync the new code OVER the live api/ folder while it served requests. For the
# seconds (Books) to minutes (Inventory) that took, a request could load some new files and some old ones
# and die before answering: an empty HTTP 500, "The server hit an unexpected error and gave no details",
# sometimes after the change it carried had been saved.
#
# Layout (outside the document root, so no release is ever reachable by URL):
#   <releases-dir>/releases/<UTC time>-<revision>/   one complete copy of the code per deploy
#   <releases-dir>/shared/<path>                     runtime state every release shares (--shared)
#   <live>  ->  <releases-dir>/releases/<current>    the live path is a symlink, swapped by rename(2)
#
#   bash atomic-switch.sh --src=<prepared code> --live=<docroot>/api --revision=<sha> \
#        [--releases-dir=<dir>] [--shared=writable/logs ...] [--env-file=.env] [--keep=5] \
#        [--check-url=https://host/api/health]
#   bash atomic-switch.sh --rollback --live=<docroot>/api [--releases-dir=<dir>] [--check-url=...]
#   bash atomic-switch.sh --status   --live=<docroot>/api [--releases-dir=<dir>]
#
#  - The release folder is built completely BEFORE the switch: code copied from --src (hard-linked with
#    the current release where unchanged, so five kept releases cost little disk), the live .env (and
#    .env.* but .env.example) carried over from the CURRENT release so edits made on the server are kept,
#    each --shared path replaced by a symlink into shared/, REVISION written.
#  - The first run converts a plain api/ folder: each --shared path is moved into shared/ and a symlink
#    left in its place (the old code keeps writing to the same files), then api/ is renamed aside and the
#    symlink renamed into place — two renames in the same folder, microseconds apart. The old folder is
#    moved out of the document root afterwards and kept as releases/<time>-pre-atomic.
#  - --check-url: after the switch the URL must answer 2xx/3xx within 20 s, or the previous code is put
#    back at once (on the first run: the original plain folder). A host that does not follow symlinks
#    therefore costs one failed check, not an outage.
#  - PHP resolves the symlink per request (realpath cache up to ~2 min), so a worker may finish serving the
#    previous release for a short while — always a WHOLE release; previous releases are kept (--keep).
#
# Exit codes: 0 switched · 2 usage · 10 --src incomplete · 11 no .env to carry over · 17 switch failed
#             21 check failed, previous code restored · 22 check failed AND restore failed (act now)
#             23 nothing to roll back to
set -uo pipefail

src="" live="" revision="" releases_dir="" env_file=".env" keep=5 check_url="" mode=switch
shared=()
for arg in "$@"; do
  case "$arg" in
    --src=*) src="${arg#--src=}" ;;
    --live=*) live="${arg#--live=}" ;;
    --revision=*) revision="${arg#--revision=}" ;;
    --releases-dir=*) releases_dir="${arg#--releases-dir=}" ;;
    --shared=*) shared+=("${arg#--shared=}") ;;
    --env-file=*) env_file="${arg#--env-file=}" ;;
    --keep=*) keep="${arg#--keep=}" ;;
    --check-url=*) check_url="${arg#--check-url=}" ;;
    --rollback) mode=rollback ;;
    --status) mode=status ;;
    *) echo "atomic-switch: unknown option $arg" >&2; exit 2 ;;
  esac
done
[ -n "$live" ] || { echo "atomic-switch: --live is required" >&2; exit 2; }
case "$keep" in '' | *[!0-9]*) echo "atomic-switch: --keep must be a number" >&2; exit 2 ;; esac
[ "$keep" -ge 2 ] || keep=2
live="${live%/}"
case "$live" in /*) ;; *) live="$PWD/$live" ;; esac
parent="$(cd "$(dirname "$live")" 2>/dev/null && pwd)" || { echo "atomic-switch: $(dirname "$live") does not exist" >&2; exit 2; }
live="$parent/$(basename "$live")"
case "$live" in / | "$HOME" | "$HOME/" | /home | /root | /usr* | /etc* | /var | /bin* | /lib*)
  echo "atomic-switch: refusing --live=$live" >&2; exit 2 ;;
esac
if [ -z "$releases_dir" ]; then
  # One folder per site and path, outside the document root: ~/aicountly-releases/books.aicountly.com-api
  releases_dir="${HOME}/aicountly-releases/$(basename "$parent")-$(basename "$live")"
fi
case "$releases_dir" in /*) ;; *) releases_dir="${HOME}/${releases_dir}" ;; esac
case "$releases_dir/" in "$live/"*) echo "atomic-switch: --releases-dir must not be inside --live" >&2; exit 2 ;; esac
releases="$releases_dir/releases"
shared_root="$releases_dir/shared"

current_target() { [ -L "$live" ] && readlink "$live" || true; }

# A path in shared/ for a --shared entry (relative to api/, no leading or trailing slash, no "..").
norm_shared() {
  local p="${1#/}"; p="${p%/}"
  case "/$p/" in *"/../"* | *"/./"* | "//") return 1 ;; esac
  printf '%s' "$p"
}

check() { # url -> 0 when it answers 2xx/3xx
  [ -n "$check_url" ] || return 0
  local code i
  for i in 1 2 3; do
    code="$(curl -s -o /dev/null -m 20 -A 'aicountly-atomic-switch/1' -w '%{http_code}' "$check_url" 2>/dev/null || echo 000)"
    case "$code" in 2?? | 3??) echo "atomic-switch: check $check_url answered $code"; return 0 ;; esac
    sleep 2
  done
  echo "atomic-switch: check $check_url answered $code"
  return 1
}

# Point $live at $1 with a single rename (live already a symlink).
swap_to() {
  local tmp="$live.next.$$"
  rm -f "$tmp"
  ln -s "$1" "$tmp" && mv -Tf "$tmp" "$live"
}

if [ "$mode" = status ]; then
  echo "live:     $live"
  if [ -L "$live" ]; then echo "current:  $(current_target)"; else echo "current:  (plain folder — not yet atomic)"; fi
  echo "releases: $releases"
  ls -1 "$releases" 2>/dev/null | sed 's/^/  /'
  exit 0
fi

# Releases oldest first (their names start with a sortable UTC time). A conversion names the old plain folder
# <stamp>-pre-atomic and the first release <stamp>-<revision> with the SAME stamp, and a revision ("b9ea…") sorts
# before "pre-atomic" by name: the pre-atomic folder is ordered as "<stamp>-", before every release of its stamp,
# or --rollback right after a conversion would find nothing earlier and a later one would skip a release.
release_names() {
  [ -d "$releases" ] || return 0
  ls -1 "$releases" | awk '{ k = $0; sub(/-pre-atomic$/, "-", k); print k "\t" $0 }' | LC_ALL=C sort | cut -f2
}

# When a release stopped being live (releases_dir/.retired/<name> holds the time). PHP's realpath cache
# keeps resolving api/ to the release it last saw for up to ~2 minutes, so a worker may still be reading
# files from a release that was just replaced: it must not be deleted yet.
retire() { [ -n "${1:-}" ] || return 0; mkdir -p "$releases_dir/.retired" && date +%s > "$releases_dir/.retired/$(basename "$1")"; }
RETIRE_GRACE="${ATOMIC_SWITCH_RETIRE_GRACE:-600}"

prune() {
  [ -d "$releases" ] || return 0
  local cur r n total at now
  cur="$(basename "$(current_target)")"
  total="$(release_names | wc -l)"
  now="$(date +%s)"
  n=0
  # The newest --keep stay, the current one stays, and so does any release replaced less than
  # RETIRE_GRACE seconds ago (or never marked retired).
  while IFS= read -r r; do
    n=$((n + 1))
    [ "$r" = "$cur" ] && continue
    [ $((total - n)) -lt "$keep" ] && continue
    at="$(cat "$releases_dir/.retired/$r" 2>/dev/null || echo "")"
    case "$at" in '' | *[!0-9]*) continue ;; esac
    [ $((now - at)) -lt "$RETIRE_GRACE" ] && continue
    rm -rf -- "${releases:?}/$r" && rm -f "$releases_dir/.retired/$r"
  done < <(release_names)
}

if [ "$mode" = rollback ]; then
  [ -L "$live" ] || { echo "atomic-switch: $live is not a release symlink — nothing to roll back to"; exit 23; }
  cur="$(current_target)"
  prev=""
  # The release created just before the current one.
  while IFS= read -r r; do
    [ "$releases/$r" = "$cur" ] && break
    prev="$releases/$r"
  done < <(release_names)
  [ -n "$prev" ] && [ -d "$prev" ] || { echo "atomic-switch: no earlier release in $releases"; exit 23; }
  # A pre-atomic folder is a plain api/: it still serves, its shared paths are symlinks into shared/.
  swap_to "$prev" || exit 17
  retire "$cur"; rm -f "$releases_dir/.retired/$(basename "$prev")"
  echo "atomic-switch: rolled back $live -> $prev (was $cur)"
  if ! check; then
    swap_to "$cur" && { echo "atomic-switch: the earlier release failed its check; $cur is live again"; exit 21; }
    exit 22
  fi
  exit 0
fi

# ------------------------------------------------------------------ switch
[ -n "$src" ] && [ -n "$revision" ] || { echo "atomic-switch: --src and --revision are required" >&2; exit 2; }
# PHP caches how a path resolves (realpath_cache_ttl, 120 s by default). Right after a conversion, a
# worker may still hold "api is a plain folder" and so reach files THROUGH the symlink; a second switch in
# that window could hand one request two releases. One deploy switches once, so this only guards re-runs.
if [ -f "$releases_dir/.converted-at" ]; then
  since=$(( $(date +%s) - $(cat "$releases_dir/.converted-at" 2>/dev/null || echo 0) ))
  if [ "$since" -ge 0 ] && [ "$since" -lt 130 ]; then
    echo "atomic-switch: $live was converted ${since}s ago; PHP may still cache the old folder — retry in $((130 - since))s"
    exit 17
  fi
fi
src="$(cd "$src" 2>/dev/null && pwd)" || { echo "atomic-switch: --src does not exist"; exit 10; }
[ "$(ls -A "$src" 2>/dev/null)" ] || { echo "atomic-switch: --src is empty"; exit 10; }
[ -e "$live" ] || [ -L "$live" ] || { echo "atomic-switch: $live does not exist — the first deploy creates it (with its .env) the old way"; exit 11; }
[ -f "$live/$env_file" ] || { echo "atomic-switch: $live/$env_file does not exist — nothing to carry over"; exit 11; }
safe_rev="$(printf '%s' "$revision" | tr -c 'A-Za-z0-9._-' '_' | cut -c1-40)"
# Sortable to the nanosecond: release order is name order (rollback and pruning read it), never mtimes.
stamp="$(date -u +%Y%m%dT%H%M%S.%NZ)"
mkdir -p "$releases" "$shared_root" || exit 17
chmod 711 "$releases_dir" 2>/dev/null || true
rel="$releases/${stamp}-${safe_rev}"
[ ! -e "$rel" ] || rel="$rel-$$"
cur="$(current_target)"
link_from=""
if [ -n "$cur" ] && [ -d "$cur" ]; then link_from="$cur"; elif [ -d "$live" ]; then link_from="$live"; fi

echo "atomic-switch: building $rel"
excludes=(--exclude="/$env_file" --exclude='/.env.*' --exclude='/REVISION' --exclude='/REVISION.tmp')
for s in "${shared[@]}"; do
  p="$(norm_shared "$s")" || { echo "atomic-switch: bad --shared=$s" >&2; exit 2; }
  excludes+=(--exclude="/$p")
done
if command -v rsync >/dev/null 2>&1; then
  link=()
  # --checksum: a file is shared with the current release only when its CONTENT is the same. rsync's
  # default size-and-mtime test hard-linked a changed file of the same size and time stamp (seen in the
  # test), which would put old code in the new release.
  [ -n "$link_from" ] && link=(--checksum --link-dest="$link_from/")
  rsync -a "${link[@]}" "${excludes[@]}" "$src/" "$rel/" || { rm -rf -- "$rel"; echo "atomic-switch: copying the release failed"; exit 17; }
else
  mkdir -p "$rel" && ( cd "$src" && tar -cf - . ) | ( cd "$rel" && tar -xf - ) || { rm -rf -- "$rel"; exit 17; }
  rm -f "$rel/$env_file" "$rel/REVISION" "$rel/REVISION.tmp"
  find "$rel" -maxdepth 1 -name '.env.*' ! -name '.env.example' -exec rm -f {} + 2>/dev/null
fi
# The shipped example stays; every other .env file is the server's and comes from the current code.
[ -f "$src/.env.example" ] && cp -p "$src/.env.example" "$rel/.env.example"
umask 077
cp -p "$live/$env_file" "$rel/$env_file" || { rm -rf -- "$rel"; exit 11; }
for f in "$live"/.env.*; do
  [ -f "$f" ] || continue
  [ "$(basename "$f")" = .env.example ] && continue
  cp -p "$f" "$rel/$(basename "$f")"
done
umask 022

# Shared runtime state. On the first run it is MOVED out of the plain folder (same file system: a rename)
# and a symlink left behind, so the old code still serving writes to the very same files.
for s in "${shared[@]}"; do
  p="$(norm_shared "$s")"
  if [ ! -e "$shared_root/$p" ]; then
    mkdir -p "$(dirname "$shared_root/$p")"
    if [ ! -L "$live" ] && [ -d "$live/$p" ] && [ ! -L "$live/$p" ]; then
      if mv "$live/$p" "$shared_root/$p" 2>/dev/null; then
        ln -s "$shared_root/$p" "$live/$p" || { echo "atomic-switch: could not leave a symlink at $live/$p"; exit 17; }
      else
        cp -a "$live/$p" "$shared_root/$p" || exit 17
      fi
    elif [ -d "$src/$p" ]; then
      cp -a "$src/$p" "$shared_root/$p" || exit 17
    else
      mkdir -p "$shared_root/$p" || exit 17
    fi
  fi
  rm -rf -- "${rel:?}/$p"
  mkdir -p "$(dirname "$rel/$p")"
  ln -s "$shared_root/$p" "$rel/$p" || exit 17
done
printf '%s\n' "$revision" > "$rel/REVISION" && chmod 644 "$rel/REVISION"

# ------------------------------------------------------------------ the switch itself
previous="" converted=0
if [ -L "$live" ]; then
  previous="$cur"
  swap_to "$rel" || { echo "atomic-switch: the switch failed; $previous is still live"; exit 17; }
  echo "atomic-switch: $live -> $rel"
else
  # First run: the plain folder becomes releases/pre-atomic-<time>. Renamed aside in its own folder first
  # (same file system, instant), the symlink renamed into its place, then moved out of the document root.
  aside="$live.pre-atomic-$stamp"
  tmp="$live.next.$$"
  rm -f "$tmp"
  ln -s "$rel" "$tmp" || exit 17
  if ! mv -T "$live" "$aside"; then rm -f "$tmp"; echo "atomic-switch: could not move $live aside"; exit 17; fi
  if ! mv -T "$tmp" "$live"; then
    mv -T "$aside" "$live"; rm -f "$tmp"; echo "atomic-switch: the switch failed; the plain folder is back"; exit 17
  fi
  converted=1
  date +%s > "$releases_dir/.converted-at"
  echo "atomic-switch: $live -> $rel (converted from a plain folder)"
  previous="$releases/${stamp}-pre-atomic"
  if ! mv -T "$aside" "$previous" 2>/dev/null; then
    previous="$aside"   # another file system: leave it beside api/ (its .htaccess still guards it)
  fi
fi

if ! check; then
  echo "atomic-switch: the new release failed its check — putting the previous code back"
  if [ "$converted" = 1 ]; then
    # Undo the conversion completely: the plain folder goes back in place of the symlink (a host that
    # will not serve through a symlink must get a real folder again). Shared paths stay moved; the
    # symlinks left in the folder point at them.
    failed="$live.failed-$stamp"
    if mv -T "$live" "$failed" && mv -T "$previous" "$live"; then
      rm -f "$failed" "$releases_dir/.converted-at"
      echo "atomic-switch: the plain folder is back at $live"
      exit 21
    fi
    echo "::error::atomic-switch: the check failed and the plain folder could not be put back ($previous) — act now"
    exit 22
  fi
  if [ -n "$previous" ] && swap_to "$previous"; then
    echo "atomic-switch: $live -> $previous restored"
    exit 21
  fi
  echo "::error::atomic-switch: the check failed and the previous code could not be restored — act now"
  exit 22
fi
retire "$previous"
prune
echo "atomic-switch: live code is now ${revision}"
exit 0
