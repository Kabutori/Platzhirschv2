<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('platform_registration_settings', function (Blueprint $t) {
                $t->unsignedInteger('id')->primary();
                $t->boolean('enabled')->default(false);
                $t->string('privacy_url', 1000);
                $t->string('imprint_url', 1000);
                $t->unsignedInteger('revision')->default(1);
                $t->timestamps();
            });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('platform_registration_settings');
    }
};
