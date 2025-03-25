<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class LinkedInServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Register all LinkedIn service classes
        $this->app->singleton(
            \App\Services\LinkedIn\Drivers\WebDriverFactory::class,
            function ($app) {
                return new \App\Services\LinkedIn\Drivers\WebDriverFactory(
                    config('services.linkedin.chrome_profile_path', '/home/tdn/.config/google-chrome/Default'),
                    config('services.linkedin.selenium_hub', 'http://host.docker.internal:4444/wd/hub')
                );
            }
        );
        
        // Register all other services
        $this->app->singleton(\App\Services\LinkedIn\Extractors\CompanyExtractor::class);
        $this->app->singleton(\App\Services\LinkedIn\Extractors\JobExtractor::class);
        $this->app->singleton(\App\Services\LinkedIn\Extractors\StaffExtractor::class);
        
        $this->app->singleton(\App\Services\LinkedIn\Repositories\CompanyRepository::class);
        $this->app->singleton(\App\Services\LinkedIn\Repositories\JobRepository::class);
        $this->app->singleton(\App\Services\LinkedIn\Repositories\StaffRepository::class);
        
        $this->app->singleton(\App\Services\LinkedIn\Processors\JobProcessor::class);
        $this->app->singleton(\App\Services\LinkedIn\Processors\StaffProcessor::class);
        $this->app->singleton(\App\Services\LinkedIn\Processors\CompanyProcessor::class);
        
        $this->app->singleton(\App\Services\LinkedIn\LinkedinSeleniumService::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
