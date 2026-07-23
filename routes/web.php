<?php

use App\Http\Controllers\Auth\GoogleSocialAuthenticationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Library\AccessController;
use App\Http\Controllers\Library\AssistantController;
use App\Http\Controllers\Library\BookController;
use App\Http\Controllers\Library\LoanController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/google', [GoogleSocialAuthenticationController::class, 'redirect'])
        ->middleware('throttle:google-social-auth')
        ->name('auth.google.redirect');
});

Route::get('auth/google/callback', [GoogleSocialAuthenticationController::class, 'callback'])
    ->middleware('throttle:google-social-auth')
    ->name('auth.google.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('settings/security/google', [GoogleSocialAuthenticationController::class, 'redirect'])
        ->middleware([RequirePassword::class, 'throttle:google-social-auth'])
        ->name('auth.google.link');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('assistant/messages', [AssistantController::class, 'store'])
        ->middleware('throttle:library-assistant')
        ->name('assistant.store');
    Route::post('assistant/checkouts', [AssistantController::class, 'confirmCheckout'])
        ->middleware(['permission:loans.manage', 'throttle:library-assistant-confirmation'])
        ->name('assistant.checkouts.store');

    Route::get('books', [BookController::class, 'index'])
        ->middleware('permission:books.view')
        ->name('books.index');
    Route::post('books', [BookController::class, 'store'])
        ->middleware('permission:books.create')
        ->name('books.store');
    Route::patch('books/{book}', [BookController::class, 'update'])
        ->middleware('permission:books.update')
        ->name('books.update');
    Route::delete('books/{book}', [BookController::class, 'destroy'])
        ->middleware('permission:books.delete')
        ->name('books.destroy');

    Route::get('loans', [LoanController::class, 'index'])
        ->middleware('permission:loans.view|loans.view-own')
        ->name('loans.index');
    Route::post('loans', [LoanController::class, 'store'])
        ->middleware('permission:loans.manage')
        ->name('loans.store');
    Route::patch('loans/{loan}/check-in', [LoanController::class, 'checkIn'])
        ->middleware('permission:loans.manage')
        ->name('loans.check-in');

    Route::get('access', [AccessController::class, 'index'])
        ->middleware('permission:access.manage')
        ->name('access.index');
    Route::patch('access/{user}/role', [AccessController::class, 'update'])
        ->middleware('permission:access.manage')
        ->name('access.update');
});

require __DIR__.'/settings.php';
