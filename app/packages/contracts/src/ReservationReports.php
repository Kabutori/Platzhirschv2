<?php
namespace App\Contracts\Module;
interface ReservationReports
{
    public function daily(string $from, string $to): array;
}
