<?php
namespace App\Core\Export;
use Illuminate\Support\ServiceProvider;
class ExportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->app->booted(
            fn() => app(\Illuminate\Console\Scheduling\Schedule::class)
                ->call(fn() => app(ExportJobs::class)->cleanup())
                ->hourly()
                ->name('export-cleanup')
                ->withoutOverlapping(),
        );
    }
}
