<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Company\CompanyController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', fn () => view('dashboard.index', ['companies' => auth()->user()->companies()->count()]))->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::resource('companies', CompanyController::class)->only(['index', 'create', 'store', 'show']);
    foreach (['accounting', 'sales', 'purchases', 'banking', 'tax', 'reports', 'settings'] as $module) {
        Route::view("/{$module}", 'coming-soon.index', ['module' => ucfirst($module)])->name($module);
    }
});
