<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->create('reservation_extra_tables', function (Blueprint $t) {
                $t->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
                $t->foreignId('table_id')->constrained('dining_tables')->restrictOnDelete();
                $t->primary(['reservation_id', 'table_id']);
                $t->index('table_id');
            });
    }
    public function down(): void
    {
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('reservation_extra_tables');
    }
};
