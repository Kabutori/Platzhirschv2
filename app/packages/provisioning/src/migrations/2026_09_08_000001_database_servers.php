<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        $schema->create('prov_db_servers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('host', 253);
            $t->unsignedSmallInteger('port');
            $t->string('region', 40);
            $t->string('purpose', 20);
            $t->string('database', 64);
            $t->string('username', 128);
            $t->text('password');
            $t->boolean('tls_required')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('prov_db_servers');
    }
};
