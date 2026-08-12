<?php

use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\ReportController;
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
    Route::get('/accounting', [JournalController::class, 'index'])->name('accounting');
    Route::get('/companies/{company}/journals/create', [JournalController::class, 'create'])->name('journals.create');
    Route::post('/companies/{company}/journals', [JournalController::class, 'store'])->name('journals.store');
    Route::get('/companies/{company}/journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::post('/companies/{company}/journals/{journal}/post', [JournalController::class, 'post'])->name('journals.post');
    Route::post('/companies/{company}/journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::get('/reports/general-ledger', [ReportController::class, 'ledger'])->name('reports.ledger');
    Route::get('/reports/trial-balance', [ReportController::class, 'trial'])->name('reports.trial');
    Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.profit-loss');
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    foreach (['sales', 'purchases', 'banking', 'tax', 'settings'] as $module) {
        Route::view("/{$module}", 'coming-soon.index', ['module' => ucfirst($module)])->name($module);
    }
});
