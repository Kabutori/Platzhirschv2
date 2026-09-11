<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        if ($schema->hasTable('support_tickets')) {
            return;
        }
        $schema->create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('subject');
            $t->string('status')->default('open');
            $t->string('priority')->default('normal');
            $t->timestamps();
        });
        $schema->create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets');
            $t->unsignedBigInteger('user_id')->index();
            $t->text('body');
            $t->boolean('internal')->default(false);
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        $s = app('db')->connection()->getSchemaBuilder();
        $s->dropIfExists('support_messages');
        $s->dropIfExists('support_tickets');
    }
};
