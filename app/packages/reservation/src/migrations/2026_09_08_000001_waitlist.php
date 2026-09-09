<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->create('reservation_waitlist', function (Blueprint $t) {
                $t->id();
                $t->string('guest_name', 120);
                $t->string('email')->nullable();
                $t->string('phone', 50)->nullable();
                $t->unsignedSmallInteger('party_size');
                $t->dateTime('requested_at');
                $t->unsignedSmallInteger('duration_minutes');
                $t->text('notes')->nullable();
                $t->string('status')->default('waiting');
                $t->foreignId('reservation_id')->nullable()->constrained('reservations');
                $t->uuid('request_key')->unique();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->index(['requested_at', 'status']);
            });
    }
    public function down(): void
    {
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('reservation_waitlist');
    }
};
