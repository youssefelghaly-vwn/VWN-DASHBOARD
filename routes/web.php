<?php

use App\Http\Controllers\Admin\ChartController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GhlConnectionController;
use App\Http\Controllers\Admin\GhlDiagnosticController;
use App\Http\Controllers\Admin\GhlHealthController;
use App\Http\Controllers\Admin\MetricController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;



Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/charts/data', [DashboardController::class, 'chartData'])->name('charts.data');
    Route::get('/table/data', [DashboardController::class, 'tableData'])->name('table.data');
    Route::post('/sync', [DashboardController::class, 'sync'])->name('sync');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::post('/charts', [ChartController::class, 'store'])->name('charts.store');
    Route::put('/charts/{chart}', [ChartController::class, 'update'])->name('charts.update');
    Route::delete('/charts/{chart}', [ChartController::class, 'destroy'])->name('charts.destroy');

    Route::get('/metrics',            [MetricController::class, 'index'])->name('metrics.index');
    Route::post('/metrics',           [MetricController::class, 'store'])->name('metrics.store');
    Route::post('/metrics/preview',   [MetricController::class, 'preview'])->name('metrics.preview');
    Route::put('/metrics/{metric}',   [MetricController::class, 'update'])->name('metrics.update');
    Route::delete('/metrics/{metric}', [MetricController::class, 'destroy'])->name('metrics.destroy');

    Route::post('/ghl',   [GhlConnectionController::class, 'store'])->name('ghl.store');
    Route::delete('/ghl', [GhlConnectionController::class, 'destroy'])->name('ghl.disconnect');
    Route::get('/ghl/health', GhlHealthController::class)->name('ghl.health');
    Route::get('/ghl/diagnose', GhlDiagnosticController::class)->name('ghl.diagnose');

});


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
