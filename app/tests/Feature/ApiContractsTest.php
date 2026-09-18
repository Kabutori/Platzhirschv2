<?php
namespace Tests\Feature;
use Tests\TestCase;
class ApiContractsTest extends TestCase
{
    public function test_every_business_route_is_classified_and_external_routes_keep_authentication(): void
    {
        $sources = [app_path('Api/platform.json')];
        $registry = app(\App\Core\Module\ModuleRegistry::class);
        foreach ($registry->catalog() as $m) {
            $file = dirname((new \ReflectionClass($registry->get($m['code'])))->getFileName()) . '/api.json';
            $this->assertFileExists($file, $m['code'] . ' needs an API contract');
            $sources[] = $file;
        }
        $declared = [];
        foreach ($sources as $source) {
            $json = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $json['schema']);
            foreach ($json['operations'] as $op) {
                $key = $op['method'] . ' ' . $op['uri'];
                $this->assertArrayNotHasKey($key, $declared);
                $declared[$key] = $op;
            }
        }
        foreach (app('router')->getRoutes() as $route) {
            if (
                !str_starts_with($route->uri(), 'api/') ||
                str_starts_with($route->uri(), 'api/external/') ||
                str_starts_with($route->uri(), 'api/v1/access')
            ) {
                continue;
            }
            $key = $route->methods()[0] . ' ' . $route->uri();
            $this->assertArrayHasKey($key, $declared, 'Undeclared route: ' . $key);
            $op = $declared[$key];
            $this->assertSame(ltrim($route->getActionName(), '\\'), ltrim($op['action'], '\\'));
            if ($op['exposure'] === 'external') {
                $this->assertContains('auth', $route->middleware());
                $this->assertStringNotContainsString('/auth/', $op['uri']);
                $this->assertNotEmpty($op['scope']);
            } else {
                $this->assertNotEmpty($op['reason']);
            }
        }
        $this->assertGreaterThan(90, count(app(\App\Modules\Api\Catalog::class)->all()));
    }
}
