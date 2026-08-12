<?php

use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Sales\ReceivablesController;
use App\Http\Controllers\Sales\SalesController;
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
    Route::resource('companies', CompanyController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
    Route::patch('/companies/{company}/status', [CompanyController::class, 'status'])->name('companies.status');
    Route::get('/companies/{company}/delete', [CompanyController::class, 'confirmDelete'])->name('companies.delete');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
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
    Route::get('/sales', [SalesController::class, 'index'])->name('sales');
    Route::get('/companies/{company}/customers', [SalesController::class, 'customers'])->name('sales.customers');
    Route::post('/companies/{company}/customers', [SalesController::class, 'storeCustomer'])->name('sales.customers.store');
    Route::get('/companies/{company}/customers/{customer}/edit', [SalesController::class, 'editCustomer'])->name('sales.customers.edit');
    Route::put('/companies/{company}/customers/{customer}', [SalesController::class, 'updateCustomer'])->name('sales.customers.update');
    Route::patch('/companies/{company}/customers/{customer}/status', [SalesController::class, 'customerStatus'])->name('sales.customers.status');
    Route::get('/companies/{company}/customers/{customer}/delete', [SalesController::class, 'confirmCustomerDelete'])->name('sales.customers.delete');
    Route::delete('/companies/{company}/customers/{customer}', [SalesController::class, 'destroyCustomer'])->name('sales.customers.destroy');
    Route::get('/companies/{company}/items', [SalesController::class, 'items'])->name('sales.items');
    Route::post('/companies/{company}/items', [SalesController::class, 'storeItem'])->name('sales.items.store');
    Route::get('/companies/{company}/items/{item}/edit', [SalesController::class, 'editItem'])->name('sales.items.edit');
    Route::put('/companies/{company}/items/{item}', [SalesController::class, 'updateItem'])->name('sales.items.update');
    Route::patch('/companies/{company}/items/{item}/status', [SalesController::class, 'itemStatus'])->name('sales.items.status');
    Route::get('/companies/{company}/items/{item}/delete', [SalesController::class, 'confirmItemDelete'])->name('sales.items.delete');
    Route::delete('/companies/{company}/items/{item}', [SalesController::class, 'destroyItem'])->name('sales.items.destroy');
    Route::get('/companies/{company}/sales-invoices', [SalesController::class, 'invoices'])->name('sales.invoices');
    Route::get('/companies/{company}/sales/{type}', [SalesController::class, 'documents'])->name('sales.documents');
    Route::post('/companies/{company}/sales-invoices/{invoice}/post', [SalesController::class, 'postInvoice'])->name('sales.invoices.post');
    Route::get('/reports/accounts-receivable', [ReceivablesController::class, 'ar'])->name('reports.ar');
    Route::get('/reports/ar-aging', [ReceivablesController::class, 'aging'])->name('reports.ar-aging');
    Route::get('/reports/customer-statement', [ReceivablesController::class, 'statement'])->name('reports.customer-statement');
    foreach (['purchases', 'banking', 'tax', 'settings'] as $module) {
        Route::view("/{$module}", 'coming-soon.index', ['module' => ucfirst($module)])->name($module);
    }
});
