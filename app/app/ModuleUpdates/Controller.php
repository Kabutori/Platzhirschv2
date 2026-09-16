<?php
namespace App\ModuleUpdates;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
class Controller {
 private function confirm(Request $r): void {$v=$r->validate(['password'=>'required|string','confirmation'=>'required|accepted']);abort_unless(Hash::check($v['password'],$r->user()->password),422,'Passwort nicht korrekt.');}
 public function index(Registry $registry,Settings $settings) {
  $s=$settings->read();return response()->json(['repositories'=>$registry->catalog(),'github_configured'=>$s['github_token']!=='','reader_configured'=>$s['reader_hash']!=='','pipeline_configured'=>$s['pipeline_configured']??false,'registry_url'=>url('/api/module-registry'),'builds'=>DB::table('platform_module_builds')->orderByDesc('created_at')->limit(20)->get(['id','status','run_id','release_tag','created_at'])]);
 }
 public function settings(Request $r,Settings $settings) {
  $this->confirm($r);$v=$r->validate(['github_token'=>'nullable|string|max:1024','clear_token'=>'sometimes|boolean','configure_pipeline'=>'sometimes|boolean','rotate_reader'=>'sometimes|boolean']);$s=$settings->read();
  if($v['clear_token']??false){$s['github_token']='';$s['pipeline_configured']=false;}elseif(!empty($v['github_token'])){$s['github_token']=$v['github_token'];$s['pipeline_configured']=false;}
  $reader=null;if($v['rotate_reader']??false){$reader=Str::random(64);$s['reader_hash']=hash('sha256',$reader);}$settings->save($s);
  if($v['configure_pipeline']??false){
   $key=$settings->github(Settings::REPOSITORY.'/actions/secrets/public-key');
   abort_unless(function_exists('sodium_crypto_box_seal'),503,'PHP-Sodium wird für die CI-Einrichtung benötigt.');
   $encrypted=base64_encode(sodium_crypto_box_seal($s['github_token'],base64_decode($key['key'])));
   $settings->github(Settings::REPOSITORY.'/actions/secrets/MODULE_REPOSITORY_TOKEN','PUT',['encrypted_value'=>$encrypted,'key_id'=>$key['key_id']]);$s['pipeline_configured']=true;$settings->save($s);
  }
  Audit::record('modules.registry_configured',1);return response()->json(['saved'=>true,'reader_token'=>$reader]);
 }
 public function sync(Request $r,Registry $registry,Settings $settings){$v=$r->validate(['repository'=>'required|string|max:100']);$result=$registry->sync($v['repository'],$settings);Audit::record('modules.registry_synced',$v['repository']);return response()->json($result);}
 public function preview(Request $r,Registry $registry){$v=$r->validate(['selection'=>'required|array','selection.*'=>'required|string|max:30']);return response()->json($registry->preview($v['selection']));}
 public function build(Request $r,Registry $registry,Settings $settings){
  $this->confirm($r);$v=$r->validate(['selection'=>'required|array','selection.*'=>'required|string|max:30','request_id'=>'required|uuid']);$plan=$registry->preview($v['selection']);abort_unless($plan['compatible'],422,implode('; ',$plan['errors']));
  abort_unless($settings->read()['pipeline_configured']??false,422,'Bitte zuerst die Build-Pipeline in den Paketquellen-Einstellungen einrichten.');
  $id=strtolower($v['request_id']);abort_if(DB::table('platform_module_builds')->where('id',$id)->exists(),409,'Buildauftrag bereits vorhanden. Status aktualisieren.');
  $ref=$settings->github(Settings::REPOSITORY.'/git/ref/heads/main');$commit=$ref['object']['sha'];
  DB::table('platform_module_builds')->insert(['id'=>$id,'status'=>'dispatching','selection'=>json_encode($plan['selection'],JSON_THROW_ON_ERROR),'base_commit'=>$commit,'actor'=>(string)$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
  try{$settings->github(Settings::REPOSITORY.'/actions/workflows/windows-package.yml/dispatches','POST',['ref'=>'main','inputs'=>['version'=>'0.1.0-modules.'.$id,'composition'=>json_encode($plan['selection'],JSON_THROW_ON_ERROR),'request_id'=>$id,'base_commit'=>$commit]]);DB::table('platform_module_builds')->where('id',$id)->update(['status'=>'queued','updated_at'=>now()]);}
  catch(\Throwable $e){DB::table('platform_module_builds')->where('id',$id)->update(['status'=>'dispatch_failed','updated_at'=>now()]);throw $e;}
  Audit::record('modules.build_requested',$id);return response()->json(['id'=>$id,'status'=>'queued'],202);
 }
 public function refresh(string $id,Settings $settings){
  $row=DB::table('platform_module_builds')->where('id',$id)->first();abort_unless($row,404);
  $runs=$settings->github(Settings::REPOSITORY.'/actions/workflows/windows-package.yml/runs?event=workflow_dispatch&per_page=100');
  $run=collect($runs['workflow_runs'])->first(fn($x)=>$x['display_title']==='Module composition '.$id && $x['head_sha']===$row->base_commit);
  if($run){$status=$run['status']==='completed'?($run['conclusion']==='success'?'ready':$run['conclusion']):$run['status'];$tag=$status==='ready'?'windows-preview-'.$run['run_number'].'-'.$run['run_attempt']:null;DB::table('platform_module_builds')->where('id',$id)->update(['status'=>$status,'run_id'=>$run['id'],'release_tag'=>$tag,'updated_at'=>now()]);}
  return response()->json(DB::table('platform_module_builds')->where('id',$id)->first(['id','status','run_id','release_tag']));
 }
 public function stage(Request $r,string $id,Settings $settings){
  $this->confirm($r);$this->refresh($id,$settings);$row=DB::table('platform_module_builds')->where('id',$id)->first();abort_unless($row->status==='ready' && $row->release_tag,422,'Build noch nicht erfolgreich abgeschlossen.');
  $directory=config('operations.directory');$file=$directory.'/public/state.json';$state=is_file($file)?json_decode(file_get_contents($file),true):[];abort_unless(strtotime($state['heartbeat']??'1970-01-01')>time()-90,503,'Windows-Betriebsverwaltung nicht erreichbar.');
  $job=(string)Str::uuid();$handle=@fopen($directory.'/inbox/'.$job.'.json','x');abort_unless($handle,503,'Auftrag kann nicht gespeichert werden.');
  try{fwrite($handle,json_encode(['id'=>$job,'action'=>'stage-module-update','target'=>$row->release_tag,'actor'=>(string)$r->user()->id,'createdAt'=>gmdate('c')],JSON_THROW_ON_ERROR));}finally{fclose($handle);}
  Audit::record('modules.update_staged',$id);return response()->json(['id'=>$job,'status'=>'queued'],202);
 }
}
