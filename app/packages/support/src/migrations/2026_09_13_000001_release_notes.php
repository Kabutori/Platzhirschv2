<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->create('support_releases', function (Blueprint $t) {
                $t->id();
                $t->string('module', 80);
                $t->string('version', 80);
                $t->string('category', 20);
                $t->text('notes');
                $t->string('git_url', 500)->nullable();
                $t->timestamp('published_at')->nullable();
                $t->unsignedInteger('revision')->default(1);
                $t->timestamps();
            });
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('support_releases');
    }
};
