<?php
namespace App\Modules\Reservation\PublicApi;
interface ReservationGateway
{
    public function catalog(): array;
    public function availableTables(
        string $date,
        int $partySize,
        int $duration,
        string $timezone,
    ): \Illuminate\Support\Collection;
    public function save(array $data, string $timezone, ?int $id = null, string $source = 'admin'): object;
}
