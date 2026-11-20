<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ValidationController;
use App\Http\Middleware\EnsureRegistrationEnabled;
use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------ accounts (guests)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // per (username + IP) attempt limit lives in LoginRequest; this caps raw request volume per IP
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:30,1')->name('login.store');

    Route::middleware(EnsureRegistrationEnabled::class)->group(function () {
        Route::get('/register', [RegisterController::class, 'create'])->name('register');
        Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register.store');
    });
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// ------------------------------------------------------------ application
// Everything below requires a signed-in, active account (fail-closed). Login
// only controls access to the app; the Scope Enforcer still checks every
// command. Guarded by tests/Feature/Auth/RouteProtectionTest.php.
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/recon', [ChatController::class, 'index'])->name('recon.index');
    Route::post('/recon', [ChatController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('recon.store');
    Route::get('/recon/{log}', [ChatController::class, 'show'])->whereNumber('log')->name('recon.show');

    Route::get('/validations', [ValidationController::class, 'index'])->name('validations.index');
    Route::get('/validations/{log}', [ValidationController::class, 'show'])->whereNumber('log')->name('validations.show');

    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/history/{log}/archive', [HistoryController::class, 'archive'])->whereNumber('log')->name('history.archive');
        Route::post('/history/{log}/restore', [HistoryController::class, 'restore'])->whereNumber('log')->name('history.restore');
        Route::delete('/history/{log}', [HistoryController::class, 'destroy'])->whereNumber('log')->name('history.destroy');
    });
    Route::get('/status', StatusController::class)->name('status');
    Route::get('/settings', SettingsController::class)->name('settings');
});
