<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->create('billing_automation', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->text('secrets')->nullable();
            $t->boolean('enabled')->default(false);
            $t->boolean('live')->default(false);
            $t->boolean('send_invoices')->default(false);
            $t->boolean('send_reminders')->default(false);
            $t->unsignedInteger('next_test_number')->default(1);
            $t->unsignedInteger('reminder_days')->default(7);
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        app('db')
            ->table('billing_automation')
            ->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $s->create('billing_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->unsignedBigInteger('tenant_id');
            $t->string('module_code', 60);
            $t->unsignedInteger('amount_cents');
            $t->string('state', 30)->default('checkout');
            $t->string('provider_id', 100)->nullable()->unique();
            $t->string('customer_id', 100)->nullable();
            $t->string('checkout_id', 100)->nullable();
            $t->text('checkout_url')->nullable();
            $t->timestamp('checkout_expires')->nullable();
            $t->boolean('live');
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestamp('period_end')->nullable();
            $t->timestamp('consented_at');
            $t->timestamp('synced_at')->nullable();
            $t->string('sync_error', 160)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'module_code']);
        });
        $s->table('billing_invoices', function (Blueprint $t) {
            $t->string('provider_id', 100)->nullable()->unique();
            $t->unsignedBigInteger('subscription_id')->nullable()->index();
            $t->string('payment_status', 20)->default('paid');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('settled_at')->nullable();
        });
        $s->create('billing_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id')->index();
            $t->string('kind', 20);
            $t->unsignedInteger('level')->default(0);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->timestamp('sent_at')->nullable();
            $t->string('error', 160)->nullable();
            $t->timestamps();
            $t->unique(['invoice_id', 'kind', 'level']);
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->dropIfExists('billing_deliveries');
        $s->table(
            'billing_invoices',
            fn(Blueprint $t) => $t->dropColumn([
                'provider_id',
                'subscription_id',
                'payment_status',
                'due_at',
                'settled_at',
            ]),
        );
        $s->dropIfExists('billing_subscriptions');
        $s->dropIfExists('billing_automation');
    }
};
