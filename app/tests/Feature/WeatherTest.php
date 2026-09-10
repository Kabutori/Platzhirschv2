<?php
namespace Tests\Feature;
use App\Models\{Tenant, User};
use App\Services\{TenantDatabase, ReservationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Artisan};
use Tests\TestCase;
class WeatherTest extends TestCase
{
    use RefreshDatabase;
    private Tenant $tenant;
    private User $user;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.tenant' => config('database.connections.sqlite')]);
        DB::purge('tenant');
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => app(\App\Core\Module\ModuleRegistry::class)
                ->get('reservation')
                ->tenantMigrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->mock(TenantDatabase::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnNull();
            $mock->shouldReceive('disconnect')->andReturnNull();
        });
        $this->tenant = Tenant::create([
            'name' => 'Restaurant Test',
            'email' => 'test@example.test',
            'status' => 'active',
            'database_name' => 'ph_t_' . str_repeat('a', 24),
            'database_user' => 'phu_' . str_repeat('a', 24),
            'database_password' => 'not-a-production-secret',
        ]);
        $this->user = User::create([
            'name' => 'Restaurant Admin',
            'email' => 'owner@example.test',
            'password' => 'Strong-test-password-2026',
            'role' => 'restaurant_admin',
            'tenant_id' => $this->tenant->id,
        ]);
        $db = DB::connection('tenant');
        $db->table('rooms')->insert([
            'id' => 1,
            'name' => 'Innenraum',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $db->table('dining_tables')->insert([
            'id' => 1,
            'name' => 'Tisch 1',
            'room_id' => 1,
            'capacity' => 4,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (range(1, 7) as $day) {
            $db->table('opening_hours')->insert([
                'weekday' => $day,
                'opens' => '12:00:00',
                'closes' => '23:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        config(['cache.default' => 'array']);
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => app(\App\Core\Module\ModuleRegistry::class)->get('weather')->tenantMigrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);
        DB::table('billing_entitlements')->insert([
            'tenant_id' => $this->tenant->id,
            'module_code' => 'weather',
            'status' => 'active',
            'paid_until' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        // Eloquent does not hydrate database defaults after INSERT. Authenticate
        // the persisted user just as the real login flow does (active = true).
        $this->actingAs($this->user->refresh());
    }

    private function settings(array $changes = []): array
    {
        return [
            ...[
                'enabled' => true,
                'latitude' => 52.52,
                'longitude' => 13.405,
                'mode' => 'evaluation',
                'rain_threshold' => 60,
                'version' => 0,
            ],
            ...$changes,
        ];
    }
    private function provider(): array
    {
        return [
            'daily' => [
                'time' => array_map(fn($i) => now('Europe/Berlin')->addDays($i)->toDateString(), range(0, 6)),
                'weather_code' => [0, 61, 3, 0, 0, 0, 0],
                'temperature_2m_max' => [20, 18, 19, 20, 21, 22, 23],
                'precipitation_probability_max' => [10, 80, 10, 0, 0, 0, 0],
            ],
        ];
    }
    public function test_forecast_is_cached_warns_and_does_not_change_bookings(): void
    {
        $this->putJson('/api/v1/restaurant/weather/settings', $this->settings())->assertOk();
        \Illuminate\Support\Facades\Http::fake([
            'api.open-meteo.com/*' => \Illuminate\Support\Facades\Http::response($this->provider()),
        ]);
        $this->getJson('/api/v1/restaurant/weather')
            ->assertOk()
            ->assertJsonPath('status', 'fresh')
            ->assertJsonPath('days.1.warning', true)
            ->assertJsonCount(7, 'days');
        $this->getJson('/api/v1/restaurant/weather')->assertOk();
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertSame(0, DB::connection('tenant')->table('room_closures')->count());
        $this->assertSame(0, DB::connection('tenant')->table('reservations')->count());
        $this->putJson('/api/v1/restaurant/weather/settings', $this->settings())->assertConflict();
        $this->putJson(
            '/api/v1/restaurant/weather/settings',
            $this->settings(['version' => 1, 'enabled' => false]),
        )->assertOk();
        $this->getJson('/api/v1/restaurant/weather')
            ->assertJsonPath('status', 'unconfigured')
            ->assertJsonCount(0, 'days');
    }
    public function test_provider_failure_uses_labeled_stale_data_and_never_exposes_errors(): void
    {
        $this->putJson('/api/v1/restaurant/weather/settings', $this->settings())->assertOk();
        \Illuminate\Support\Facades\Http::fake([
            'api.open-meteo.com/*' => \Illuminate\Support\Facades\Http::response($this->provider()),
        ]);
        $this->getJson('/api/v1/restaurant/weather')->assertJsonPath('status', 'fresh');
        $this->travel(31)->minutes();
        \Illuminate\Support\Facades\Http::fake([
            'api.open-meteo.com/*' => \Illuminate\Support\Facades\Http::response(
                ['error' => 'secret-api-key'],
                500,
            ),
        ]);
        $this->getJson('/api/v1/restaurant/weather')
            ->assertJsonPath('status', 'stale')
            ->assertDontSee('secret-api-key');
    }
    public function test_missing_license_inactive_module_and_permission_do_not_call_provider(): void
    {
        config(['weather.api_key' => '']);
        $this->putJson(
            '/api/v1/restaurant/weather/settings',
            $this->settings(['mode' => 'commercial']),
        )->assertOk();
        $this->getJson('/api/v1/restaurant/weather')->assertJsonPath('status', 'provider_missing');
        $this->user->update(['role' => 'staff']);
        $this->putJson(
            '/api/v1/restaurant/weather/settings',
            $this->settings(['version' => 1]),
        )->assertForbidden();
        DB::table('billing_entitlements')
            ->where('module_code', 'weather')
            ->update(['status' => 'disabled']);
        $this->getJson('/api/v1/restaurant/weather')->assertJsonPath('status', 'inactive');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_background_refresh_populates_the_shared_cache_without_a_browser(): void
    {
        $this->putJson('/api/v1/restaurant/weather/settings', $this->settings())->assertOk();
        $this->mock(\App\Contracts\Module\TenantRuntime::class, function ($mock) {
            $mock->shouldReceive('withTenant')->once()->with($this->tenant->id, \Mockery::type(\Closure::class))
                ->andReturnUsing(fn($id, $callback) => $callback((object) [
                    'id' => $id, 'timezone' => 'Europe/Berlin',
                ]));
        });
        \Illuminate\Support\Facades\Http::fake([
            'api.open-meteo.com/*' => \Illuminate\Support\Facades\Http::response($this->provider()),
        ]);
        $this->artisan('weather:refresh')->assertSuccessful();
        $this->getJson('/api/v1/restaurant/weather')->assertJsonPath('status', 'fresh');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertSame(0, DB::connection('tenant')->table('room_closures')->count());
    }

    public function test_background_refresh_skips_unlicensed_tenants_and_continues_after_failure(): void
    {
        $second = $this->tenant->replicate();
        $second->database_name = 'ph_t_' . str_repeat('b', 24);
        $second->database_user = 'phu_' . str_repeat('b', 24);
        $second->save();
        $third = $second->replicate();
        $third->database_name = 'ph_t_' . str_repeat('c', 24);
        $third->database_user = 'phu_' . str_repeat('c', 24);
        $third->save();
        $this->mock(\App\Contracts\Module\ModuleAccess::class, function ($mock) use ($third) {
            $mock->shouldReceive('enabled')->andReturnUsing(fn($id) => $id === $third->id ? [] : ['weather']);
        });
        $this->mock(\App\Contracts\Module\TenantRuntime::class, function ($mock) use ($second, $third) {
            $mock->shouldReceive('withTenant')->once()->with($this->tenant->id, \Mockery::type(\Closure::class))
                ->andThrow(new \RuntimeException('secret-provider-key'));
            $mock->shouldReceive('withTenant')->once()->with($second->id, \Mockery::type(\Closure::class))
                ->andReturn(['status' => 'fresh']);
            $mock->shouldNotReceive('withTenant')->with($third->id, \Mockery::type(\Closure::class));
        });
        $this->artisan('weather:refresh')
            ->expectsOutput('Wetterprüfung: 1 Restaurants geprüft; 1 ohne aktuelle Vorhersage.')
            ->assertFailed();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_weather_refresh_is_scheduled_without_overlap(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn($event) => str_contains($event->command ?? '', 'weather:refresh'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
