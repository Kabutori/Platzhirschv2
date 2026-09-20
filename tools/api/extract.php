<?php
require getcwd().'/app/vendor/autoload.php';
$parser=(new PhpParser\ParserFactory)->createForNewestSupportedVersion();$printer=new PhpParser\PrettyPrinter\Standard;
$classes=[];$files=[];
foreach(['app/app','app/packages'] as $dir){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f){if(!$f->isFile()||$f->getExtension()!=='php')continue;$nodes=$parser->parse(file_get_contents($f));$t=new PhpParser\NodeTraverser;$t->addVisitor(new PhpParser\NodeVisitor\NameResolver);$nodes=$t->traverse($nodes);$finder=new PhpParser\NodeFinder;foreach($finder->findInstanceOf($nodes,PhpParser\Node\Stmt\Class_::class) as $class){if(!isset($class->namespacedName))continue;$name=(string)$class->namespacedName;$classes[$name]=[];foreach($class->getMethods() as $m){$classes[$name][(string)$m->name]=['source'=>$printer->prettyPrint($m->stmts??[]),'parameters'=>array_map(fn($p)=>(string)$p->var->name,$m->params),'node'=>$m];}$files[$name]=(string)$f;}}}
function literal($n,$ctx=[]){
 if($n instanceof PhpParser\Node\Scalar\String_||$n instanceof PhpParser\Node\Scalar\Int_||$n instanceof PhpParser\Node\Scalar\Float_)return $n->value;
 if($n instanceof PhpParser\Node\Expr\ConstFetch)return match(strtolower((string)$n->name)){'true'=>true,'false'=>false,'null'=>null,default=>'@dynamic'};
 if($n instanceof PhpParser\Node\Expr\Variable)return $ctx[$n->name]??'@dynamic';
 if($n instanceof PhpParser\Node\Expr\BinaryOp\Concat){$a=literal($n->left,$ctx);$b=literal($n->right,$ctx);return is_string($a)&&is_string($b)?$a.$b:'@dynamic';}
 if($n instanceof PhpParser\Node\Expr\Ternary){$v=literal($n->cond,$ctx);if($v!=='@dynamic')return literal($v?$n->if:$n->else,$ctx);return ['@variants'=>[literal($n->if,$ctx),literal($n->else,$ctx)]];}
 if($n instanceof PhpParser\Node\Expr\Match_){$variants=[];foreach($n->arms as $arm){$variants[isset($arm->conds[0])?literal($arm->conds[0]):'default']=literal($arm->body,$ctx);}return ['@choices'=>$variants];}
 if($n instanceof PhpParser\Node\Expr\StaticCall && isset($n->class) && (string)$n->name==='rules')return methodLiteral((string)$n->class,(string)$n->name);
 if($n instanceof PhpParser\Node\Expr\MethodCall && $n->var instanceof PhpParser\Node\Expr\Variable && $n->var->name==='this' && in_array((string)$n->name,['partyRules'],true))return methodLiteral($GLOBALS['currentClass'],(string)$n->name);
 if($n instanceof PhpParser\Node\Expr\Array_){$a=[];foreach($n->items as $i){if(!$i)continue;if($i->unpack){$v=literal($i->value,$ctx);if(is_array($v))$a=[...$a,...$v];continue;}if($i->key){$key=literal($i->key,$ctx);if(!is_int($key)&&!is_string($key))$key='@dynamic';$a[$key]=literal($i->value,$ctx);}else $a[]=literal($i->value,$ctx);}return $a;}
 if($n instanceof PhpParser\Node\Expr\StaticCall && (string)$n->name==='in'){$v=literal($n->args[0]->value,$ctx);return is_array($v)?'in:'.implode(',',$v):'@dynamic';}
 return '@dynamic';
}
function methodLiteral($class,$method){$m=$GLOBALS['classes'][$class][$method]['node']??null;if(!$m)return '@dynamic';foreach($m->stmts??[] as $s)if($s instanceof PhpParser\Node\Stmt\Return_)return literal($s->expr);return '@dynamic';}
$out=[];
$finder=new PhpParser\NodeFinder;
foreach($classes as $class=>$methods){foreach($methods as $name=>$data){$GLOBALS['currentClass']=$class;$m=$data['node'];$rules=[];$vars=[];foreach($finder->findInstanceOf($m->stmts??[],PhpParser\Node\Expr\Assign::class) as $assign){if($assign->var instanceof PhpParser\Node\Expr\Variable)$vars[$assign->var->name]=literal($assign->expr,$vars);}
foreach($finder->findInstanceOf($m->stmts??[],PhpParser\Node\Expr\MethodCall::class) as $call){if((string)$call->name==='validate' && isset($call->args[0]))$rules[]=literal($call->args[0]->value,$vars);}
$returns=[];foreach($finder->findInstanceOf($m->stmts??[],PhpParser\Node\Stmt\Return_::class) as $ret)if($ret->expr)$returns[]=$printer->prettyPrintExpr($ret->expr);
$out[$class.'@'.$name]=['file'=>$files[$class],'source'=>$data['source'],'rules'=>$rules,'returns'=>$returns,'return_values'=>array_map(fn($ret)=>literal($ret->expr,$vars),$finder->findInstanceOf($m->stmts??[],PhpParser\Node\Stmt\Return_::class))];}}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
