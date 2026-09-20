<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('platform_export_jobs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('tenant_id')->nullable();
                $t->uuid('token_id')->nullable();
                $t->string('operation')->nullable();
                $t->string('source', 60);
                $t->string('status', 20);
                $t->unsignedInteger('row_count')->default(0);
                $t->timestamp('expires_at')->index();
                $t->timestamps();
            });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('platform_export_jobs');
    }
};
