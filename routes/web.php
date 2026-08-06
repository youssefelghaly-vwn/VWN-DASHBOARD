<?php

use App\Dashboard\Controllers\ChartController;
use App\Dashboard\Controllers\DashboardController;
use App\Dashboard\Controllers\LoopController;
use App\Dashboard\Controllers\MetricController;
use App\Dashboard\Controllers\SectionController;
use App\DataHealth\Controllers\DataHealthController;
use App\Http\Controllers\ProfileController;
use App\Integration\Controllers\IntegrationController;
use App\Menu\Controllers\MenuController;
use App\Sheet\Controllers\SheetController;
use App\Team\Controllers\InvitationController;
use App\Team\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->name('admin.')->group(function () {
    // Dashboards — the default index redirects to the default dashboard.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
    Route::get('/dashboards/{dashboard:slug}', [DashboardController::class, 'show'])->name('dashboards.show');
    Route::get('/dashboards/{dashboard:slug}/charts/data', [DashboardController::class, 'chartData'])->name('dashboards.charts.data');
    Route::get('/dashboards/{dashboard:slug}/metrics', [MetricController::class, 'index'])->name('dashboards.metrics.index');
    Route::delete('/dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
    Route::get('/table/data', [DashboardController::class, 'tableData'])->name('table.data');
    Route::get('/table/distinct', [DashboardController::class, 'distinct'])->name('table.distinct');
    Route::post('/dashboards/{dashboard}/layout', [DashboardController::class, 'reorderLayout'])->name('dashboards.layout');

    // Freshness — last sync across integrations (polled by the dashboard header) + sync-all.
    Route::get('/sync-status', [DashboardController::class, 'syncStatus'])->name('sync.status');
    Route::post('/sync-all', [DashboardController::class, 'syncAll'])->name('sync.all');

    // Dashboard sections — titled dividers that group + nest widgets.
    Route::get('/dashboards/{dashboard:slug}/sections', [SectionController::class, 'index'])->name('dashboards.sections.index');
    Route::post('/dashboards/{dashboard}/sections', [SectionController::class, 'store'])->name('sections.store');
    Route::put('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::delete('/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

    // Loop statistics — fan template widgets out across a column's distinct values.
    Route::get('/dashboards/{dashboard:slug}/loops', [LoopController::class, 'index'])->name('dashboards.loops.index');
    Route::post('/dashboards/{dashboard}/loops', [LoopController::class, 'store'])->name('loops.store');
    Route::put('/loops/{loop}', [LoopController::class, 'update'])->name('loops.update');
    Route::post('/loops/{loop}/refresh', [LoopController::class, 'refresh'])->name('loops.refresh');
    Route::delete('/loops/{loop}', [LoopController::class, 'destroy'])->name('loops.destroy');

    // Chart widgets.
    Route::post('/dashboards/{dashboard}/charts', [ChartController::class, 'store'])->name('charts.store');
    Route::put('/charts/{chart}', [ChartController::class, 'update'])->name('charts.update');
    Route::delete('/charts/{chart}', [ChartController::class, 'destroy'])->name('charts.destroy');

    // Metric widgets.
    Route::post('/dashboards/{dashboard}/metrics', [MetricController::class, 'store'])->name('metrics.store');
    Route::post('/metrics/preview', [MetricController::class, 'preview'])->name('metrics.preview');
    Route::put('/metrics/{metric}', [MetricController::class, 'update'])->name('metrics.update');
    Route::delete('/metrics/{metric}', [MetricController::class, 'destroy'])->name('metrics.destroy');

    // Integrations — generic connect / sync / disconnect for every provider.
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations', [IntegrationController::class, 'store'])->name('integrations.store');
    Route::put('/integrations/{integration}', [IntegrationController::class, 'update'])->name('integrations.update');
    Route::post('/integrations/{integration}/sync', [IntegrationController::class, 'sync'])->name('integrations.sync');
    Route::delete('/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');

    // Sheets — read-only, Excel-style workspace over synced datasets.
    Route::get('/sheets', [SheetController::class, 'index'])->name('sheets.index');
    Route::post('/sheets', [SheetController::class, 'store'])->name('sheets.store');
    Route::get('/sheets/{sheet}/data', [SheetController::class, 'data'])->name('sheets.data');
    Route::put('/sheets/{sheet}', [SheetController::class, 'update'])->name('sheets.update');
    Route::delete('/sheets/{sheet}', [SheetController::class, 'destroy'])->name('sheets.destroy');

    // Generic Data Health.
    Route::get('/data-health', [DataHealthController::class, 'index'])->name('data-health');
    Route::post('/data-health/{integration}/sync', [DataHealthController::class, 'sync'])->name('data-health.sync');

    // Team — invite members by email; they set their own password on accept.
    Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    Route::post('/team/invite', [TeamController::class, 'invite'])->name('team.invite');
    Route::post('/team/{user}/resend', [TeamController::class, 'resend'])->name('team.resend');
    Route::delete('/team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

    // Menu management.
    Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');
    Route::post('/menu', [MenuController::class, 'store'])->name('menu.store');
    Route::delete('/menu/{menuItem}', [MenuController::class, 'destroy'])->name('menu.destroy');
});

// Keeps Breeze's auth redirects (route('dashboard')) working.
Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
    ->middleware('auth')
    ->name('dashboard');

// Accepting an invitation — public: the emailed token is the credential.
Route::middleware('guest')->group(function () {
    Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invite/{token}', [InvitationController::class, 'store'])->name('invitations.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
