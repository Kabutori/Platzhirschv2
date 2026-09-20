#!/usr/bin/env python3
"""php tools/api/extract.php > /tmp/api-methods.json; python tools/api/generate.py /tmp/api-methods.json
Regenerate committed per-module JSON Schemas after reviewing schemas.py and controller changes.
"""
import json,sys,re,hashlib,copy
from pathlib import Path
from schemas import *
root=Path(__file__).resolve().parents[2]
methods=json.load(open(sys.argv[1]))
def rules_for(action,seen=None):
 seen=set(seen or []);
 if action in seen:return []
 seen.add(action);data=methods.get(action,{})
 rules=list(data.get('rules',[]));cls=action.split('@')[0]
 for helper in re.findall(r'\$this->(\w+)\(\$r(?:\W|$)',data.get('source','')):
  if helper not in ['index','show','save']:rules+=rules_for(cls+'@'+helper,seen)
 return rules

def resolve(value,op):
 if isinstance(value,dict) and '@variants' in value:
  return resolve(value['@variants'][0 if op['method']=='PATCH' else 1],op)
 if isinstance(value,list):return [resolve(v,op) for v in value]
 if isinstance(value,dict):return {k:resolve(v,op) for k,v in value.items()}
 return value

def field_schema(value):
 rules=value if isinstance(value,list) else str(value).split('|')
 rules=[str(v) for v in rules if v!='@dynamic']
 typ='object' if any(x.startswith('array:') for x in rules) else 'integer' if 'integer' in rules else 'number' if 'numeric' in rules else 'boolean' if 'boolean' in rules else 'array' if any(x=='array' or x.startswith('array:') for x in rules) else 'string'
 s={'type':typ,'x-validation':rules}
 if typ=='array':s['items']={'type':'string'}
 if typ=='object':s.update(properties={},required=[],additionalProperties=False)
 if 'nullable' in rules:s['type']=[typ,'null']
 if 'accepted' in rules:s={'enum':[True,1,'1','yes','on','true'],'x-validation':rules}
 if 'email' in rules:s['format']='email'
 if 'uuid' in rules:s['format']='uuid'
 if any(r.startswith('url:') or r=='url' for r in rules):s['format']='uri'
 if 'prohibited' in rules:return {'not':{}}
 for rule in rules:
  k,sep,v=rule.partition(':')
  if k=='in':s['enum']=[int(x) if typ=='integer' else x for x in v.split(',')]+([None] if 'nullable' in rules else [])
  if k in ['min','max','size','between']:
   vals=v.split(',')
   if not all(re.fullmatch(r'-?\d+(\.\d+)?',x) for x in vals):continue
   lo,hi=('minItems','maxItems') if typ=='array' else ('minimum','maximum') if typ in ['integer','number'] else ('minLength','maxLength')
   n=lambda x:float(x) if '.' in x else int(x)
   if k in ['min','size','between']:s[lo]=n(vals[0])
   if k in ['max','size','between']:s[hi]=n(vals[-1])
  if k=='digits':s['pattern']='^[0-9]{'+v+'}$'
  if k=='multiple_of':s['multipleOf']=int(v)
  if k=='date_format':
   if v=='Y-m-d':s['format']='date'
   elif v=='H:i':s['pattern']='^[0-2][0-9]:[0-5][0-9]$'
   elif 'TH:i' in v:s['pattern']='^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-2][0-9]:[0-5][0-9]$'
  if k=='distinct':s['x-distinct']=True
  if k in ['required_if','required_with','exists','unique','different','after','after_or_equal','timezone'] or rule=='@dynamic':s['description']='Zusätzliche Fachvalidierung: '+', '.join(rules)
 if any('password' in r for r in rules):s['writeOnly']=True
 return s

def schema(rules,op):
 if '@choices' in rules:return {'anyOf':[dict(schema(v,op),title=k) for k,v in rules['@choices'].items()]}
 if '@variants' in rules:return {'anyOf':[schema(v,op) for v in rules['@variants']]}
 rules=resolve(rules,op)
 s={'type':'object','properties':{},'required':[],'additionalProperties':True}
 for key,val in rules.items():
  if key=='@dynamic' or key.isdigit():continue
  if val=='@dynamic':
   if key=='password':val=('required' if op['method']=='POST' else 'nullable')+'|string|max:1024'
   else:raise ValueError((op['id'],key,val))
  if key=='platform_role_id' and op['action'].endswith('UserController@updateUser'):val='sometimes|integer'
  parts=key.split('.');node=s
  for segment in parts[:-1]:
   if segment=='*':
    node.setdefault('items',{})
    node['items']={'type':'object','properties':{},'required':[],'additionalProperties':True} if 'properties' not in node['items'] else node['items'];node=node['items']
   else:
    node.setdefault('properties',{}).setdefault(segment,{'type':'object','properties':{},'required':[]});node=node['properties'][segment]
  leaf=parts[-1];fs=field_schema(val)
  if leaf=='*':node['items']=fs
  else:
   node.setdefault('properties',{})[leaf]=fs
   vr=val if isinstance(val,list) else val.split('|')
   if any(x in vr for x in ['required','present','accepted']) and 'sometimes' not in vr:node.setdefault('required',[]).append(leaf)
   if 'confirmed' in vr:
    node['properties'][leaf+'_confirmation']={**fs,'description':'Muss mit '+leaf+' übereinstimmen.'};node.setdefault('required',[]).append(leaf+'_confirmation')
   if leaf in ['password','password_confirmation','api_key','webhook_secret','github_token']:fs['writeOnly']=True
 return s

