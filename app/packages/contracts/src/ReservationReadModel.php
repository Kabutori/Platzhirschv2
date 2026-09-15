<?php
namespace App\Contracts\Module;
interface ReservationReadModel
{
    public function findForNotification(int $id): ?object;
}
