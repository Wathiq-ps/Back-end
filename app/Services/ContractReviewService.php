<?php

namespace App\Services;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Models\AnalysisFinding;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The lawyer's review, after the AI analysis lands (M4): decide each finding,
 * edit clauses into a new version, then approve (UC-027) or send the contract
 * back for modification (UC-070). Only the assigned lawyer acts here.
 *
 * Every status change runs in a transaction that sets wathiq.actor_id and
 * wathiq.transition_reason first, so the contract_status_history row the
 * trigger writes says who moved the contract and why.
 */
class ContractReviewService
{
    /** A finding of the latest analysis is accepted or rejected by the lawyer. */
    public function resolveFinding(User $lawyer, Contract $contract, AnalysisFinding $finding, string $resolution, ?string $note): AnalysisFinding
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        if ($finding->analysis_id !== $contract->latestAnalysis?->id) {
            abort(404);
        }
        if ($contract->status !== 'pending_lawyer_review') {
            abort(409, 'Findings are decided while the contract is pending your review.');
        }
        if ($finding->resolution === 'superseded') {
            abort(409, 'This finding was about a version that has since been replaced.');
        }

        $finding->update([
            'resolution' => $resolution,
            'resolved_by' => $lawyer->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        return $finding;
    }

    /**
     * The lawyer's edit: the changed clauses, keyed by ordinal, over the
     * current version's. Versions and clauses are immutable, so this is a new
     * version. Findings still open on the analysed text are superseded — they
     * were about wording that no longer exists. On a contract sent back for
     * modification, saving the new version is its resubmission.
     *
     * @param  array<int, string>  $edits  ordinal => new clause body
     */
    public function saveVersion(User $lawyer, Contract $contract, array $edits, ?string $changeNote): ContractVersion
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        return DB::transaction(function () use ($lawyer, $contract, $edits, $changeNote) {
            $contract = $this->lock($contract);

            if (! in_array($contract->status, ['pending_lawyer_review', 'requires_modification'], true)) {
                abort(409, 'The contract can be edited only while it is under your review.');
            }

            $clauses = $contract->currentVersion->clauses()->orderBy('ordinal')->get();
            $unknown = array_diff(array_keys($edits), $clauses->pluck('ordinal')->all());
            if ($unknown) {
                abort(422, 'No clause with ordinal '.implode(', ', $unknown).' in the current version.');
            }

            $clauses = $clauses->map(fn ($clause) => [
                'ordinal' => $clause->ordinal,
                'kind' => $clause->kind,
                'heading' => $clause->heading,
                'body' => $edits[$clause->ordinal] ?? $clause->body,
                'is_ai_generated' => $clause->is_ai_generated && ! array_key_exists($clause->ordinal, $edits),
            ]);

            // Same layout the AI drafts in: clauses in order, a blank line apart.
            $body = $clauses->pluck('body')->implode("\n\n");
            $hash = hash('sha256', $body);

            if ($hash === $contract->currentVersion->content_hash) {
                abort(422, 'The edit does not change the contract.');
            }

            // contract_versions_hash_key allows one row per body: going back
            // to an earlier wording points at that version again.
            $version = $contract->versions()->where('content_hash', $hash)->first()
                ?? $this->createVersion($lawyer, $contract, $body, $hash, $clauses->all(), $changeNote);

            $contract->update(['current_version_id' => $version->id]);

            $contract->latestAnalysis?->findings()->where('resolution', 'open')->update([
                'resolution' => 'superseded',
                'resolved_by' => $lawyer->id,
                'resolved_at' => now(),
                'resolution_note' => "Superseded by version {$version->version_no}.",
            ]);

            if ($contract->status === 'requires_modification') {
                $this->transition($lawyer, $contract, 'pending_lawyer_review', $changeNote);
            }

            return $version;
        });
    }

    /** UC-027. Every finding must be decided (or superseded) first. */
    public function approve(User $lawyer, Contract $contract): Contract
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        return DB::transaction(function () use ($lawyer, $contract) {
            $contract = $this->lock($contract);

            if ($contract->status !== 'pending_lawyer_review') {
                abort(409, 'Only a contract pending your review can be approved.');
            }

            $open = $contract->latestAnalysis?->findings()->where('resolution', 'open')->count() ?? 0;
            if ($open > 0) {
                abort(409, "{$open} finding(s) still need your decision.");
            }

            $this->transition($lawyer, $contract, 'approved');

            return $contract;
        });
    }

    /** UC-070. The reason is what the parties see, so it is required. */
    public function requestModification(User $lawyer, Contract $contract, string $reason): Contract
    {
        $this->assertAssignedLawyer($lawyer, $contract);

        return DB::transaction(function () use ($lawyer, $contract, $reason) {
            $contract = $this->lock($contract);

            if ($contract->status !== 'pending_lawyer_review') {
                abort(409, 'Only a contract pending your review can be sent back.');
            }

            $this->transition($lawyer, $contract, 'requires_modification', $reason);

            return $contract;
        });
    }

    private function createVersion(User $lawyer, Contract $contract, string $body, string $hash, array $clauses, ?string $changeNote): ContractVersion
    {
        $version = $contract->versions()->create([
            'tenant_id' => $contract->tenant_id,
            'version_no' => (int) $contract->versions()->max('version_no') + 1,
            'body' => $body,
            'body_format' => 'plain',
            'content_hash' => $hash,
            'author_type' => 'lawyer',
            'author_id' => $lawyer->id,
            'change_note' => $changeNote,
        ]);

        foreach ($clauses as $clause) {
            $version->clauses()->create(['tenant_id' => $contract->tenant_id, ...$clause]);
        }

        return $version;
    }

    /** Must run inside the caller's transaction: the GUCs are transaction-local. */
    private function transition(User $actor, Contract $contract, string $to, ?string $reason = null): void
    {
        DB::select(
            "select set_config('wathiq.actor_id', ?, true), set_config('wathiq.transition_reason', ?, true)",
            [$actor->id, (string) $reason],
        );

        $contract->update(['status' => $to]);
    }

    /** Re-read under a row lock, so two reviews of one contract can't interleave. */
    private function lock(Contract $contract): Contract
    {
        return Contract::whereKey($contract->id)->lockForUpdate()->with('currentVersion')->firstOrFail();
    }

    private function assertAssignedLawyer(User $user, Contract $contract): void
    {
        if ($contract->lawyer_id !== $user->id) {
            throw AuthorizationFailedException::forbidden();
        }
    }
}
