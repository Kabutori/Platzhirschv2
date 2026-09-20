#!/usr/bin/env python3
"""Validate module schemas and reject breaking changes within external API v1.
Compare to --base <git commit> in CI. An incompatible contract needs a parallel v2 route,
not a version string change or a replacement baseline.
"""
import argparse,hashlib,json,re,subprocess,sys
from pathlib import Path
from jsonschema import Draft202012Validator
ROOT=Path(__file__).resolve().parents[2]

def compatible(old,new,path='',response=False):
 errors=[]
 if old==new:return errors
 def fail(msg):errors.append(path+': '+msg)
 if isinstance(old,bool) or isinstance(new,bool):
  if old!=new:fail('boolean schema changed; manual versioned contract required')
  return errors
 ot=old.get('type');nt=new.get('type')
 if ot is not None:
  a=set(ot if isinstance(ot,list) else [ot]);b=set(nt if isinstance(nt,list) else [nt]) if nt else set()
  if not ((b<=a and b) if response else a<=b):fail('incompatible type change')
 if 'enum' in old or 'enum' in new:
  a={json.dumps(v,sort_keys=True) for v in old.get('enum',[])};b={json.dumps(v,sort_keys=True) for v in new.get('enum',[])}
  if response and 'enum' in old and ('enum' not in new or not b<=a):fail('response enum widened')
  if not response and 'enum' in new and ('enum' not in old or not a<=b):fail('input enum restricted')
 a=set(old.get('required',[]));b=set(new.get('required',[]))
 if response and not a<=b:fail('required response field removed')
 if not response and not b<=a:fail('new required input field')
 for key,value in old.get('properties',{}).items():
  if key not in new.get('properties',{}):fail('documented field removed: '+key)
  else:errors+=compatible(value,new['properties'][key],path+'.'+key,response)
 if 'items' in old:
  if 'items' not in new:fail('array item contract removed')
  else:errors+=compatible(old['items'],new['items'],path+'[]',response)
 for key in ['minimum','minLength','minItems','minProperties','exclusiveMinimum']:
  if not response and key in new and (key not in old or new[key]>old[key]):fail(key+' tightened')
  if response and key in old and (key not in new or new[key]<old[key]):fail(key+' widened')
 for key in ['maximum','maxLength','maxItems','maxProperties','exclusiveMaximum']:
  if not response and key in new and (key not in old or new[key]<old[key]):fail(key+' tightened')
  if response and key in old and (key not in new or new[key]>old[key]):fail(key+' widened')
 for key in ['pattern','format','multipleOf','not','anyOf','oneOf','allOf','dependentRequired','if','then','else','const','x-validation']:
  if old.get(key)!=new.get(key):fail(key+' changed; explicit versioned contract required')
 if not response and old.get('additionalProperties',True)!=False and new.get('additionalProperties',True)==False:fail('extension input fields prohibited')
 if response and old.get('additionalProperties',True)==False and new.get('additionalProperties',True)!=False:fail('closed output became extensible')
 return errors

