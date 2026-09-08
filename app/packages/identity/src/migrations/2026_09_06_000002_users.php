<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        if ($schema->hasTable('users')) {
            return;
        }
        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('staff');
            $t->boolean('active')->default(true);
            $t->text('mfa_secret')->nullable();
            $t->unsignedBigInteger('mfa_last_step')->default(0);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        $schema->create('bootstrap_state', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('completed')->default(false);
        });
        app('db')
            ->table('bootstrap_state')
            ->insert(['id' => 1, 'completed' => false]);
        $schema->create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->dropIfExists('password_reset_tokens');
        $s->dropIfExists('bootstrap_state');
        $s->dropIfExists('users');
    }
};
