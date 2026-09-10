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
            ->create('weather_settings', function (Blueprint $t) {
                $t->unsignedInteger('id')->primary();
                $t->boolean('enabled')->default(false);
                $t->decimal('latitude', 8, 5);
                $t->decimal('longitude', 8, 5);
                $t->string('mode', 20)->default('commercial');
                $t->unsignedInteger('rain_threshold')->default(60);
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
            });
    }
    public function down(): void
    {
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('weather_settings');
    }
};
