<?php
namespace App\Services;
class Permissions
{
    public const CATALOG = [
        'reservation.read' => 'Reservierungen und Belegung ansehen',
        'reservation.write' => 'Reservierungen anlegen und bearbeiten',
        'reservation.cancel' => 'Reservierungen stornieren',
        'reservation.export' => 'Gästedaten exportieren',
        'restaurant.configure' => 'Räume, Tische und Öffnungszeiten verwalten',
        'restaurant.profile' => 'Restaurantprofil bearbeiten',
        'widget.manage' => 'Buchungswidget verwalten',
        'support.access' => 'Support-Tickets lesen und bearbeiten',
    ];
    public static function catalog(): array
    {
        $catalog = self::CATALOG;
        foreach (app(\App\Core\Module\ModuleRegistry::class)->permissionFamilies() as $family) {
            if (($family['scope'] ?? '') !== 'restaurant') {
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
