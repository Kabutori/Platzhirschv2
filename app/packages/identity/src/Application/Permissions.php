<?php
namespace App\Modules\Identity\Application;
class Permissions
{
    public static function catalog(): array
    {
        $catalog = [];
        foreach (app(\App\Core\Module\ModuleRegistry::class)->permissionFamilies() as $family) {
            if (!in_array($family['scope'] ?? '', ['restaurant', 'both'], true)) {
                continue;
            }
            foreach ($family['permissions'] as $permission) {
                $catalog[$permission['code']] = $permission['label'];
            }
        }
        return $catalog;
    }
    public const STAFF = [
        'reservation.read',
        'reservation.write',
        'reservation.cancel',
        'reservation.export',
        'support.access',
    ];
}
