<?php
namespace App\Modules\Identity\Application;
use Illuminate\Database\DatabaseManager;
use App\Core\Module\ModuleRegistry;
class RoleDirectory implements \App\Modules\Identity\PublicApi\RoleDirectory
{
    public function __construct(private DatabaseManager $db, private ModuleRegistry $registry) {}
    public function permissions(?int $roleId): array
    {
        if (!$roleId) {
            return [];
        }
        $json = $this->db->table('identity_platform_roles')->where('id', $roleId)->value('permissions');
        $catalog = [];
        foreach ($this->registry->permissionFamilies() as $family) {
            if (!in_array($family['scope'] ?? 'administration', ['administration', 'both'], true)) {
                continue;
            }
            foreach ($family['permissions'] as $p) {
                $catalog[] = $p['code'];
            }
        }
        return array_values(array_intersect(json_decode($json ?? '[]', true), $catalog));
    }
    public function assignable(int $roleId): bool
    {
        return $this->db
            ->table('identity_platform_roles')
            ->where('id', $roleId)
            ->where('locked', false)
            ->whereNotNull('activated_at')
            ->exists();
    }
}
