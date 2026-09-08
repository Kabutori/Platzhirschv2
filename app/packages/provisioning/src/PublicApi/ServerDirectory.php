<?php
namespace App\Modules\Provisioning\PublicApi;
interface ServerDirectory extends \App\Contracts\Module\ServerTargets
{
    public function connection(int $id): array;
    public function isEnabled(int $id): bool;
}
