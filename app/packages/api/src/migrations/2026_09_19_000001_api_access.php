<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->create('api_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name', 100);
            $t->string('secret_hash', 64);
            $t->text('scopes');
            $t->string('audience', 8);
            $t->text('cidrs');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
        $s->create('api_settings', function (Blueprint $t) {
            $t->string('module', 80)->primary();
            $t->boolean('enabled')->default(true);
            $t->boolean('mcp_enabled')->default(true);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        $s->create('api_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('token_id');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('status', 20);
            $t->integer('http_status')->nullable();
            $t->longText('response')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['token_id', 'request_key']);
        });
        $s->create('api_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('token_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('operation', 200);
            $t->string('request_hash', 64);
            $t->text('preview');
            $t->timestamp('expires_at');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        $s->create('api_access_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id');
            $t->uuid('token_id')->index();
            $t->string('operation', 200);
            $t->unsignedSmallInteger('http_status');
            $t->timestamp('created_at')->index();
        });
    }
    public function down(): void
    {
        foreach (
            ['api_access_events', 'api_confirmations', 'api_requests', 'api_settings', 'api_tokens']
            as $name
        ) {
            app('db')->connection()->getSchemaBuilder()->dropIfExists($name);
        }
    }
};
