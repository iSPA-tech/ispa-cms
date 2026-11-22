<?php

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Controllers\Web\EmailVerificationController;
use Cms\Core\Http\Controllers\Web\HubWellKnownController;
use Cms\Core\Http\Controllers\Web\PasskeyLoginController;
use Cms\Core\Http\Controllers\Web\WebMfaChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('qr', [AdminController::class, 'QrGenerate'])
    ->middleware('throttle:qr-public')
    ->name('qr.generate');

// One budget for both passkey steps, under its own key prefix: an unnamed
// throttle:N,M shares a single per-user/IP counter with every other one.
// Single-route limits use throttle.route (Cms\Core\Http\Middleware\ThrottlePerRoute).
Route::prefix('auth/passkey')
    ->as('core.passkey.')
    ->middleware('throttle:10,1,core-web-passkey:')
    ->group(static function (): void {
        Route::post('options', [PasskeyLoginController::class, 'options'])->name('options');
        Route::post('login', [PasskeyLoginController::class, 'login'])->name('login');
    });

Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle.route:6,1'])
    ->name('verification.verify');

Route::prefix('auth/mfa')
    ->as('core.web-mfa.')
    ->group(static function (): void {
        Route::get('/', [WebMfaChallengeController::class, 'show'])
            ->middleware('throttle.route:60,1')
            ->name('show');
        Route::post('/', [WebMfaChallengeController::class, 'verify'])
            ->middleware('throttle.route:10,1')
            ->name('verify');
        Route::post('cancel', [WebMfaChallengeController::class, 'cancel'])
            ->middleware('throttle.route:10,1')
            ->name('cancel');
        Route::post('resend', [WebMfaChallengeController::class, 'resend'])
            ->middleware('throttle.route:5,1')
            ->name('resend');
    });

// The hub's domain check of a connecting site: answers only while a connect call is outstanding.
Route::get('.well-known/ispa-hub/{nonce}', [HubWellKnownController::class, 'show'])
    ->where('nonce', '[^/]{1,200}')
    ->middleware('throttle.route:60,1')
    ->name('core.hub-well-known');
