<?php

use App\Http\Controllers\Admin\IdentityDocumentController as AdminIdentityDocumentController;
use App\Http\Controllers\Admin\LawyerCredentialController as AdminLawyerCredentialController;
use App\Http\Controllers\Ai\AiCallbackController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Contract\ContractController;
use App\Http\Controllers\Kyc\IdentityDocumentController as KycIdentityDocumentController;
use App\Http\Controllers\Lawyer\LawyerCredentialController;
use App\Http\Controllers\Lawyer\PropertyRequestController as LawyerPropertyRequestController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Property\PropertyController;
use App\Http\Controllers\Property\PropertyRatingController;
use App\Http\Controllers\Property\PropertyRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    // NFR-4.5: per-IP throttling here is defense in depth on top of the
    // per-email resend cooldown enforced inside OtpService.
    Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:10,1');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:20,1');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth.jwt');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth.jwt');
});

// UC-040 step 1: name, nationality and signature must be on the profile
// before the KYC upload below will accept anything.
Route::prefix('v1/profile')->middleware('auth.jwt')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::patch('/', [ProfileController::class, 'update']);
});

// UC-040 step 2: the authenticated user submits their own documents.
Route::prefix('v1/kyc')->middleware('auth.jwt')->group(function () {
    Route::post('documents', [KycIdentityDocumentController::class, 'store']);
    Route::get('status', [KycIdentityDocumentController::class, 'status']); // this is for test
});

// FR-13.5: a lawyer registers through the same OTP flow as everyone else
// (registering with role=lawyer), verifies their identity via /v1/kyc like
// anyone else, and then proves the licence here. Submitting requires an
// approved ID; reading status never does, so a half-onboarded lawyer can
// always see where they stand.
Route::prefix('v1/lawyer')->middleware('auth.jwt')->group(function () {
    Route::post('credentials', [LawyerCredentialController::class, 'store'])->middleware('kyc.verified');
    Route::get('credentials/status', [LawyerCredentialController::class, 'status']);
});

// User endpoints
Route::get('v1/properties/search', [PropertyController::class, 'search']);

Route::get('v1/properties/home', [PropertyController::class, 'home']);

// FR-3.x: an owner submits a listing, which starts under review.
// lawyer.approved is a no-op for ordinary users and blocks lawyer-type
// accounts whose licence an admin hasn't approved yet — it belongs on every
// transacting group, alongside kyc.verified.
Route::prefix('v1/properties')->middleware(['auth.jwt', 'kyc.verified', 'lawyer.approved'])->group(function () {

    // Owner endpoints
    Route::get('/my-properties', [PropertyController::class, 'index']);
    Route::post('/', [PropertyController::class, 'store']);
    Route::patch('/{id}', [PropertyController::class, 'update']);
    Route::patch('/{id}/publish', [PropertyController::class, 'publish']);
    Route::patch('/{id}/suspend', [PropertyController::class, 'suspend']);
    Route::delete('/{id}', [PropertyController::class, 'destroy']);

    Route::get('/incoming-requests', [PropertyRequestController::class, 'incoming']);
    Route::patch('/requests/{propertyRequest}/reject', [PropertyRequestController::class, 'reject']);
    Route::patch('/requests/{propertyRequest}/accept', [PropertyRequestController::class, 'accept']);

    Route::post('/{id}/ratings', [PropertyRatingController::class, 'store']);

    // Requester-side(User): submit a request, and track the ones already sent.
    Route::post('/{id}/requests', [PropertyRequestController::class, 'store']);
    Route::get('/my-requests', [PropertyRequestController::class, 'mine']);
});

// The assigned lawyer decides a request the owner forwarded to them.
// Accepting it creates the contract and has the AI draft it.
Route::prefix('v1/lawyer/requests')->middleware(['auth.jwt', 'kyc.verified', 'lawyer.approved'])->group(function () {
    Route::get('/', [LawyerPropertyRequestController::class, 'index']);
    Route::patch('/{propertyRequest}/accept', [LawyerPropertyRequestController::class, 'accept'])->whereUuid('propertyRequest');
    Route::patch('/{propertyRequest}/reject', [LawyerPropertyRequestController::class, 'reject'])->whereUuid('propertyRequest');
});

// Contracts the caller is a party to. The AI work behind them is async:
// clients poll the contract and read its ai_job.status.
Route::prefix('v1/contracts')->middleware(['auth.jwt', 'kyc.verified', 'lawyer.approved'])->group(function () {
    Route::get('/', [ContractController::class, 'index']);
    Route::get('/{contract}', [ContractController::class, 'show'])->whereUuid('contract');
    Route::post('/{contract}/analysis', [ContractController::class, 'submitForAnalysis'])->whereUuid('contract');
    Route::post('/{contract}/generation', [ContractController::class, 'retryGeneration'])->whereUuid('contract');
});

// Results from the AI service. No JWT — the HMAC signature is the auth
// (see AiCallbackController).
Route::post('v1/ai/callback', AiCallbackController::class)->middleware('throttle:120,1');

// UC-031/032-style admin review queue for KYC submissions.
Route::prefix('v1/admin/kyc')->middleware(['auth.jwt', 'admin'])->name('admin.kyc.')->group(function () {
    Route::get('documents', [AdminIdentityDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{identityDocument}', [AdminIdentityDocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{identityDocument}/image/{which}', [AdminIdentityDocumentController::class, 'image'])->name('image');
    Route::post('documents/{identityDocument}/approve', [AdminIdentityDocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{identityDocument}/reject', [AdminIdentityDocumentController::class, 'reject'])->name('documents.reject');
});

// The same review queue shape, for lawyer licences (FR-13.5). Approving here
// is what makes an account assignable as a lawyer on a property request.
Route::prefix('v1/admin/lawyers')->middleware(['auth.jwt', 'admin'])->name('admin.lawyers.')->group(function () {
    Route::get('/', [AdminLawyerCredentialController::class, 'index'])->name('index');
    Route::get('/{lawyerCredential}', [AdminLawyerCredentialController::class, 'show'])->name('show');
    Route::get('/{lawyerCredential}/document', [AdminLawyerCredentialController::class, 'document'])->name('document');
    Route::post('/{lawyerCredential}/approve', [AdminLawyerCredentialController::class, 'approve'])->name('approve');
    Route::post('/{lawyerCredential}/reject', [AdminLawyerCredentialController::class, 'reject'])->name('reject');
});
