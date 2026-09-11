<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('widget_clients', function (Blueprint $table) {
                $table->unsignedSmallInteger('duration_minutes')->default(90);
                $table->string('accent', 7)->nullable();
            });
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table(
                'widget_clients',
                fn(Blueprint $table) => $table->dropColumn(['duration_minutes', 'accent']),
            );
    }
};
