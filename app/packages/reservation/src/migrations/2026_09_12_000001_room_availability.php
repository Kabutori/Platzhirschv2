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
            ->create('room_closures', function (Blueprint $t) {
                $t->id();
                $t->foreignId('room_id')->constrained('rooms');
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->string('reason', 250);
                $t->timestamps();
                $t->index(['room_id', 'starts_at', 'ends_at']);
            });
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->create('table_combinations', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->create('table_combination_members', function (Blueprint $t) {
                $t->foreignId('combination_id')->constrained('table_combinations')->cascadeOnDelete();
                $t->foreignId('table_id')->constrained('dining_tables');
                $t->primary(['combination_id', 'table_id']);
            });
    }
    public function down(): void
    {
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('table_combination_members');
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('table_combinations');
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('room_closures');
    }
};
