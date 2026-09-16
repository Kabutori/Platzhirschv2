<?php
namespace Tests\Feature;
use App\Models\User;
use App\ModuleUpdates\{Settings,Registry};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File,Http,DB};
use Illuminate\Support\Str;
use Tests\TestCase;
class ModuleUpdatesTest extends TestCase {
 use RefreshDatabase;
 private string $dir;
 protected function setUp(): void {parent::setUp();$this->dir=sys_get_temp_dir().'/ph-registry-'.Str::uuid();File::makeDirectory($this->dir);config(['module_updates.seed'=>$this->dir]);$blob='test archive';File::put($this->dir.'/test.zip',$blob);$p=['name'=>'platzhirsch/test','version'=>'0.1.0','kind'=>'php','source'=>'.','target'=>'app/packages/test','manifest'=>['name'=>'platzhirsch/test','version'=>'0.1.0','require'=>[]],'file'=>'test.zip','sha256'=>hash('sha256',$blob),'sha1'=>sha1($blob)];File::put($this->dir.'/index.json',json_encode(['repositories'=>['platzhirsch-module-test'=>['installed'=>'0.1.0','versions'=>['0.1.0'=>['commit'=>str_repeat('a',40),'packages'=>[$p]],'0.2.0'=>['commit'=>str_repeat('b',40),'packages'=>[[...$p,'version'=>'0.2.0','manifest'=>[...$p['manifest'],'version'=>'0.2.0','require'=>['platzhirsch/missing'=>'0.2.0']]]]]]]]]));}
 protected function tearDown(): void {File::deleteDirectory($this->dir);parent::tearDown();}
 private function login(string $role='system_admin'): void {$this->actingAs(User::create(['name'=>'Admin','email'=>$role.'@example.test','password'=>'Correct-password-123','role'=>$role])->fresh());}
 private function auth(): array{return ['password'=>'Correct-password-123','confirmation'=>true];}
 public function test_admin_only_and_no_credential_leak(): void {
  app(Settings::class)->save(['github_token'=>'secret-value','reader_hash'=>'hash']);
  $this->getJson('/api/v1/admin/module-updates')->assertUnauthorized();$this->login('staff');$this->getJson('/api/v1/admin/module-updates')->assertForbidden();$this->login();$this->getJson('/api/v1/admin/module-updates')->assertOk()->assertJsonPath('github_configured',true)->assertDontSee('secret-value');
 }
 public function test_password_required_and_token_is_encrypted_and_reader_rotates(): void {
  $this->login();$url='/api/v1/admin/module-updates/settings';$this->putJson($url,['password'=>'wrong','confirmation'=>true,'github_token'=>'secret'])->assertStatus(422);
  $one=$this->putJson($url,[...$this->auth(),'github_token'=>'private-token','rotate_reader'=>true])->assertOk()->json('reader_token');$this->assertStringNotContainsString('private-token',DB::table('platform_module_settings')->value('encrypted'));
  $this->withToken($one)->getJson('/api/module-registry/composer/packages.json')->assertOk()->assertJsonStructure(['packages'=>['platzhirsch/test'=>['0.1.0'=>['dist']]]]);
  $two=$this->putJson($url,[...$this->auth(),'rotate_reader'=>true])->assertOk()->json('reader_token');$this->withToken($one)->getJson('/api/module-registry/composer/packages.json')->assertUnauthorized();$this->withToken($two)->getJson('/api/module-registry/files/'.hash('sha256','test archive'))->assertOk();
 }
 public function test_registry_requires_token_even_for_logged_in_admin(): void {$this->login();$this->getJson('/api/module-registry/composer/packages.json')->assertUnauthorized();}
 public function test_preview_rejects_unknown_and_incompatible_selection(): void {
  $this->login();$url='/api/v1/admin/module-updates/preview';$this->postJson($url,['selection'=>['platzhirsch-module-test'=>'0.1.0']])->assertOk()->assertJsonPath('compatible',true);$this->postJson($url,['selection'=>['platzhirsch-module-test'=>'9.9.9']])->assertStatus(422);$this->postJson($url,['selection'=>['platzhirsch-module-test'=>'0.2.0']])->assertOk()->assertJsonPath('compatible',false);$this->postJson($url,['selection'=>['other'=>'0.1.0']])->assertStatus(422);
 }
 public function test_build_dispatch_is_pinned_and_deduplicated(): void {
  $this->login();app(Settings::class)->save(['github_token'=>'secret','reader_hash'=>'','pipeline_configured'=>true]);Http::fake(['*/git/ref/heads/main'=>Http::response(['object'=>['sha'=>str_repeat('c',40)]]),'*/dispatches'=>Http::response(null,204)]);
  $id=(string)Str::uuid();$body=[...$this->auth(),'request_id'=>$id,'selection'=>['platzhirsch-module-test'=>'0.1.0']];$this->postJson('/api/v1/admin/module-updates/builds',$body)->assertStatus(202);$this->postJson('/api/v1/admin/module-updates/builds',$body)->assertStatus(409);$this->assertDatabaseHas('platform_module_builds',['id'=>$id,'status'=>'queued','base_commit'=>str_repeat('c',40)]);
  Http::assertSent(fn($r)=>str_ends_with($r->url(),'/dispatches') && $r['ref']==='main' && $r['inputs']['base_commit']===str_repeat('c',40));$this->assertStringNotContainsString('secret',DB::table('platform_module_builds')->where('id',$id)->value('selection'));
 }
 public function test_failed_build_cannot_stage_and_offline_worker_rejects(): void {
  $this->login();app(Settings::class)->save(['github_token'=>'secret','reader_hash'=>'']);$id=(string)Str::uuid();DB::table('platform_module_builds')->insert(['id'=>$id,'status'=>'queued','selection'=>'{}','base_commit'=>str_repeat('c',40),'actor'=>'1','created_at'=>now(),'updated_at'=>now()]);
  $run=['id'=>12,'display_title'=>'Module composition '.$id,'head_sha'=>str_repeat('c',40),'status'=>'completed','conclusion'=>'failure','run_number'=>120,'run_attempt'=>1];Http::fake(['*'=>Http::response(['workflow_runs'=>[$run]])]);$this->postJson('/api/v1/admin/module-updates/builds/'.$id.'/stage',$this->auth())->assertStatus(422);
  $run['conclusion']='success';Http::fake(['*'=>Http::response(['workflow_runs'=>[$run]])]);config(['operations.directory'=>$this->dir.'/ops']);$this->postJson('/api/v1/admin/module-updates/builds/'.$id.'/stage',$this->auth())->assertStatus(503);
  File::makeDirectory($this->dir.'/ops/public',0755,true);File::makeDirectory($this->dir.'/ops/inbox');File::put($this->dir.'/ops/public/state.json',json_encode(['heartbeat'=>gmdate('c')]));$this->postJson('/api/v1/admin/module-updates/builds/'.$id.'/stage',$this->auth())->assertStatus(202);$job=json_decode(File::get(File::files($this->dir.'/ops/inbox')[0]->getPathname()),true);$this->assertSame('windows-preview-120-1',$job['target']);$this->assertSame('stage-module-update',$job['action']);$this->assertArrayNotHasKey('password',$job);
 }
}
