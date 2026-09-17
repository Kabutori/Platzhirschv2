<?php
namespace App\Modules\Billing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
class RunBilling implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $tries = 1;
    public int $uniqueFor = 3600;
    public int $timeout = 1800;
    public function handle(RecurringBilling $billing): void
    {
        $billing->run();
    }
}
