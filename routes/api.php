<?php

use App\Http\Controllers\Admin\IdentityDocumentController as AdminIdentityDocumentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Kyc\IdentityDocumentController as KycIdentityDocumentController;
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

Route::get('/user', [AuthController::class, 'me'])->middleware('auth.jwt');

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

// User endpoints
Route::get('v1/properties/search', [PropertyController::class, 'search']);

Route::get('v1/properties/home', [PropertyController::class, 'home']);

// FR-3.x: an owner submits a listing, which starts under review.
Route::prefix('v1/properties')->middleware(['auth.jwt', 'kyc.verified'])->group(function () {

    // Owner endpoints
    Route::get('/my-properties', [PropertyController::class, 'index']);
    Route::post('/', [PropertyController::class, 'store']);
    Route::patch('/{id}', [PropertyController::class, 'update']);
    Route::patch('/{id}/publish', [PropertyController::class, 'publish']);
    Route::patch('/{id}/suspend', [PropertyController::class, 'suspend']);
    Route::delete('/{id}', [PropertyController::class, 'destroy']);

    Route::get('/incoming-requests', [PropertyRequestController::class, 'incoming']);

    Route::post('/{id}/ratings', [PropertyRatingController::class, 'store']);

    // Requester-side(User): submit a request, and track the ones already sent.
    Route::post('/{id}/requests', [PropertyRequestController::class, 'store']);
    Route::get('/my-requests', [PropertyRequestController::class, 'mine']);
});

// UC-031/032-style admin review queue for KYC submissions.
Route::prefix('v1/admin/kyc')->middleware(['auth.jwt', 'admin'])->name('admin.kyc.')->group(function () {
    Route::get('documents', [AdminIdentityDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{identityDocument}', [AdminIdentityDocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{identityDocument}/image/{which}', [AdminIdentityDocumentController::class, 'image'])->name('image');
    Route::post('documents/{identityDocument}/approve', [AdminIdentityDocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{identityDocument}/reject', [AdminIdentityDocumentController::class, 'reject'])->name('documents.reject');
});
