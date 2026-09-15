<?php
namespace App\Contracts\Module;
interface ReservationNotifier
{
    public function readiness(): array;
    public function enqueue(object $reservation): void;
    public function dispatch(string $restaurant, string $timezone, int $limit = 20): void;
}
