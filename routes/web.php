<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Library\AccessController;
use App\Http\Controllers\Library\BookController;
use App\Http\Controllers\Library\LoanController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

require __DIR__ . '/settings.php';
