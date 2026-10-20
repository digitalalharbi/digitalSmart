#!/usr/bin/env bash
#
# One line saying whether this checkout, GitHub and production agree.
#
#   ● main@dc05b45  ● Synced  ● CI green  ● Main green  ○ Deploy waiting
#
# Green = fine · amber = running or pending · red = failed · grey = waiting on something outside this repository, or
# unknown (offline). Each source is read once — no loop, no polling; run it again to refresh.
#
# Usage:  scripts/sync-status.sh [--fetch] [--plain] [--json]
#   --fetch   `git fetch origin` first, so "behind" is current (otherwise local refs are used)
#   --plain   no colour (also automatic when stdout is not a terminal)
#   --json    machine-readable, for the Claude Code status line or a notifier
#
# Developer tooling: it reads `git` and the GitHub CLI, and holds no credential of its own.

set -uo pipefail

FETCH=0 PLAIN=0 JSON=0
for arg in "$@"; do
  case "$arg" in
    --fetch) FETCH=1 ;;
    --plain) PLAIN=1 ;;
    --json) JSON=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done
[[ -t 1 ]] || PLAIN=1

[[ $FETCH -eq 1 ]] && git fetch -q origin 2>/dev/null

branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
sha="$(git rev-parse --short=7 HEAD 2>/dev/null || echo '?')"

# ---- Git: synced / changes pending / ahead / behind --------------------------------------------------------------
git_state=green git_text="Synced"
if [[ -n "$(git status --porcelain 2>/dev/null)" ]]; then
  git_state=amber git_text="Changes pending"
elif upstream="$(git rev-parse --abbrev-ref '@{upstream}' 2>/dev/null)"; then
  read -r behind ahead < <(git rev-list --left-right --count "$upstream"...HEAD 2>/dev/null || echo "0 0")
  if [[ "${ahead:-0}" -gt 0 ]]; then git_state=amber git_text="Ahead ${ahead} (not pushed)"
  elif [[ "${behind:-0}" -gt 0 ]]; then git_state=amber git_text="Behind ${behind}"
  fi
else
  git_state=amber git_text="Not pushed"
fi

# ---- GitHub Actions ----------------------------------------------------------------------------------------------
have_gh=1
command -v gh >/dev/null 2>&1 || have_gh=0

# state of the latest run of a workflow on a branch -> "green|amber|red|grey|Text"
run_state() { # $1 branch  $2 workflow name
  [[ $have_gh -eq 1 ]] || { echo "grey|unknown"; return; }
  local row
  row="$(gh run list --branch "$1" --workflow "$2" --limit 1 --json status,conclusion --jq '.[0]|"\(.status) \(.conclusion)"' 2>/dev/null)" \
    || { echo "grey|offline"; return; }
  case "$row" in
    ""|"null null"|"null "*) echo "grey|no run" ;;
    completed\ success) echo "green|green" ;;
    completed\ failure|completed\ timed_out) echo "red|failed" ;;
    completed\ cancelled|completed\ skipped) echo "grey|cancelled" ;;
    *) echo "amber|running" ;;
  esac
}

branch_ci="grey|n/a"
[[ "$branch" != "main" && "$branch" != "?" ]] && branch_ci="$(run_state "$branch" CI)"
main_ci="$(run_state main CI)"

# Deployment: the workflow reports success while no server exists, so the repository variable is what says whether
# a deploy is possible at all. Without it the honest state is "waiting", not green.
deploy_state="grey|waiting (no server)"
if [[ $have_gh -eq 1 ]]; then
  enabled="$(gh variable get PRODUCTION_ENABLED 2>/dev/null || true)"
  if [[ "$enabled" == "true" ]]; then deploy_state="$(run_state main 'Deploy Production')"; fi
fi

split() { echo "${1%%|*}" "${1#*|}"; }
read -r ci_c ci_t <<<"$(split "$branch_ci")"
read -r m_c m_t <<<"$(split "$main_ci")"
read -r d_c d_t <<<"$(split "$deploy_state")"

if [[ $JSON -eq 1 ]]; then
  printf '{"branch":"%s","sha":"%s","git":{"state":"%s","text":"%s"},"ci":{"state":"%s","text":"%s"},"main":{"state":"%s","text":"%s"},"deploy":{"state":"%s","text":"%s"}}\n' \
    "$branch" "$sha" "$git_state" "$git_text" "$ci_c" "$ci_t" "$m_c" "$m_t" "$d_c" "$d_t"
  exit 0
fi

dot() { # state text
  if [[ $PLAIN -eq 1 ]]; then
    case "$1" in green) printf '[ok] ' ;; amber) printf '[..] ' ;; red) printf '[!!] ' ;; *) printf '[--] ' ;; esac
    printf '%s' "$2"; return
  fi
  local c=90
  case "$1" in green) c=32 ;; amber) c=33 ;; red) c=31 ;; esac
  printf '\033[%sm●\033[0m %s' "$c" "$2"
}

printf '%s@%s  ' "$branch" "$sha"
dot "$git_state" "$git_text"; printf '  '
[[ "$branch" != "main" ]] && { dot "$ci_c" "CI $ci_t"; printf '  '; }
dot "$m_c" "Main $m_t"; printf '  '
dot "$d_c" "Deploy $d_t"; printf '\n'
