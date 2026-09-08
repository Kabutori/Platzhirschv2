<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        if ($schema->hasTable('tenants')) {
            return;
        }
        $schema->create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('status')->default('provisioning');
            $t->string('database_name')->unique();
            $t->string('database_user')->unique();
            $t->text('database_password');
            $t->string('timezone')->default('Europe/Berlin');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('tenants');
    }
};
