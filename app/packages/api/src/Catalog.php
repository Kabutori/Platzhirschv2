<?php
namespace App\Modules\Api;
use Illuminate\Routing\Router;
use Illuminate\Database\DatabaseManager;
use App\Contracts\Module\ModuleAccess;
use App\Core\Module\ModuleRegistry;
class Catalog
{
    private ?array $operations = null;
    public function __construct(
        private Router $router,
        private ModuleRegistry $modules,
        private DatabaseManager $db,
        private ModuleAccess $access,
    ) {}
    public function all(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }
        $sources = [app_path('Api/platform.json')];
        foreach ($this->modules->catalog() as $module) {
            $provider = $this->modules->get($module['code']);
            $source = dirname((new \ReflectionClass($provider))->getFileName()) . '/api.json';
            if (is_file($source)) {
                $sources[] = $source;
            }
        }
        $all = [];
        $routes = [];
        foreach ($this->router->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method . ' ' . $route->uri()] = $route;
            };
        }
        foreach ($sources as $source) {
            $manifest = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
            foreach ($manifest['operations'] as $op) {
                if ($op['exposure'] !== 'external') {
                    continue;
                }
                $route = $routes[$op['method'] . ' ' . $op['uri']] ?? null;
                if (!$route || ltrim($route->getActionName(), '\\') !== ltrim($op['action'], '\\')) {
                    throw new \LogicException('API contract does not match installed route: ' . $op['id']);
                }
                if (isset($all[$op['id']])) {
                    throw new \LogicException('Duplicate API operation.');
                }
                $op['module'] = $manifest['module'];
                $op['path'] =
                    '/api/external/v1/' . $op['module'] . '/' . preg_replace('~^api/v1/~', '', $op['uri']);
                $op['parameters'] = $route->parameterNames();
                $op['route'] = $route;
                $all[$op['id']] = $op;
            }
        }
        return $this->operations = $all;
    }
    public function allowed(array $op, object $token, object $user): bool
    {
        if (($op['admin_only'] ?? false) && !$user->isSystem()) {
            return false;
        }
        if (!$user->active || !$user->canManage()) {
            return false;
        }
        if ($op['context'] === 'admin' && !$user->isSystem()) {
            return false;
        }
        if ($op['context'] === 'restaurant' && $user->isSystem()) {
            return false;
        }
        if (!in_array($op['scope'], json_decode($token->scopes, true), true)) {
            return false;
        }
        foreach (['api', $op['module']] as $module) {
            $setting = $this->db->table('api_settings')->where('module', $module)->first();
            if ($setting && !$setting->enabled) {
                return false;
            }
            if ($token->audience === 'mcp' && $setting && !$setting->mcp_enabled) {
                return false;
            }
        }
        if ($token->audience === 'mcp' && !$op['mcp']) {
            return false;
        }
        if (
            $user->tenant_id &&
            in_array($op['module'], ['reporting', 'weather'], true) &&
            !in_array($op['module'], $this->access->enabled((int) $user->tenant_id), true)
        ) {
            return false;
        }
        return true;
    }
    public function visible(object $token, object $user): array
    {
        return array_values(
            array_map(function ($op) {
                unset($op['route'], $op['action']);
                return $op;
            }, array_filter($this->all(), fn($op) => $this->allowed($op, $token, $user))),
        );
    }
    public function scopes(object $user): array
    {
        $ops = array_filter(
            $this->all(),
            fn($op) => (!($op['admin_only'] ?? false) || $user->isSystem()) &&
                ($op['context'] === 'shared' ||
                    ($user->isSystem() ? $op['context'] === 'admin' : $op['context'] === 'restaurant')),
        );
        return array_values(array_unique(array_column($ops, 'scope')));
    }
}
