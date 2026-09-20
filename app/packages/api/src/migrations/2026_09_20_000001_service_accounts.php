<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->create('api_service_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name', 100);
            $t->text('operations');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        $s->table('api_tokens', function (Blueprint $t) {
            $t->uuid('service_account_id')->nullable()->index();
            $t->text('operations')->nullable();
            $t->uuid('rotated_to')->nullable();
        });
        $s->table('api_access_events', function (Blueprint $t) {
            $t->uuid('service_account_id')->nullable()->index();
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->table('api_access_events', fn(Blueprint $t) => $t->dropColumn('service_account_id'));
        $s->table(
            'api_tokens',
            fn(Blueprint $t) => $t->dropColumn(['service_account_id', 'operations', 'rotated_to']),
        );
        $s->dropIfExists('api_service_accounts');
    }
};
