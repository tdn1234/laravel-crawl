<?php

namespace App\Providers;

use App\Services\LinkedinCrawlerService;
use Illuminate\Support\ServiceProvider;

class LinkedinCrawlerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(LinkedinCrawlerService::class, function ($app) {
            return new LinkedinCrawlerService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}