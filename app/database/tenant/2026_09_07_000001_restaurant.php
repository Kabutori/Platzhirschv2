<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        $schema->create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('color')->default('terracotta');
            $t->boolean('outdoor')->default(false);
            $t->timestamps();
        });
        $schema->create('dining_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('capacity');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $schema->create('opening_hours', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('weekday');
            $t->time('opens');
            $t->time('closes');
            $t->timestamps();
            $t->index('weekday');
        });
        $schema->create('special_days', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->boolean('closed')->default(true);
            $t->time('opens')->nullable();
            $t->time('closes')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        $schema->create('reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('table_id')->constrained('dining_tables');
            $t->string('guest_name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->unsignedSmallInteger('party_size');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('status')->default('confirmed');
            $t->string('source')->default('admin');
            $t->text('notes')->nullable();
            $t->uuid('request_key')->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['table_id', 'starts_at', 'ends_at']);
        });
    }
    public function down(): void
    {
        foreach (['reservations', 'special_days', 'opening_hours', 'dining_tables', 'rooms'] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }
    }
};
