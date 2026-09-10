<?php
namespace App\Modules\Weather;
use App\Contracts\Module\{ModuleAccess, AuditSink};
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
class WeatherController
{
    public function __construct(
        private DatabaseManager $db,
        private ModuleAccess $access,
        private AuditSink $audit,
    ) {}
    private function authorize(Request $r): void
    {
        abort_unless($r->user()->hasPermission('restaurant.configure'), 403);
        abort_unless(
            in_array('weather', $this->access->enabled($r->attributes->get('tenant')->id), true),
            403,
            'Wettermodul nicht aktiv.',
        );
    }
    public function settings(Request $r): array
    {
        $this->authorize($r);
        return [
            'settings' => $this->db->connection('tenant')->table('weather_settings')->find(1),
            'commercial_key_configured' => config('weather.api_key') !== '',
        ];
    }
    public function save(Request $r): array
    {
        $this->authorize($r);
        $v = $r->validate([
            'enabled' => 'required|boolean',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'mode' => 'required|in:commercial,evaluation',
            'rain_threshold' => 'required|integer|between:1,100',
            'version' => 'required|integer|min:0',
        ]);
        $db = $this->db->connection('tenant');
        return $db->transaction(function () use ($r, $v, $db) {
            // Create the singleton first; concurrent first saves also share the row lock.
            $db->table('weather_settings')->insertOrIgnore([
                'id' => 1,
                'latitude' => 0,
                'longitude' => 0,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $old = $db->table('weather_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless(
                $old->version === $v['version'] || (int) $old->version === (int) $v['version'],
                409,
                'Wetter-Einstellungen wurden geändert. Bitte neu laden.',
            );
            $db->table('weather_settings')
                ->where('id', 1)
                ->update([...$v, 'version' => $v['version'] + 1, 'updated_at' => now()]);
            $this->audit->record('weather.settings_saved', 1, $r->attributes->get('tenant')->id);
            return ['saved' => true];
        });
    }
}
