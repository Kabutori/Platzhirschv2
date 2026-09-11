<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prov_db_servers', function (Blueprint $t) {
            $t->boolean('provisioning_enabled')->default(false);
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->foreignId('server_id')->nullable()->constrained('prov_db_servers')->restrictOnDelete();
            $t->unsignedInteger('placement_version')->default(1);
        });
        Schema::create('tenant_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('status', 30)->default('queued');
            $t->unsignedBigInteger('target_server_id')->nullable();
            $t->string('module_code', 60)->nullable();
            $t->string('error_code', 60)->nullable();
            $t->json('result')->nullable();
            $t->unsignedInteger('expected_version');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tenant_operations');
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('server_id');
            $t->dropColumn('placement_version');
        });
        Schema::table('prov_db_servers', fn(Blueprint $t) => $t->dropColumn('provisioning_enabled'));
    }
};
