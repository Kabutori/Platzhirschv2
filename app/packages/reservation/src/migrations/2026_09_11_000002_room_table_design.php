<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        $s = app('db')->connection('tenant')->getSchemaBuilder();
        $s->table('rooms', function (Blueprint $t) {
            $t->string('location', 200)->nullable();
            $t->text('note')->nullable();
            $t->string('icon', 30)->default('room');
        });
        $s->table('dining_tables', function (Blueprint $t) {
            $t->string('shape', 20)->default('rectangle');
            $t->unsignedTinyInteger('layout_x')->nullable();
            $t->unsignedTinyInteger('layout_y')->nullable();
        });
    }
    public function down(): void
    {
        $s = app('db')->connection('tenant')->getSchemaBuilder();
        $s->table('rooms', fn(Blueprint $t) => $t->dropColumn(['location', 'note', 'icon']));
        $s->table('dining_tables', fn(Blueprint $t) => $t->dropColumn(['shape', 'layout_x', 'layout_y']));
    }
};