def operations(manifest):return {o['id']:o for o in manifest['operations'] if o['exposure']=='external'}
def compare(before,after):
 errors=[]
 for id,old in before.items():
  new=after.get(id)
  if not new:errors.append(id+': operation removed');continue
  for key in ['method','uri','scope','context','confirmation','mcp','admin_only']:
   if old.get(key)!=new.get(key):errors.append(id+': '+key+' changed')
  if 'contract' not in old:continue # First field-level contract release establishes the baseline.
  if 'contract' not in new:errors.append(id+': contract removed');continue
  for section in ['parameters','query','body']:errors+=compatible(old['contract'][section],new['contract'][section],id+'.'+section)
  for code,res in old['contract']['responses'].items():
   current=new['contract']['responses'].get(code)
   if current is None:errors.append(id+': response removed '+code);continue
   for media,body in res.get('content',{}).items():
    if media not in current.get('content',{}):errors.append(id+': media type removed '+media);continue
    errors+=compatible(body['schema'],current['content'][media]['schema'],id+'.response.'+code,True)
 return errors

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--base');parser.add_argument('--observations');args=parser.parse_args()
 files=[ROOT/'app/app/Api/platform.json',*sorted((ROOT/'app/packages').glob('*/src/api.json'))]
 after={};before={};errors=[];classes={}
 for directory in ['app/app','app/packages']:
  for path in (ROOT/directory).rglob('*.php'):
   text=path.read_text();namespace=re.search(r'namespace\s+([^;]+);',text);cls=re.search(r'\bclass\s+(\w+)',text)
   if namespace and cls:classes[namespace[1].strip()+'\\'+cls[1]]=path
 for path in files:
  manifest=json.loads(path.read_text());ops=operations(manifest)
  for id,o in ops.items():
   if id in after:errors.append('Duplicate operation: '+id)
   c=o.get('contract');
   if not c:errors.append('Missing contract: '+id);continue
   for name in ['parameters','query','body']:
    Draft202012Validator.check_schema(c[name]);Draft202012Validator(c[name]).validate(c['example'][name])
   for res in c['responses'].values():
    for body in res.get('content',{}).values():
     Draft202012Validator.check_schema(body['schema'])
     if 'example' in body:Draft202012Validator(body['schema']).validate(body['example'])
   if o['action']!='Closure':
    source=classes.get(o['action'].split('@')[0]);actual=hashlib.sha256(source.read_bytes().replace(b'\r\n',b'\n')).hexdigest() if source else None
    if actual!=c.get('source_sha256'):errors.append(id+': controller changed; review and regenerate contract')
  after.update(ops)
  if args.base and not re.fullmatch('0+',args.base):
   old=subprocess.run(['git','show',args.base+':'+path.relative_to(ROOT).as_posix()],cwd=ROOT,capture_output=True,text=True)
   if old.returncode==0:before.update(operations(json.loads(old.stdout)))
 if args.base:
  # A deleted manifest must not silently disappear from the comparison.
  names=subprocess.check_output(['git','ls-tree','-r','--name-only',args.base],cwd=ROOT,text=True).splitlines() if not re.fullmatch('0+',args.base) else []
  for name in names:
   if re.fullmatch(r'app/packages/[^/]+/src/api.json|app/app/Api/platform.json',name):
    before.update(operations(json.loads(subprocess.check_output(['git','show',args.base+':'+name],cwd=ROOT,text=True))))
 errors+=compare(before,after)
 if args.observations:
  observations=[json.loads(line) for line in Path(args.observations).read_text().splitlines() if line]
  if not observations:errors.append('No live API observations produced')
  for row in observations:
   if row['operation']=='@openapi':
    for path,verbs in row['body']['paths'].items():
     for op in verbs.values():
      for parameter in op['parameters']:Draft202012Validator.check_schema(parameter['schema'])
      for body in op.get('requestBody',{}).get('content',{}).values():
       Draft202012Validator.check_schema(body['schema'])
       if 'example' in body:Draft202012Validator(body['schema']).validate(body['example'])
      for response in op['responses'].values():
       for body in response.get('content',{}).values():
        Draft202012Validator.check_schema(body['schema'])
        if 'example' in body:Draft202012Validator(body['schema']).validate(body['example'])
    continue
   c=after[row['operation']]['contract'];res=c['responses'].get(row['status'])
   if not res or 'application/json' not in res.get('content',{}):errors.append(row['operation']+': undocumented JSON response '+row['status']);continue
   for err in list(Draft202012Validator(res['content']['application/json']['schema']).iter_errors(row['body']))[:5]:errors.append(row['operation']+': '+'.'.join(map(str,err.path))+' '+err.message)
  print(str(len(observations))+' live API responses checked against contracts.')
 if errors:print('\n'.join(errors),file=sys.stderr);return 1
 print(f'{len(after)} operation contracts valid; {len(before)} previous operations checked.')
 return 0
if __name__=='__main__':sys.exit(main())
