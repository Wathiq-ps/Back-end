<?php

namespace App\Http\Controllers\Property;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\RejectPropertyRequestRequest;
use App\Http\Requests\Property\SubmitPropertyRequestRequest;
use App\Http\Resources\IncomingPropertyRequestResource;
use App\Http\Resources\PropertyRequestResource;
use App\Models\PropertyRequest;
use App\Models\Tenant;
use App\Services\PropertyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyRequestController extends Controller
{
    public function __construct(private readonly PropertyRequestService $requests) {}

    /**
     * app.request_status is a native Postgres enum — same reasoning as
     * PropertyController::STATUSES: an unrecognised ?status must be
     * rejected before it reaches the query, or it's a 500, not "no match".
     */
    private const STATUSES = ['pending', 'accepted', 'rejected', 'cancelled', 'closed', 'expired'];

    public function store(SubmitPropertyRequestRequest $request, string $id): JsonResponse
    {
        $propertyRequest = $this->requests->submit(
            $request->user(),
            $id,
            $request->validated(),
        );

        return response()->json([
            'message' => __('Your request was submitted and is pending review.'),
            'data' => new PropertyRequestResource($propertyRequest),
        ], 201);
    }

    /**
     * The owner's inbox: every request against any property they own.
     * Defaults to 'pending' — the only ones that actually need a decision
     * (approve/reject) — since that's what this endpoint is for; pass
     * ?status=accepted|rejected|... or ?status=all to see the rest.
     */
    public function incoming(Request $request): JsonResponse
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $status = $request->query('status', 'pending');

        $query = PropertyRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('property', fn ($q) => $q->where('owner_id', $request->user()->id))
            ->with(['property', 'requester'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            abort_unless(in_array($status, self::STATUSES, true), 422, 'Invalid status filter.');
            $query->where('status', $status);
        }

        $propertyRequests = $query->paginate(20);

        return response()->json([
            'data' => IncomingPropertyRequestResource::collection($propertyRequests),
            'meta' => [
                'current_page' => $propertyRequests->currentPage(),
                'last_page' => $propertyRequests->lastPage(),
                'total' => $propertyRequests->total(),
            ],
        ]);
    }

    /**
     * The requester's own history: every request they've sent, any status.
     * Unlike incoming() there's no "needs action" framing here — the sender
     * is just tracking what happened, so nothing is filtered out by
     * default; ?status=pending|accepted|... narrows it down.
     */
    public function mine(Request $request): JsonResponse
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');
        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $status = $request->query('status');

        $query = PropertyRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('requester_id', $request->user()->id)
            ->with('property')
            ->orderByDesc('created_at');

        if ($status !== null) {
            abort_unless(in_array($status, self::STATUSES, true), 422, 'Invalid status filter.');
            $query->where('status', $status);
        }

        $propertyRequests = $query->paginate(20);

        return response()->json([
            'data' => PropertyRequestResource::collection($propertyRequests),
            'meta' => [
                'current_page' => $propertyRequests->currentPage(),
                'last_page' => $propertyRequests->lastPage(),
                'total' => $propertyRequests->total(),
            ],
        ]);
    }

    /**
     * Owner rejects a pending request against one of their own properties.
     * A reason is required — see RejectPropertyRequestRequest.
     */
    public function reject(PropertyRequest $propertyRequest, RejectPropertyRequestRequest $request): JsonResponse
    {
        $propertyRequest = $this->requests->reject(
            $request->user(),
            $propertyRequest,
            $request->validated('reason'),
        );

        return response()->json([
            'message' => __('Request rejected.'),
            'data' => new IncomingPropertyRequestResource($propertyRequest->fresh(['property', 'requester'])),
        ]);
    }
}
