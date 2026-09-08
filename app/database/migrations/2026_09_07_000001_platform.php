<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
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
        Schema::create('users', function (Blueprint $t) {
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
        Schema::create('bootstrap_state', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('completed')->default(false);
        });
        DB::table('bootstrap_state')->insert(['id' => 1, 'completed' => false]);
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->string('action');
            $t->string('resource')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at');
        });

    }
    public function down(): void
    {
        foreach (
            [
                'audit_entries',
                'failed_jobs',
                'jobs',
                'cache_locks',
                'cache',
                'sessions',
                'password_reset_tokens',
                'bootstrap_state',
                'users',
                'tenants',
            ]
            as $name
        ) {
            Schema::dropIfExists($name);
        }
    }
};
