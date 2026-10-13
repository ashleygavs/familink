<?php

use App\Enums\RoleName;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyRelativesController;
use App\Http\Controllers\FamilySuggestionController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\RelativeSuggestionController;
use App\Http\Controllers\ResidentController;
use App\Models\Household;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:'.RoleName::User->value)->prefix('encoder')->name('encoder.')->group(function () {
        Route::get('register', [ResidentController::class, 'create'])->name('register');
        Route::post('register', [ResidentController::class, 'store'])->name('register.store');
        Route::get('residents/search', [ResidentController::class, 'search'])->name('residents.search');
        Route::get('households/search', [HouseholdController::class, 'search'])->name('households.search');
        Route::get('family-suggestions', FamilySuggestionController::class)->name('family-suggestions');
        Route::get('family-relatives', FamilyRelativesController::class)->name('family-relatives');
        Route::get('relative-suggestions', RelativeSuggestionController::class)->name('relative-suggestions');
        Route::inertia('scanner', 'encoder/scanner')->name('scanner');
    });

    Route::middleware('role:'.RoleName::Admin->value)->prefix('admin')->name('admin.')->group(function () {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('approvals/{resident}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{resident}/archive', [ApprovalController::class, 'archive'])->name('approvals.archive');
        Route::post('approvals/{resident}/links', [ApprovalController::class, 'links'])->name('approvals.links');
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
