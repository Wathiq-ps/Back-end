<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Property\RejectPropertyRequestRequest;
use App\Http\Resources\ContractResource;
use App\Http\Resources\IncomingPropertyRequestResource;
use App\Models\PropertyRequest;
use App\Services\PropertyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assigned lawyer's side of a property request: the owner forwarded it
 * (status pending_lawyer_review), and the lawyer's decision is what settles
 * it. Accepting creates the contract and starts the AI draft.
 */
class PropertyRequestController extends Controller
{
    public function __construct(private readonly PropertyRequestService $requests) {}

    /** Requests assigned to this lawyer. Defaults to the ones awaiting a decision; ?status=all for every one. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending_lawyer_review');

        $query = PropertyRequest::query()
            ->where('lawyer_id', $request->user()->id)
            ->with(['property', 'requester'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            abort_unless(in_array($status, ['pending_lawyer_review', 'accepted', 'rejected'], true), 422, 'Invalid status filter.');
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

    public function accept(Request $request, PropertyRequest $propertyRequest): JsonResponse
    {
        $contract = $this->requests->lawyerAccept($request->user(), $propertyRequest);

        return response()->json([
            'message' => __('Request accepted. The contract is being drafted.'),
            'data' => new ContractResource($contract->load('latestAiJob')),
        ], 201);
    }

    public function reject(PropertyRequest $propertyRequest, RejectPropertyRequestRequest $request): JsonResponse
    {
        $propertyRequest = $this->requests->lawyerReject(
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
