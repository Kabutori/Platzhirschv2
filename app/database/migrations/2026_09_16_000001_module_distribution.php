<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('platform_module_settings',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->text('encrypted');});
  Schema::create('platform_module_releases',function(Blueprint $t){$t->id();$t->string('repository',100);$t->string('version',30);$t->longText('metadata');$t->unique(['repository','version']);$t->timestamps();});
  Schema::create('platform_module_builds',function(Blueprint $t){$t->uuid('id')->primary();$t->string('status',30);$t->longText('selection');$t->string('base_commit',40);$t->string('actor');$t->unsignedBigInteger('run_id')->nullable();$t->string('release_tag')->nullable();$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('platform_module_builds');Schema::dropIfExists('platform_module_releases');Schema::dropIfExists('platform_module_settings');}
};
