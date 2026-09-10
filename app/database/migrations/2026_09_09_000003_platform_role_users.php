<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table(
            'users',
            fn(Blueprint $t) => $t
                ->foreignId('platform_role_id')
                ->nullable()
                ->constrained('identity_platform_roles')
                ->restrictOnDelete(),
        );
    }
    public function down(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->dropConstrainedForeignId('platform_role_id'));
    }
};
