<?php
namespace App\Modules\Reservation\Application;
use Illuminate\Database\DatabaseManager;
class ReservationReadModel implements \App\Contracts\Module\ReservationReadModel
{
    public function __construct(private DatabaseManager $db) {}
    public function findForNotification(int $id): ?object
    {
        return $this->db->connection('tenant')->table('reservations')->find($id);
    }
}
