<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('restaurant_roles', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
                $t->string('name', 120);
                $t->json('permissions');
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['tenant_id', 'name']);
            });
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('users', function (Blueprint $t) {
                $t->foreignId('restaurant_role_id')
                    ->nullable()
                    ->constrained('restaurant_roles')
                    ->restrictOnDelete();
            });
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('users', fn(Blueprint $t) => $t->dropConstrainedForeignId('restaurant_role_id'));
        app('db')->connection()->getSchemaBuilder()->dropIfExists('restaurant_roles');
    }
};
