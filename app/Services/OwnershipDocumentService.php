<?php

namespace App\Services;

use App\Models\OwnershipDocument;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * FR-2.2/FR-2.4, UC-004. The admin side of what PropertyService::create()
 * starts: an owner submits a listing with its ownership documents and the
 * listing sits at `pending_verification` until an admin has ruled on those
 * documents. Shaped like KycService and LawyerCredentialService — same
 * submit → queue → approve/reject lifecycle, same never-a-public-URL posture
 * for the stored file.
 *
 * The one thing this adds over those two: a document decision also moves the
 * *property*, since the BR the 000304_* migration documents ("a property may
 * not be published until at least one ownership document is approved") spans
 * two tables and so has to live in code.
 */
class OwnershipDocumentService
{
    private const DISK = 'local';

    /**
     * Statuses that mean "still waiting on an admin".
     */
    public const OPEN_STATUSES = ['pending', 'under_review'];

    public function approve(OwnershipDocument $document, User $reviewer): OwnershipDocument
    {
        return DB::transaction(function () use ($document, $reviewer) {
            $document->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->syncPropertyStatus($document->property);

            return $document->refresh();
        });
    }

    /**
     * ownership_documents_rejection_has_reason makes the reason mandatory at
     * the DB level too — the form request is what turns a missing one into a
     * 422 rather than a constraint-violation 500.
     */
    public function reject(OwnershipDocument $document, User $reviewer, string $reason): OwnershipDocument
    {
        return DB::transaction(function () use ($document, $reviewer, $reason) {
            $document->forceFill([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->syncPropertyStatus($document->property);

            return $document->refresh();
        });
    }

    /**
     * Derives the listing's status from the documents attached to it, so an
     * admin never sets property status by hand and the "at least one approved
     * document" invariant can't drift:
     *
     *   at least one approved    -> released to `draft`, the only status
     *                               PropertyService::publish() accepts.
     *                               Publishing stays the owner's decision.
     *   none approved, some open -> back to `pending_verification`; the review
     *                               isn't finished yet.
     *   none approved, none open -> `rejected`; every document was refused.
     *
     * Reversals are covered in both directions: approving a document on a
     * previously `rejected` listing reopens it, and rejecting the last
     * approved document pulls a `published` listing back down (published_at
     * cleared with it, so properties_published_has_timestamp stays honest).
     * A listing already `published` off an approved document is not demoted to
     * `draft` when a second one is approved — that would silently unpublish it.
     *
     * A listing under an active contract is left alone: a late document
     * decision must not yank a property out from under a signed deal; that
     * unwinds through the contract, not here.
     */
    private function syncPropertyStatus(Property $property): void
    {
        if (in_array($property->status, ['under_contract', 'sold', 'rented'], true)) {
            return;
        }

        $statuses = OwnershipDocument::where('property_id', $property->id)
            ->pluck('status')
            ->all();

        $target = match (true) {
            in_array('approved', $statuses, true) => 'draft',
            array_intersect(self::OPEN_STATUSES, $statuses) !== [] => 'pending_verification',
            default => 'rejected',
        };

        if ($target === 'draft' && $property->status === 'published') {
            return;
        }

        if ($property->status === $target) {
            return;
        }

        $property->status = $target;

        if ($target !== 'draft') {
            $property->published_at = null;
        }

        $property->save();
    }

    /**
     * @return array{contents: string, mime: string}
     */
    public function readDocument(OwnershipDocument $document): array
    {
        $disk = Storage::disk(self::DISK);

        abort_unless($document->path && $disk->exists($document->path), 404);

        return [
            'contents' => $disk->get($document->path),
            'mime' => $disk->mimeType($document->path) ?: 'application/octet-stream',
        ];
    }
}
