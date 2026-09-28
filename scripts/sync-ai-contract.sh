#!/usr/bin/env bash
# The AI service's wire contract, vendored for the tests (tests/Contract/ai).
#
#   scripts/sync-ai-contract.sh <ai-commit-sha>   copy openapi.yaml + contract/ from that Ai commit
#   scripts/sync-ai-contract.sh --check           warn if Ai main's contract differs from the copy
#
# Wathiq-ps/Ai records real exchanges in contract/*.json (tests/test_contract_goldens.py);
# tests/Feature/AiContractTest.php replays them here. The copy is pinned by commit
# (tests/Contract/AI_CONTRACT_SHA) so the AI can move without breaking this repo's CI;
# --check only warns — re-sync on purpose, in a PR.
set -euo pipefail

repo="Wathiq-ps/Ai"
root="$(cd "$(dirname "$0")/.." && pwd)"
dest="$root/tests/Contract/ai"

fetch() { # <ref> <dir>
    curl -fsSL "https://codeload.github.com/$repo/tar.gz/$1" | tar -xz --strip-components=1 -C "$2"
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

if [[ "${1:-}" == "--check" ]]; then
    fetch main "$tmp"
    if diff -rq "$tmp/contract" "$dest/contract" >/dev/null 2>&1 && diff -q "$tmp/openapi.yaml" "$dest/openapi.yaml" >/dev/null 2>&1; then
        echo "AI contract up to date with $repo main."
    else
        echo "::warning::$repo main's wire contract differs from tests/Contract/ai (pinned $(cat "$root/tests/Contract/AI_CONTRACT_SHA")). Re-sync: scripts/sync-ai-contract.sh <sha>"
    fi
    exit 0
fi

sha="${1:?usage: $0 <ai-commit-sha> | --check}"
fetch "$sha" "$tmp"
rm -rf "$dest" && mkdir -p "$dest"
cp -r "$tmp/contract" "$dest/contract"
cp "$tmp/openapi.yaml" "$dest/openapi.yaml"
echo "$sha" > "$root/tests/Contract/AI_CONTRACT_SHA"
echo "Synced $repo@$sha into tests/Contract/ai."
