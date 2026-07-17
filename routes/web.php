<?php

use App\Dashboard\Controllers\ChartController;
use App\Dashboard\Controllers\DashboardController;
use App\Dashboard\Controllers\MetricController;
use App\DataHealth\Controllers\DataHealthController;
use App\Http\Controllers\ProfileController;
use App\Integration\Controllers\IntegrationController;
use App\Menu\Controllers\MenuController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboards — the default index redirects to the default dashboard.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
    Route::get('/dashboards/{dashboard:slug}', [DashboardController::class, 'show'])->name('dashboards.show');
    Route::get('/dashboards/{dashboard:slug}/charts/data', [DashboardController::class, 'chartData'])->name('dashboards.charts.data');
    Route::get('/dashboards/{dashboard:slug}/metrics', [MetricController::class, 'index'])->name('dashboards.metrics.index');
    Route::delete('/dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
    Route::get('/table/data', [DashboardController::class, 'tableData'])->name('table.data');

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
    Route::post('/integrations/{integration}/sync', [IntegrationController::class, 'sync'])->name('integrations.sync');
    Route::delete('/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');

    // Generic Data Health.
    Route::get('/data-health', [DataHealthController::class, 'index'])->name('data-health');
    Route::post('/data-health/{integration}/sync', [DataHealthController::class, 'sync'])->name('data-health.sync');

    // Menu management.
    Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');
    Route::post('/menu', [MenuController::class, 'store'])->name('menu.store');
    Route::delete('/menu/{menuItem}', [MenuController::class, 'destroy'])->name('menu.destroy');
});

// Keeps Breeze's auth redirects (route('dashboard')) working.
Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
