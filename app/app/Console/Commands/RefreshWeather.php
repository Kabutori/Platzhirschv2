<?php
namespace App\Console\Commands;

use App\Contracts\Module\{ModuleAccess, TenantRuntime, WeatherForecast};
use App\Models\Tenant;
use Illuminate\Console\Command;

class RefreshWeather extends Command
{
    protected $signature = 'weather:refresh';
    protected $description = 'Wettervorhersagen aktiver Restaurants im Hintergrund aktualisieren';

    public function handle(ModuleAccess $access, TenantRuntime $runtime, WeatherForecast $weather): int
    {
        $failed = 0;
        $checked = 0;
        Tenant::where('status', 'active')->select('id')->chunkById(100, function ($tenants) use (
            $access, $runtime, $weather, &$failed, &$checked,
        ) {
            foreach ($tenants as $tenant) {
                try {
                    // Do not open databases of restaurants without the optional module.
                    if (!in_array('weather', $access->enabled($tenant->id), true)) {
                        continue;
                    }
                    $result = $runtime->withTenant($tenant->id, fn($context) =>
                        $weather->forecast($context->id, $context->timezone),
                    );
                    $checked++;
                    if (in_array($result['status'], ['stale', 'unavailable', 'provider_missing'], true)) {
                        $failed++;
                    }
                } catch (\Throwable) {
                    // Continue with other tenants; exception messages can contain credentials.
                    $failed++;
                }
            }
        });
        $this->info("Wetterprüfung: {$checked} Restaurants geprüft; {$failed} ohne aktuelle Vorhersage.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
