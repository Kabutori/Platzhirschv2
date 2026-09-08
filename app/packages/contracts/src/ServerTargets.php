<?php
namespace App\Contracts\Module;
interface ServerTargets
{
    public function isEnabled(int $id): bool;
}
