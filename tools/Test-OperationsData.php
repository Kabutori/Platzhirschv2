<?php
[$script,$root,$mode]=array_pad($argv,3,null);
require $root.'/app/vendor/autoload.php';
$app=require $root.'/app/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    $state=json_decode(file_get_contents($root.'/installation.json'),true,512,JSON_THROW_ON_ERROR);
    $local=new PDO('mysql:host=127.0.0.1;port='.$state['databasePort'].';dbname=platzhirsch_platform;charset=utf8mb4','root',$state['rootPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($mode==='recovery-restrict' || $mode==='recovery-grant') {
        $access=json_decode(file_get_contents($root.'/ci-recovery-access.json'),true,512,JSON_THROW_ON_ERROR);
        $pdo=new PDO('mysql:host=127.0.0.1;port=3309','root',$access['root'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $account="'ph_recovery_ci'@'127.0.0.1'";
        if($mode==='recovery-restrict')$pdo->exec('CREATE USER '.$account.' IDENTIFIED BY '.$pdo->quote($access['limited']));
        else $pdo->exec('GRANT ALL PRIVILEGES ON *.* TO '.$account.' WITH GRANT OPTION');
    } elseif($mode==='seed') {
        $local->exec('CREATE TABLE ops_recovery_fixture (id INT PRIMARY KEY, payload MEDIUMBLOB NOT NULL)');
        $insert=$local->prepare('INSERT INTO ops_recovery_fixture VALUES (?,?)');$local->beginTransaction();
        for($i=0;$i<10000;$i++)$insert->execute([$i,hash('sha256',(string)$i,true).str_repeat('Recovery-data-',80)]);
        $local->commit();
    } elseif($mode==='change') {
        $local->exec('UPDATE ops_recovery_fixture SET payload=CONCAT(payload,\'changed\') WHERE id=1');
        foreach(\App\Models\Tenant::whereNotNull('server_id')->get() as $tenant){
            [$remote]=app(\App\Services\ProvisioningConnection::class)->open($tenant->server_id);
            $remote->exec('CREATE TABLE `'.$tenant->database_name.'`.ops_after_backup (id INT)');
        }
    } elseif($mode==='check') {
        if((int)$local->query('SELECT COUNT(*) FROM ops_recovery_fixture')->fetchColumn()!==10000)throw new RuntimeException('Row count');
        if($local->query('SELECT payload FROM ops_recovery_fixture WHERE id=1')->fetchColumn()!==hash('sha256','1',true).str_repeat('Recovery-data-',80))throw new RuntimeException('Binary content');
        foreach(\App\Models\Tenant::whereNotNull('server_id')->get() as $tenant){
            [$remote]=app(\App\Services\ProvisioningConnection::class)->open($tenant->server_id);
            $q=$remote->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'ops_after_backup\'');$q->execute([$tenant->database_name]);if($q->fetchColumn())throw new RuntimeException('Remote rollback failed');
        }
        foreach(\App\Models\Tenant::where('status','active')->get() as $tenant){
            $db=app(\App\Services\TenantDatabase::class);$db->connect($tenant);
            try{
                $denied=false;
                try{DB::connection('tenant')->statement('CREATE TABLE ops_forbidden_ddl (id INT)');}catch(\Illuminate\Database\QueryException){$denied=true;}
                if(!$denied)throw new RuntimeException('Tenant retained DDL permissions');
            }finally{$db->disconnect();}
        }
    } elseif($mode==='updated' || $mode==='rolled-back') {
        $expected=$mode==='updated'?1:0;
        $q=$local->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='platzhirsch_platform' AND TABLE_NAME='ops_update_fixture'");
        if((int)$q->fetchColumn()!==$expected)throw new RuntimeException('Platform migration/rollback');
        foreach(\App\Models\Tenant::where('status','active')->get() as $tenant){
            $db=app(\App\Services\TenantDatabase::class);$db->connect($tenant);
            try{if((int)DB::connection('tenant')->selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ops_update_fixture'")->n!==$expected)throw new RuntimeException('Tenant migration/rollback');}finally{$db->disconnect();}
        }
    }
    echo "Operations data check: $mode passed.\n";
} catch(Throwable $e){fwrite(STDERR,'Operations fixture failed: '.get_class($e)." at line ".$e->getLine()."\n");exit(1);}
