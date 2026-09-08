<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        $schema->create('billing_products', function (Blueprint $t) {
            $t->string('module_code', 60)->primary();
            $t->unsignedInteger('amount_cents')->nullable();
            $t->string('currency', 3)->default('EUR');
            $t->boolean('available')->default(false);
            $t->timestamps();
        });
        app('db')
            ->table('billing_products')
            ->insert(['module_code' => 'reporting', 'created_at' => now(), 'updated_at' => now()]);
        $schema->create('billing_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('module_code', 60);
            $t->uuid('request_key');
            $t->unsignedInteger('amount_cents');
            $t->string('currency', 3);
            $t->string('status', 20)->default('pending');
            $t->string('payment_reference', 120)->nullable()->unique();
            $t->timestamps();
            $t->unique(['tenant_id', 'request_key']);
        });
        $schema->create('billing_entitlements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('module_code', 60);
            $t->timestamp('paid_until');
            $t->string('status', 20)->default('inactive');
            $t->string('installed_version', 30)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'module_code']);
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->dropIfExists('billing_entitlements');
        $s->dropIfExists('billing_orders');
        $s->dropIfExists('billing_products');
    }
};
