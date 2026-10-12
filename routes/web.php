<?php

use App\Enums\RoleName;
use App\Http\Controllers\DashboardController;
use App\Models\Household;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:'.RoleName::User->value)->prefix('encoder')->name('encoder.')->group(function () {
        Route::inertia('register', 'encoder/register-resident')->name('register');
        Route::inertia('scanner', 'encoder/scanner')->name('scanner');
    });

    Route::middleware('role:'.RoleName::Admin->value)->prefix('admin')->name('admin.')->group(function () {
        Route::inertia('approvals', 'admin/approvals')->name('approvals');
        Route::inertia('events', 'admin/events')->name('events');
    });

    Route::middleware('role:'.RoleName::Stakeholder->value)->group(function () {
        Route::inertia('reports/analytics', 'reports/analytics')->name('reports.analytics');
        Route::get('family-tree/{household}', fn (Household $household) => Inertia::render('reports/family-tree', [
            'household' => $household->only(['id', 'household_code']),
        ]))->name('family-tree');
    });

    Route::middleware('role:'.RoleName::SuperAdmin->value)->group(function () {
        Route::inertia('admin/users', 'admin/users')->name('admin.users');
    });
});

require __DIR__.'/settings.php';
