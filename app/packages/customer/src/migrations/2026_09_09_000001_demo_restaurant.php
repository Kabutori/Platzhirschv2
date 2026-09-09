<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('tenants', fn(Blueprint $t) => $t->boolean('is_demo')->default(false));
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('tenants', fn(Blueprint $t) => $t->dropColumn('is_demo'));
    }
};