def example(s):
 if 'anyOf' in s:return example(next((x for x in s['anyOf'] if x.get('type')!='null'),s['anyOf'][0]))
 if 'enum' in s:return s['enum'][0]
 t=s.get('type');t=t[0] if isinstance(t,list) else t
 if t=='object':return ({'platzhirsch-module-host':'0.1.3'} if s.get('minProperties') else {k:example(v) for k,v in s.get('properties',{}).items() if k in s.get('required',[])})
 if t=='array':return [example(s['items']) for _ in range(s.get('minItems',0))]
 if t in ['integer','number']:return max(1,s.get('minimum',0))
 if t=='boolean':return True
 if s.get('format')=='uuid':return '00000000-0000-4000-8000-000000000001'
 if s.get('format')=='email':return 'kontakt@example.test'
 if s.get('format')=='date':return '2026-10-01'
 if s.get('format')=='uri':return 'https://example.test'
 if 'pattern' in s:
  if '{6}' in s['pattern']:return '123456'
  if 'T' in s['pattern']:return '2026-10-01T18:00'
  if ':' in s['pattern']:return '18:00'
 return 'Beispiel'+('x'*max(0,s.get('minLength',0)-8))

empty={'type':'object','properties':{},'required':[],'additionalProperties':True}
count=0
for path in [root/'app/app/Api/platform.json',*sorted((root/'app/packages').glob('*/src/api.json'))]:
 manifest=json.loads(path.read_text())
 for op in manifest['operations']:
  if op['exposure']!='external':continue
  action=op['action'];short=action.split('\\')[-1];data=methods.get(action,{})
  raw=rules_for(action);merged={}
  for r in reversed(raw):
   if isinstance(r,dict):merged.update(r)
   elif r!='@dynamic':raise ValueError((op['id'],r))
  if short=='ExportController@direct':merged={'format':'required|in:csv,xlsx,pdf'}
  if short=='ExportController@start':merged={'format':'required|in:csv'}
  if short in ['RoleController@activate','RoleController@check']:merged={'version':'required|integer|min:1'}
  if short=='ReservationController@export':merged['date']='required|date_format:Y-m-d'
  if short=='UserController@updateUser':merged['platform_role_id']='sometimes|integer'
  if 'paginate(' in data.get('source','') or short in ['AutomationController@index','InvoiceController@index','InvoiceController@customer']:merged.setdefault('page','sometimes|integer|min:1')
  request=schema(merged,op)
  if action.startswith('App\\ModuleUpdates\\Controller@') and 'selection' in request.get('properties',{}):request['properties']['selection']={'type':'object','additionalProperties':{'type':'string','maxLength':30},'minProperties':1}
  query=copy.deepcopy(empty);body=copy.deepcopy(empty)
  if op['method']=='GET':query=request
  else:body=request
  params={}
  for name in re.findall(r'\{([^}]+)\}',op['uri']):
   params[name]={'type':'string','description':'Kennung im Pfad.'}
   if name=='resource':params[name]['enum']=['rooms','tables','hours','special-days']
   if name=='kind':params[name]['enum']=['room-closures','table-combinations']
  parameter_schema={'type':'object','properties':params,'required':list(params),'additionalProperties':False}
  responses_out={}
  if short.endswith('@export') or short=='ExportController@direct':
   types=['text/csv','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/pdf']
   if short=='ReservationController@export':types+=['text/html']
   responses_out['200']={'description':'Datei im angeforderten Format; PDF bis 500, direkte Tabellen bis 10.000 Datensätze.','content':{t:{'schema':{'type':'string','format':'binary'}} for t in types}}
  elif short in ['InvoiceController@pdf','InvoiceController@print','ExportController@download']:
   typ='application/pdf' if short.endswith('@pdf') else 'text/html' if short.endswith('@print') else 'text/csv'
   responses_out['200']={'description':'Privater Download','content':{typ:{'schema':{'type':'string','format':'binary'}}}}
  elif op['method']=='DELETE' and short!='InvoiceController@discard' or short in ['ReservationController@cancel','WidgetController@revoke']:
   responses_out['204']={'description':'Erfolgreich; kein Antwortkörper.'}
  else:
   response=platform.get(op['id'],responses.get(short))
   if response is None:raise ValueError('Missing reviewed response: '+op['id']+' '+short)
   codes=['200']
   if '201' in data.get('source','') or short in ['WidgetController@create','ReportController@save']:codes+=['201']
   if '202' in data.get('source',''):codes+=['202']
   for code in codes:responses_out[code]={'description':'Fachantwort; zusätzliche additive Felder sind möglich.','content':{'application/json':{'schema':response,'example':example(response)}}}
  op['contract']={'parameters':parameter_schema,'query':query,'body':body,'example':{'parameters':example(parameter_schema),'query':example(query),'body':example(body)},'responses':responses_out}
  # Fingerprint forces an explicit contract review whenever its controller is edited.
  if data.get('file'):op['contract']['source_sha256']=hashlib.sha256((root/data['file']).read_bytes().replace(b'\r\n',b'\n')).hexdigest()
  count+=1
 path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
print('Generated',count,'operation contracts')
