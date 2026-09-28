# contract/ — the wire, recorded

What Wathiq-ps/Back-end's tests replay. `openapi.yaml` (repo root) describes
the wire; these files are real exchanges produced by this service's own code,
so they cannot disagree with it.

| File | What it holds |
|---|---|
| `generate_contract.succeeded.json` | a rent draft: 16 clauses in contract order, citations per clause |
| `generate_contract.failed.json` | a type the service cannot draft → `unsupported_contract_type` |
| `analyze_contract.succeeded.json` | an analysis sent as `clauses`: findings carry `ordinal`, coverage carries `ordinals` |
| `hmac.json` | one callback signature both sides must reproduce byte for byte |

Each exchange file is `{request, accepted, callback}`: the `POST /v1/jobs` body
Laravel sends, the 202 we answer (with `respond_within_seconds`), and the exact
callback body we sign and send back. Ids are fixed; `usage.latency_ms` is
recorded as 0.

They are regenerated, never hand-edited:

    UPDATE_GOLDENS=1 uv run pytest tests/test_contract_goldens.py

`tests/test_contract_goldens.py` fails CI here whenever the wire changes and
the goldens were not regenerated — that failure is the signal to tell Back-end
to re-sync.

## Using them from Back-end

Copy this folder and `openapi.yaml` from a pinned Ai commit, and record the
commit, for example:

    sha=<ai commit>
    gh api "repos/Wathiq-ps/Ai/tarball/$sha" | tar -xz --strip-components=1 -C /tmp/ai-contract
    cp -r /tmp/ai-contract/contract/. tests/Contract/ai/ && cp /tmp/ai-contract/openapi.yaml tests/Contract/ai/
    echo "$sha" > tests/Contract/AI_CONTRACT_SHA

Then replay each `callback` through `POST /api/v1/ai/callback` (swap in the
test's own `job_id` and `kb_version_id`) and assert what gets stored, and check
the signer against `hmac.json`.
