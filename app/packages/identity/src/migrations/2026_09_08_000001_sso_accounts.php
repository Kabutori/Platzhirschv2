<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('identity_sso_accounts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->char('provider', 64);
                $t->char('subject_hash', 64);
                $t->timestamps();
                $t->unique(['provider', 'subject_hash']);
                $t->unique(['user_id', 'provider']);
            });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('identity_sso_accounts');
    }
};
