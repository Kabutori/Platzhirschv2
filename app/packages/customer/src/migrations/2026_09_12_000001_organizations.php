<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('customer_organizations', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->foreign('parent_id')->references('id')->on('customer_organizations');
            });
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('tenants', function (Blueprint $t) {
                $t->unsignedBigInteger('organization_id')->nullable();
                $t->foreign('organization_id')->references('id')->on('customer_organizations');
            });
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('tenants', function (Blueprint $t) {
                $t->dropForeign(['organization_id']);
                $t->dropColumn('organization_id');
            });
        app('db')->connection()->getSchemaBuilder()->dropIfExists('customer_organizations');
    }
};
