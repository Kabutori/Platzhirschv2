<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->json('permissions');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('restaurant_role_id')
                ->nullable()
                ->constrained('restaurant_roles')
                ->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->dropConstrainedForeignId('restaurant_role_id'));
        Schema::dropIfExists('restaurant_roles');
    }
};
