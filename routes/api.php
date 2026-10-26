<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/paddle', [\App\Http\Controllers\WebhookController::class, 'handlePaddle']);
Route::post('/webhooks/paddle', [\App\Http\Controllers\WebhookController::class, 'handlePaddle']);

// Public, time-limited SIGNED media stream. Exists so platforms that ingest media
// by URL (e.g. TikTok photo carousels, which only support PULL_FROM_URL) fetch it
// from our own verifiable domain instead of R2's unverifiable S3 endpoint. The
// signature enforces expiry + integrity, so no session auth is required.
Route::get('/media/{media}/raw', [\App\Http\Controllers\Api\MediaProxyController::class, 'raw'])
    ->middleware('throttle:120,1')
    ->name('media.raw');

// Waitlist Routes
Route::prefix('waitlist')->group(function () {
    Route::post('/', [\App\Http\Controllers\Api\WaitlistController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/confirm/{token}', [\App\Http\Controllers\Api\WaitlistController::class, 'confirm']);
    Route::get('/status/{status_token}', [\App\Http\Controllers\Api\WaitlistController::class, 'status'])->middleware('throttle:60,1');
    Route::post('/resend-status', [\App\Http\Controllers\Api\WaitlistController::class, 'resendStatus'])->middleware('throttle:5,1');
});
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('register/send-code', [AuthController::class, 'sendRegisterCode']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // Password Reset Flow
    Route::post('forgot-password', [\App\Http\Controllers\Api\Auth\PasswordResetController::class, 'forgotPassword']);
    Route::post('validate-reset-token', [\App\Http\Controllers\Api\Auth\PasswordResetController::class, 'validateResetToken']);
    Route::post('reset-password', [\App\Http\Controllers\Api\Auth\PasswordResetController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    // Sub-namespace for Social OAuth
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('{platform}/redirect', [\App\Http\Controllers\Api\Auth\SocialAuthController::class, 'redirect']);
    });

    // Callback must be public as TikTok redirects directly to this URL
    Route::get('{platform}/callback', [\App\Http\Controllers\Api\Auth\SocialAuthController::class, 'callback']);
});

Route::middleware(['auth:sanctum', 'throttle:100,1'])->group(function () {

    // Plan Context
    Route::get('/user/plan-context', [\App\Http\Controllers\Api\UserController::class, 'planContext']);

    // Platform capability registry (media types, carousel support, limits)
    Route::get('/platforms', [\App\Http\Controllers\Api\PlatformController::class, 'index']);

    // Posts & Scheduling
    Route::apiResource('posts', \App\Http\Controllers\Api\PostController::class)
        ->middleware(['plan:videos', 'plan:custom_caption']);

    // Video Management
    Route::apiResource('videos', \App\Http\Controllers\VideoController::class)
        ->middleware(['plan:videos']);

    // Subscriptions webhook

    // Subscriptions
    Route::get('/subscription/config', [\App\Http\Controllers\SubscriptionController::class, 'config']);
    Route::get('/subscription/billing', [\App\Http\Controllers\SubscriptionController::class, 'billingInfo']);
    Route::post('/subscription/cancel', [\App\Http\Controllers\SubscriptionController::class, 'cancel']);
    Route::post('/subscription/reactivate', [\App\Http\Controllers\SubscriptionController::class, 'reactivate']);
    Route::get('/subscription/status', [\App\Http\Controllers\SubscriptionController::class, 'status']);
    Route::get('/subscription/portal-url', [\App\Http\Controllers\SubscriptionController::class, 'portalUrl']);
    Route::post('/subscription/sync', [\App\Http\Controllers\SubscriptionController::class, 'syncPaddle']);
    Route::prefix('dashboard')->group(function () {
        Route::get('stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
        Route::get('calendar', [\App\Http\Controllers\DashboardController::class, 'calendar']);
        Route::get('storage', [\App\Http\Controllers\DashboardController::class, 'storage']);
    });

    // Social Accounts
    Route::get('/social-accounts', [\App\Http\Controllers\Api\SocialAccountController::class, 'index']);
    Route::delete('/social-accounts/{id}', [\App\Http\Controllers\Api\SocialAccountController::class, 'destroy']);
    Route::get('/tiktok/creator-info', [\App\Http\Controllers\Api\TikTokController::class, 'getCreatorInfo']);

    // Analytics
    Route::get('/analytics/{any}', [\App\Http\Controllers\Api\AnalyticsController::class, 'show'])
        ->middleware(['plan:analytics'])
        ->where('any', '.*');

    // Presigned URL Upload (60/minute rate limit)
    // Accepts both video and carousel-image uploads. /media/* are the canonical
    // routes; /videos/* remain as backward-compatible aliases.
    Route::post('/videos/presigned-url', [\App\Http\Controllers\VideoUploadController::class, 'generatePresignedUrl'])
        ->middleware(['throttle:60,1', 'plan:videos']);
    Route::post('/videos/confirm-upload', [\App\Http\Controllers\VideoUploadController::class, 'confirmUpload'])
        ->middleware(['throttle:60,1', 'plan:videos']);
    Route::post('/media/presigned-url', [\App\Http\Controllers\VideoUploadController::class, 'generatePresignedUrl'])
        ->middleware(['throttle:60,1', 'plan:videos']);
    Route::post('/media/confirm-upload', [\App\Http\Controllers\VideoUploadController::class, 'confirmUpload'])
        ->middleware(['throttle:60,1', 'plan:videos']);

    // Carousel slide management
    Route::post('/posts/{post}/media/reorder', [\App\Http\Controllers\Api\PostMediaController::class, 'reorder'])
        ->middleware(['plan:videos']);
    Route::delete('/posts/{post}/media/{media}', [\App\Http\Controllers\Api\PostMediaController::class, 'destroy'])
        ->middleware(['plan:videos']);

    // Support & Feedback
    Route::post('/support/contact', [\App\Http\Controllers\Api\SupportController::class, 'contact']);
    Route::post('/support/feedback', [\App\Http\Controllers\Api\SupportController::class, 'feedback']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'throttle:100,1']);

Route::middleware(['auth:sanctum', 'throttle:100,1'])->prefix('user')->group(function () {
    Route::get('settings', [\App\Http\Controllers\Api\UserSettingsController::class, 'getSettings']);
    Route::put('profile', [\App\Http\Controllers\Api\UserSettingsController::class, 'updateProfile']);
    Route::post('email/send-code', [\App\Http\Controllers\Api\UserSettingsController::class, 'sendEmailChangeCode']);
    Route::put('email', [\App\Http\Controllers\Api\UserSettingsController::class, 'updateEmail']);
    Route::post('password/send-code', [\App\Http\Controllers\Api\UserSettingsController::class, 'sendPasswordVerificationCode']);
    Route::put('password', [\App\Http\Controllers\Api\UserSettingsController::class, 'updatePassword']);
    Route::put('settings', [\App\Http\Controllers\Api\UserSettingsController::class, 'updatePreferences']);
    Route::post('delete/send-code', [\App\Http\Controllers\Api\UserSettingsController::class, 'sendDeleteAccountCode']);
    Route::delete('/', [\App\Http\Controllers\Api\UserSettingsController::class, 'destroy']);
});
