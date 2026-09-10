<?php
namespace App\Contracts\Module;
interface WeatherForecast
{
    /** Sanitized seven-day forecast for the currently connected tenant. */
    public function forecast(int $tenantId, string $timezone): array;
}
