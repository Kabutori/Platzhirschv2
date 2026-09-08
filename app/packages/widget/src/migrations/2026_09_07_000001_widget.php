<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        if ($schema->hasTable('widget_clients')) {
            return;
        }
        $schema->create('widget_clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('token_hash', 64)->unique();
            $t->json('origins');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('widget_clients');
    }
};
