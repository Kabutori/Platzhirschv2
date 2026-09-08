<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
return new class extends Migration {
    public function up(): void
    {
        $db = app('db');
        $db->connection()
            ->getSchemaBuilder()
            ->create('identity_platform_roles', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120)->unique();
                $t->boolean('locked')->default(false);
                $t->json('permissions');
                $t->json('draft_permissions');
                $t->unsignedInteger('version')->default(1);
                $t->unsignedInteger('tested_version')->nullable();
                $t->timestamp('activated_at')->nullable();
                $t->timestamps();
            });
        foreach (
            [
                ['System Administrator', true, ['*']],
                ['Support', false, ['platform.audit.read', 'platform.health.read', 'support.access']],
            ]
            as [$name, $locked, $permissions]
        ) {
            $db->table('identity_platform_roles')->insert([
                'name' => $name,
                'locked' => $locked,
                'permissions' => json_encode($permissions),
                'draft_permissions' => json_encode($permissions),
                'version' => 1,
                'tested_version' => 1,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists('identity_platform_roles');
    }
};
