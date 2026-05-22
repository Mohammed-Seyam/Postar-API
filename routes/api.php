<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;






Route::post('/webhooks/paddle', [\App\Http\Controllers\WebhookController::class, 'handlePaddle']);





Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

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

    // Posts & Scheduling
    Route::apiResource('posts', \App\Http\Controllers\Api\PostController::class)
        ->middleware(['plan:posts', 'plan:uploads', 'plan:custom_caption']);

    // Video Management
    Route::apiResource('videos', \App\Http\Controllers\VideoController::class)
        ->middleware(['plan:uploads', 'throttle:10,1']);

    // Subscriptions webhook

    // Subscriptions
    Route::get('/subscription/config', [\App\Http\Controllers\SubscriptionController::class, 'config']);
    Route::post('/subscription/cancel', [\App\Http\Controllers\SubscriptionController::class, 'cancel']);
    Route::get('/subscription/portal-url', [\App\Http\Controllers\SubscriptionController::class, 'portalUrl']);
    Route::prefix('dashboard')->group(function () {
        Route::get('stats', [\App\Http\Controllers\DashboardController::class, 'stats']);
        Route::get('calendar', [\App\Http\Controllers\DashboardController::class, 'calendar']);
        Route::get('storage', [\App\Http\Controllers\DashboardController::class, 'storage']);
    });

    // Social Accounts
    Route::get('/social-accounts', [\App\Http\Controllers\Api\SocialAccountController::class, 'index']);
    Route::delete('/social-accounts/{id}', [\App\Http\Controllers\Api\SocialAccountController::class, 'destroy']);

    // Analytics
    Route::get('/analytics/{any}', [\App\Http\Controllers\Api\AnalyticsController::class, 'show'])
        ->middleware(['plan:analytics'])
        ->where('any', '.*');

    // Presigned URL Upload (10/minute rate limit)
    Route::post('/videos/presigned-url', [\App\Http\Controllers\VideoUploadController::class, 'generatePresignedUrl'])
        ->middleware('throttle:10,1');
    Route::post('/videos/confirm-upload', [\App\Http\Controllers\VideoUploadController::class, 'confirmUpload'])
        ->middleware('throttle:10,1');
});



Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'throttle:100,1']);

Route::middleware(['auth:sanctum', 'throttle:100,1'])->prefix('user')->group(function () {
    Route::get('settings', [\App\Http\Controllers\Api\UserSettingsController::class, 'getSettings']);
    Route::put('profile', [\App\Http\Controllers\Api\UserSettingsController::class, 'updateProfile']);
    Route::put('email', [\App\Http\Controllers\Api\UserSettingsController::class, 'updateEmail']);
    Route::put('password', [\App\Http\Controllers\Api\UserSettingsController::class, 'updatePassword']);
    Route::put('settings', [\App\Http\Controllers\Api\UserSettingsController::class, 'updatePreferences']);
    Route::delete('/', [\App\Http\Controllers\Api\UserSettingsController::class, 'destroy']);
});
