<?php
namespace App\Contracts\Module;
interface AccountDirectory
{
    public function names(array $ids): array;
}
