<?php
namespace App\Operations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\Audit;
class Controller
{
    private function directory(): string { return config('operations.directory'); }
    public function index() {
        $file=$this->directory().'/public/state.json';
        if (!is_file($file)) return response()->json(['available'=>false,'backups'=>[],'packages'=>[],'jobs'=>[]]);
        $state=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        $state['available']=isset($state['heartbeat']) && strtotime($state['heartbeat']) > time()-90;
        return response()->json($state);
    }
    public function store(Request $request) {
        $input=$request->validate(['action'=>'required|in:backup,verify,restore,rollback,update,check','target'=>'nullable|regex:/\A[a-zA-Z0-9_-]{1,100}\z/','password'=>'required|string','confirmation'=>'required|accepted','request_id'=>'required|uuid']);
        abort_unless(Hash::check($input['password'],$request->user()->password),422,'Passwort nicht korrekt.');
        $dir=$this->directory();
        abort_unless(is_file($dir.'/public/state.json'),503,'Windows-Betriebsverwaltung ist nicht eingerichtet.');
        $state=json_decode(file_get_contents($dir.'/public/state.json'),true,512,JSON_THROW_ON_ERROR);
        abort_unless(strtotime($state['heartbeat']??'1970-01-01')>time()-90,503,'Windows-Betriebsverwaltung ist nicht erreichbar.');
        if(in_array($input['action'],['verify','restore','rollback','update'])) {
            $list=$input['action']==='update' ? ($state['packages']??[]) : ($state['backups']??[]);
            abort_unless(in_array($input['target']??'',array_column($list,'id'),true),422,'Ziel ist nicht verfügbar.');
        }
        // Exclusive creation deduplicates retries; credentials never leave the request.
        $id=strtolower($input['request_id']); $file=$dir.'/inbox/'.$id.'.json';
        $handle=@fopen($file,'x');
        abort_unless($handle,409,'Auftrag bereits übergeben. Bitte Status aktualisieren.');
        try { fwrite($handle,json_encode(['id'=>$id,'action'=>$input['action'],'target'=>$input['target']??null,'actor'=>(string)$request->user()->id,'createdAt'=>gmdate('c')],JSON_THROW_ON_ERROR)); } finally { fclose($handle); }
        Audit::record('system.operation_requested', $id);
        return response()->json(['id'=>$id,'status'=>'queued'],202);
    }
}
