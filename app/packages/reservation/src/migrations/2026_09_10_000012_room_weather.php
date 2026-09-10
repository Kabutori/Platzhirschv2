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
            ->table('rooms', fn(Blueprint $t) => $t->boolean('weather_dependent')->default(false));
    }
    public function down(): void
    {
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->table('rooms', fn(Blueprint $t) => $t->dropColumn('weather_dependent'));
    }
};
