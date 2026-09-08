<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    protected $connection = 'tenant';
    public function up(): void
    {
        app('db')
            ->connection('tenant')
            ->getSchemaBuilder()
            ->create('reporting_saved_reports', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->date('date_from');
                $t->date('date_to');
                $t->timestamps();
            });
    }
    public function down(): void
    {
        app('db')->connection('tenant')->getSchemaBuilder()->dropIfExists('reporting_saved_reports');
    }
};
