<?php

namespace App\Providers;

use App\Integration\Services\IntegrationManager;
use App\Integration\Services\RecordReader;
use App\Menu\Services\MenuBuilder;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolved once per request: the manager is stateless, the reader
        // memoizes rows so many widgets over one dataset hit the DB once.
        $this->app->singleton(IntegrationManager::class);
        $this->app->scoped(RecordReader::class);
    }

    public function boot(): void
    {
        // Make the DB-backed menu available to the layout (the sidebar renders it).
        View::composer('layouts.app', function ($view) {
            $view->with('menuTree', app(MenuBuilder::class)->tree());
        });
    }
}
