<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('notification_attempts', function (Blueprint $t) {
                $t->id();
                $t->uuid('public_id')->unique();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('event_id');
                $t->string('channel', 10);
                $t->string('status', 30);
                $t->string('provider_sid', 40)->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'event_id', 'id']);
            });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('notification_attempts');
    }
};
