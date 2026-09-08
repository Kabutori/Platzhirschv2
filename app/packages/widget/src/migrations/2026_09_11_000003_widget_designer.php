<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('widget_clients', function (Blueprint $t) {
                $t->string('language', 2)->default('de');
                $t->string('position', 20)->default('inline');
                $t->unsignedTinyInteger('max_party_size')->default(50);
                $t->boolean('show_brand')->default(true);
            });
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table(
                'widget_clients',
                fn(Blueprint $t) => $t->dropColumn(['language', 'position', 'max_party_size', 'show_brand']),
            );
    }
};
