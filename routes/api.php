<?php

use App\Http\Controllers\Admin\IdentityDocumentController as AdminIdentityDocumentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Kyc\IdentityDocumentController as KycIdentityDocumentController;
use App\Http\Controllers\Property\PropertyController;
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

// UC-040 KYC: the authenticated user submits their own documents.
Route::prefix('v1/kyc')->middleware('auth.jwt')->group(function () {
    Route::post('documents', [KycIdentityDocumentController::class, 'store']);
    Route::get('status', [KycIdentityDocumentController::class, 'status']); //this is for test
});

// FR-3.x: an owner submits a listing, which starts under review.
Route::prefix('v1/properties')->middleware(['auth.jwt', 'kyc.verified'])->group(function () {
    Route::post('/', [PropertyController::class, 'store']);
});

// UC-031/032-style admin review queue for KYC submissions.
Route::prefix('v1/admin/kyc')->middleware(['auth.jwt', 'admin'])->name('admin.kyc.')->group(function () {
    Route::get('documents', [AdminIdentityDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{identityDocument}', [AdminIdentityDocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{identityDocument}/image/{which}', [AdminIdentityDocumentController::class, 'image'])->name('image');
    Route::post('documents/{identityDocument}/approve', [AdminIdentityDocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/{identityDocument}/reject', [AdminIdentityDocumentController::class, 'reject'])->name('documents.reject');
});
