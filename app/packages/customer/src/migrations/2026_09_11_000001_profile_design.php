<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table('tenants', function (Blueprint $t) {
                $t->string('cuisine', 120)->nullable();
                $t->string('price_range', 30)->nullable();
                $t->unsignedSmallInteger('total_seats')->nullable();
                $t->text('description')->nullable();
                $t->string('website', 500)->nullable();
                $t->string('logo_url', 500)->nullable();
            });
    }
    public function down(): void
    {
        app('db')
            ->connection()
            ->getSchemaBuilder()
            ->table(
                'tenants',
                fn(Blueprint $t) => $t->dropColumn([
                    'cuisine',
                    'price_range',
                    'total_seats',
                    'description',
                    'website',
                    'logo_url',
                ]),
            );
    }
};
