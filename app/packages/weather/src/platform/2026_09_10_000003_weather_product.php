<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->table('billing_products')
            ->insertOrIgnore(['module_code' => 'weather', 'created_at' => now(), 'updated_at' => now()]);
    }
    public function down(): void
    {
        app('db')->table('billing_products')->where('module_code', 'weather')->delete();
    }
};
