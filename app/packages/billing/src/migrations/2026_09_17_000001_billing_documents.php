<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->table('billing_orders', function (Blueprint $t) {
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->timestamp('paid_at')->nullable();
        });
        $s->create('billing_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unsignedInteger('revision')->default(0);
            $t->json('data');
            $t->timestamps();
        });
        app('db')
            ->table('billing_settings')
            ->insert(['id' => 1, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $s->create('billing_profiles', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->primary();
            $t->unsignedInteger('revision')->default(1);
            $t->json('data');
            $t->timestamps();
        });
        $s->create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('order_id')->nullable()->unique();
            $t->unsignedBigInteger('original_id')->nullable()->unique();
            $t->string('kind', 20)->default('invoice');
            $t->string('status', 20)->default('draft');
            $t->string('number', 50)->nullable()->unique();
            $t->bigInteger('total_cents');
            $t->bigInteger('tax_cents');
            $t->json('payload');
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->dropIfExists('billing_invoices');
        $s->dropIfExists('billing_profiles');
        $s->dropIfExists('billing_settings');
        $s->table(
            'billing_orders',
            fn(Blueprint $t) => $t->dropColumn(['period_start', 'period_end', 'paid_at']),
        );
    }
};
