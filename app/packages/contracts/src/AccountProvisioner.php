<?php
namespace App\Contracts\Module;
interface AccountProvisioner
{
    public function createOwner(array $data): void;
}
