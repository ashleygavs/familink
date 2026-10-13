<?php

use App\Enums\RoleName;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyRelativesController;
use App\Http\Controllers\FamilySuggestionController;
use App\Http\Controllers\FamilyTreeController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\MunicipalityController;
use App\Http\Controllers\RelativeSuggestionController;
use App\Http\Controllers\ReliefController;
use App\Http\Controllers\ReliefEventController;
use App\Http\Controllers\ResidentCardController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ResidentListController;
use App\Http\Controllers\SuggestionReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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
        Route::get('scanner', [ReliefController::class, 'scanner'])->name('scanner');
        Route::post('scan', [ReliefController::class, 'scan'])->name('scan');
    });

    Route::middleware('role:'.RoleName::Admin->value)->prefix('admin')->name('admin.')->group(function () {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('approvals/{resident}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{resident}/archive', [ApprovalController::class, 'archive'])->name('approvals.archive');
        Route::post('approvals/{resident}/links', [ApprovalController::class, 'links'])->name('approvals.links');
        Route::post('approvals/{resident}/unlink', [ApprovalController::class, 'unlink'])->name('approvals.unlink');
        Route::post('approvals/{resident}/feedback', [ApprovalController::class, 'feedback'])->name('approvals.feedback');
        Route::get('suggestion-report', SuggestionReportController::class)->name('suggestion-report');
        Route::get('events', [ReliefEventController::class, 'index'])->name('events');
        Route::post('events', [ReliefEventController::class, 'store'])->name('events.store');
        Route::put('events/{event}', [ReliefEventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [ReliefEventController::class, 'destroy'])->name('events.destroy');
        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
    });

    Route::middleware('role:'.RoleName::User->value.','.RoleName::Admin->value)->group(function () {
        Route::get('residents', ResidentListController::class)->name('residents.index');
        Route::get('residents/{resident}/card', [ResidentCardController::class, 'resident'])->name('residents.card');
        Route::get('households/{household}/cards', [ResidentCardController::class, 'household'])->name('households.cards');
    });

    Route::middleware('role:'.RoleName::Stakeholder->value.','.RoleName::Admin->value)->group(function () {
        Route::get('reports/analytics', AnalyticsController::class)->name('reports.analytics');
        Route::get('family-tree/{household}', FamilyTreeController::class)->name('family-tree');
    });

    Route::middleware('role:'.RoleName::SuperAdmin->value)->group(function () {
        Route::delete('admin/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('admin/municipalities', [MunicipalityController::class, 'index'])->name('admin.municipalities');
        Route::post('admin/municipalities', [MunicipalityController::class, 'store'])->name('admin.municipalities.store');
        Route::put('admin/municipalities/{municipality}', [MunicipalityController::class, 'update'])->name('admin.municipalities.update');
        Route::delete('admin/municipalities/{municipality}', [MunicipalityController::class, 'destroy'])->name('admin.municipalities.destroy');
        Route::post('admin/barangays', [BarangayController::class, 'store'])->name('admin.barangays.store');
        Route::put('admin/barangays/{barangay}', [BarangayController::class, 'update'])->name('admin.barangays.update');
        Route::delete('admin/barangays/{barangay}', [BarangayController::class, 'destroy'])->name('admin.barangays.destroy');
    });
});

require __DIR__.'/settings.php';
