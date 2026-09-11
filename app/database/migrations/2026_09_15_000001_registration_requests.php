<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('registration_requests', function (Blueprint $t) {
            $t->id();
            $t->string('email', 254)->unique();
            $t->string('business_name', 120);
            $t->string('owner_name', 120);
            $t->string('website', 2048);
            $t->string('domain', 253);
            $t->string('category', 20);
            $t->json('keywords');
            $t->char('token_hash', 64)->nullable()->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('registration_requests'); }
};
