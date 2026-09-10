<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
class SystemOperationsTest extends TestCase {
 use RefreshDatabase;
 private string $directory;
 protected function setUp(): void {parent::setUp();$this->directory=sys_get_temp_dir().'/ph-ops-'.Str::uuid();File::makeDirectory($this->directory.'/public',0755,true);File::makeDirectory($this->directory.'/inbox');config(['operations.directory'=>$this->directory]);$this->state();}
 protected function tearDown(): void {File::deleteDirectory($this->directory);parent::tearDown();}
 private function state(?string $heartbeat=null): void {File::put($this->directory.'/public/state.json',json_encode(['heartbeat'=>$heartbeat??gmdate('c'),'packages'=>[['id'=>'approved','label'=>'Approved']],'backups'=>[],'jobs'=>[]]));}
 private function login(string $role='system_admin'): void {$this->actingAs(User::create(['name'=>'Admin','email'=>$role.'@example.test','password'=>'Correct-password-123','role'=>$role])->fresh());}
 private function payload(): array {return ['action'=>'update','target'=>'approved','password'=>'Correct-password-123','confirmation'=>true,'request_id'=>(string)Str::uuid()];}
 public function test_operations_require_root_admin(): void {$this->getJson('/api/v1/admin/system-operations')->assertUnauthorized();$this->login('staff');$this->getJson('/api/v1/admin/system-operations')->assertForbidden();$this->postJson('/api/v1/admin/system-operations',$this->payload())->assertForbidden();}
 public function test_password_and_approved_target_required(): void {$this->login();$this->postJson('/api/v1/admin/system-operations',[...$this->payload(),'password'=>'wrong'])->assertStatus(422);$this->postJson('/api/v1/admin/system-operations',[...$this->payload(),'target'=>'../evil'])->assertStatus(422);$this->postJson('/api/v1/admin/system-operations',[...$this->payload(),'target'=>'unapproved'])->assertStatus(422);$this->assertCount(0,File::files($this->directory.'/inbox'));}
 public function test_queue_contains_no_password_and_duplicate_is_rejected(): void {$this->login();$p=$this->payload();$this->postJson('/api/v1/admin/system-operations',$p)->assertStatus(202);$this->postJson('/api/v1/admin/system-operations',$p)->assertStatus(409);$raw=File::get($this->directory.'/inbox/'.$p['request_id'].'.json');$this->assertStringNotContainsString('Correct-password',$raw);$this->assertSame('approved',json_decode($raw,true)['target']);$this->assertDatabaseHas('audit_entries',['action'=>'system.operation_requested']);}
 public function test_offline_worker_rejects_request(): void {$this->login();$this->state(gmdate('c',time()-300));$this->getJson('/api/v1/admin/system-operations')->assertJsonPath('available',false);$this->postJson('/api/v1/admin/system-operations',$this->payload())->assertStatus(503);}
}
