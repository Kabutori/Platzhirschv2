<?php
namespace App\Modules\Provisioning\PublicApi;
interface ServerDirectory
{
    public function connection(int $id): array;
    public function isEnabled(int $id): bool;
}
