<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        $s = app('db')->connection('tenant')->getSchemaBuilder();
        $s->create('reservation_notification_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('email_enabled')->default(false);
            $t->boolean('sms_enabled')->default(false);
            $t->unsignedInteger('reminder_minutes')->default(120);
        });
        app('db')
            ->connection('tenant')
            ->table('reservation_notification_settings')
            ->insert(['id' => 1]);
        $s->create('reservation_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('channel', 10);
            $t->string('kind', 20);
            $t->string('status', 20)->default('pending');
            $t->dateTime('due_at');
            $t->dateTime('processed_at')->nullable();
            $t->unique(['reservation_id', 'version', 'channel', 'kind'], 'reservation_notification_event');
            $t->index(['status', 'due_at']);
        });
    }
    public function down(): void
    {
        $s = app('db')->connection('tenant')->getSchemaBuilder();
        $s->dropIfExists('reservation_notifications');
        $s->dropIfExists('reservation_notification_settings');
    }
};
