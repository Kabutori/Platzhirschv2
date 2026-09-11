<?php
namespace App\Modules\Weather;
use App\Contracts\Module\{ModuleAccess, WeatherForecast};
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Contracts\Cache\Repository;
use Carbon\CarbonImmutable;
class Forecast implements WeatherForecast
{
    public function __construct(
        private DatabaseManager $db,
        private Factory $http,
        private Repository $cache,
        private ModuleAccess $access,
    ) {}
    public function forecast(int $tenantId, string $timezone): array
    {
        if (!in_array('weather', $this->access->enabled($tenantId), true)) {
            return ['status' => 'inactive', 'days' => []];
        }
        $s = $this->db->connection('tenant')->table('weather_settings')->find(1);
        if (!$s || !$s->enabled) {
            return ['status' => 'unconfigured', 'days' => []];
        }
        $key = (string) config('weather.api_key');
        if ($s->mode === 'commercial' && $key === '') {
            return ['status' => 'provider_missing', 'days' => []];
        }
        // Tenant, settings revision, date and timezone isolate both cache and refresh limits.
        $cacheKey =
            'weather:' .
            hash(
                'sha256',
                json_encode([
                    $tenantId,
                    $s->version,
                    $s->latitude,
                    $s->longitude,
                    $s->mode,
                    $timezone,
                    CarbonImmutable::now($timezone)->toDateString(),
                ]),
            );
        $saved = $this->cache->get($cacheKey);
        if ($saved && $saved['expires'] > now()->getTimestamp()) {
            return $saved['data'];
        }
        if (!$this->cache->add($cacheKey . ':attempt', true, 300)) {
            return $this->fallback($saved);
        }
        try {
            $query = [
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
                'timezone' => $timezone,
                'forecast_days' => 7,
                'daily' => 'weather_code,temperature_2m_max,precipitation_probability_max',
            ];
            $host = $s->mode === 'commercial' ? 'customer-api.open-meteo.com' : 'api.open-meteo.com';
            if ($s->mode === 'commercial') {
                $query['apikey'] = $key;
            }
            // Fixed HTTPS endpoints; no redirects and no user-supplied URLs.
            $response = $this->http
                ->timeout(8)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false])
                ->get('https://' . $host . '/v1/forecast', $query);
            if (!$response->successful()) {
                throw new \RuntimeException('weather-unavailable');
            }
            $daily = $response->json('daily');
            if (!is_array($daily) || count($daily['time'] ?? []) !== 7) {
                throw new \RuntimeException('weather-invalid');
            }
            $days = [];
            foreach ($daily['time'] as $i => $date) {
                $expected = CarbonImmutable::now($timezone)->addDays($i)->toDateString();
                $code = $daily['weather_code'][$i] ?? null;
                $temp = $daily['temperature_2m_max'][$i] ?? null;
                $rain = $daily['precipitation_probability_max'][$i] ?? null;
                if (
                    $date !== $expected ||
                    !is_numeric($code) ||
                    !is_numeric($temp) ||
                    !is_numeric($rain) ||
                    $rain < 0 ||
                    $rain > 100 ||
                    $temp < -100 ||
                    $temp > 70
                ) {
                    throw new \RuntimeException('weather-invalid');
                }
                $days[] = [
                    'date' => $date,
                    'code' => (int) $code,
                    'temperature' => (float) $temp,
                    'rain_probability' => (int) $rain,
                    'warning' => $rain >= $s->rain_threshold || $code >= 51,
                ];
            }
            $data = [
                'status' => 'fresh',
                'days' => $days,
                'fetched_at' => now()->toIso8601String(),
                'timezone' => $timezone,
            ];
            $this->cache->put($cacheKey, ['expires' => now()->getTimestamp() + 1800, 'data' => $data], 21600);
            return $data;
        } catch (\Throwable) {
            // Provider errors may contain an API key. Never return or log them.
            return $this->fallback($saved);
        }
    }
    private function fallback(?array $saved): array
    {
        return $saved ? [...$saved['data'], 'status' => 'stale'] : ['status' => 'unavailable', 'days' => []];
    }
}
