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
            }
        }
        foreach ($sources as $source) {
            $manifest = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
            $original = json_decode(file_get_contents($source), flags: JSON_THROW_ON_ERROR);
            foreach ($manifest['operations'] as $index => $op) {
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
                foreach ($op['contract']['responses'] as $code => &$response) {
                    foreach ($response['content'] ?? [] as $mime => $definition) {
                        if (array_key_exists('example', $definition)) {
                            $response['content'][$mime]['example'] =
                                $original->operations[
                                    $index
                                ]->contract->responses->{(string) $code}->content->{$mime}->example;
                        }
                    }
                }
                unset($response);
                foreach (['parameters', 'query', 'body'] as $part) {
                    $op['contract']['example'][$part] =
                        $original->operations[$index]->contract->example->{$part};
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
        if (
            ($token->operations ?? null) !== null &&
            !in_array($op['id'], json_decode($token->operations, true), true)
        ) {
            return false;
        }
        if ($token->service_account_id ?? null) {
            $account = $this->db->table('api_service_accounts')->find($token->service_account_id);
            if (
                !$account ||
                !$account->active ||
                (int) $account->user_id !== (int) $token->user_id ||
                (string) $account->tenant_id !== (string) $token->tenant_id ||
                !in_array($op['id'], json_decode($account->operations, true), true)
            ) {
                return false;
            }
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
                $op['contract'] = self::schemaObjects($op['contract']);
                return $op;
            }, array_filter($this->all(), fn($op) => $this->allowed($op, $token, $user))),
        );
    }
    private static function schemaObjects(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::schemaObjects($item);
                if (
                    in_array(
                        $key,
                        [
                            'properties',
                            'items',
                            'not',
                            'additionalProperties',
                            'patternProperties',
                            '$defs',
                            'schema',
                        ],
                        true,
                    ) &&
                    $item === []
                ) {
                    $value[$key] = (object) [];
                }
            }
        }
        return $value;
    }
    public function manageable(object $user): array
    {
        return array_values(
            array_filter(
                $this->all(),
                fn($op) => (!($op['admin_only'] ?? false) || $user->isSystem()) &&
                    ($op['context'] === 'shared' ||
                        ($user->isSystem() ? $op['context'] === 'admin' : $op['context'] === 'restaurant')),
            ),
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
