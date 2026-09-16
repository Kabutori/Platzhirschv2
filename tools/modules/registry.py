"""Private Composer/npm registry seed and verified module composition builder."""
import argparse, base64, hashlib, json, os, re, shutil, tarfile, zipfile, io
from pathlib import Path
from urllib.request import Request, urlopen, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from urllib.parse import unquote, urlparse
import packages
ROOT=Path(__file__).resolve().parents[2]
VERSION=re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z')
def write(path,value):
 path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(value,indent=2)+'\n',encoding='utf-8')
def seed(root,out):
 lock=packages.read(root/'modules.lock.json');paths=packages.tracked(root);rows={r['directory']:r for r in packages.catalog(root)};index={'repositories':{}}
 out.mkdir(parents=True,exist_ok=True)
 for repo in lock['repositories']:
  name=repo['repository'].split('/')[-1];records=[]
  for p in repo['packages']:
   manifest=root/p['target']/('composer.json' if p['kind']=='php' else 'package.json')
   data=packages.read(manifest);row=rows.get(p['target'],{'kind':p['kind'],'directory':p['target'],'manifest':data})
   blob=packages.archive(row,packages.package_files(root,row,paths));filename=data['name'].replace('@','').replace('/','-')+'-'+data['version']+('.zip' if p['kind']=='php' else '.tgz');(out/filename).write_bytes(blob)
   records.append({**{k:p[k] for k in ('name','kind','source','target')},'version':data['version'],'manifest':data,'file':filename,'sha256':hashlib.sha256(blob).hexdigest(),'sha1':hashlib.sha1(blob).hexdigest()})
  versions={p['version'] for p in records}
  if len(versions)!=1:raise ValueError('Repository package versions differ')
  v=versions.pop();index['repositories'][name]={'installed':v,'versions':{v:{'commit':repo['commit'],'packages':records}}}
 write(out/'index.json',index);return index
class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
def github(path,token,binary=False):
 req=Request('https://api.github.com/repos/'+path,headers={'Authorization':'Bearer '+token,'Accept':'application/octet-stream' if binary else 'application/vnd.github+json','User-Agent':'Platzhirsch-Module-Builder'})
 try:r=build_opener(NoRedirect).open(req,timeout=60)
 except HTTPError as e:
  if not binary or e.code not in (301,302,307):raise
  url=e.headers['Location'];host=urlparse(url)
  if host.scheme!='https' or host.hostname not in ('release-assets.githubusercontent.com','objects.githubusercontent.com'):raise ValueError('Unexpected artifact host')
  r=build_opener(NoRedirect).open(Request(url,headers={'User-Agent':'Platzhirsch-Module-Builder'}),timeout=60)
 with r:
  content=r.read(20*1024*1024+1)
  if len(content)>20*1024*1024:raise ValueError('Artifact too large')
 return content if binary else json.loads(content)
def safe_extract(blob,kind,destination):
 files=[];total=0
 if kind=='php':
  with zipfile.ZipFile(io.BytesIO(blob)) as z:
   for x in z.infolist():
    if x.is_dir():continue
    if (x.external_attr>>16)&0o170000==0o120000:raise ValueError('Symlink rejected')
    total+=x.file_size
    if total>100*1024*1024:raise ValueError('Expanded archive too large')
    files.append((x.filename,z.read(x)))
 else:
  with tarfile.open(fileobj=io.BytesIO(blob),mode='r:gz') as t:
   for x in t:
    if not x.isfile() or not x.name.startswith('package/'):raise ValueError('Unexpected archive member')
    total+=x.size
    if total>100*1024*1024:raise ValueError('Expanded archive too large')
    files.append((x.name[8:],t.extractfile(x).read()))
 for name,data in files:
  if '\\' in name or ':' in name or name.startswith('/') or any(x in ('','..','.') for x in name.split('/')):raise ValueError('Unsafe archive path')
 for name,data in files:
  target=destination/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data)
 return files
def compose(root,selection,out):
 index=seed(root,out);known=index['repositories'];token=os.environ.get('MODULE_REPOSITORY_TOKEN','')
 if set(selection)!=set(known):raise ValueError('Select all known repositories exactly once')
 lock=packages.read(root/'modules.lock.json')
 for repo,choice in selection.items():
  if set(choice)!= {'version','commit'} or not VERSION.fullmatch(choice['version']) or not re.fullmatch('[a-f0-9]{40}',choice['commit']):raise ValueError('Invalid selection')
  baseline=known[repo];v=choice['version'];current=baseline['versions'].get(v)
  if current and current['commit']==choice['commit']:continue
  if not token:raise ValueError('MODULE_REPOSITORY_TOKEN required for private releases')
  release=github('Kabutori/'+repo+'/releases/tags/v'+v,token)
  if release['draft'] or release['prerelease']:raise ValueError('Stable module release required')
  assets={x['name']:x for x in release['assets']}
  def asset(name):return github('Kabutori/'+repo+'/releases/assets/'+str(assets[name]['id']),token,True)
  meta=json.loads(asset('packages.json'))
  if meta['sourceCommit']!=choice['commit']:raise ValueError('Release changed after approval')
  expected=baseline['versions'][baseline['installed']]['packages'];new=[]
  if {p['name'] for p in meta['packages']}!={p['name'] for p in expected}:raise ValueError('Package set changed')
  for p in expected:
   r=next(x for x in meta['packages'] if x['name']==p['name']);blob=asset(r['file'])
   if r['version']!=v or hashlib.sha256(blob).hexdigest()!=r['sha256']:raise ValueError('Package hash/version mismatch')
   target=root/p['target'];retained=target/'test-server.mjs';retained_data=retained.read_bytes() if p['target']=='widget-embed' and retained.exists() else None
   shutil.rmtree(target);files=safe_extract(blob,p['kind'],target)
   if retained_data is not None:retained.write_bytes(retained_data)
   manifest=packages.read(target/('composer.json' if p['kind']=='php' else 'package.json'))
   if manifest['name']!=p['name'] or manifest['version']!=v:raise ValueError('Archive manifest mismatch')
   filename=p['name'].replace('@','').replace('/','-')+'-'+v+('.zip' if p['kind']=='php' else '.tgz');(out/filename).write_bytes(blob)
   new.append({**p,'version':v,'manifest':manifest,'file':filename,'sha256':r['sha256'],'sha1':hashlib.sha1(blob).hexdigest()})
   pinned=next(x for x in lock['repositories'] if x['repository'].endswith('/'+repo));pinned['commit']=choice['commit'];lp=next(x for x in pinned['packages'] if x['name']==p['name']);lp['version']=v;lp['files']={name:hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest() for name,data in files}
  baseline['installed']=v;baseline['versions']={v:{'commit':choice['commit'],'packages':new}}
 all_packages={p['name']:p for r in known.values() for p in r['versions'][r['installed']]['packages']}
 for p in all_packages.values():
  m=p['manifest'];deps=m.get('require',{}) if p['kind']=='php' else {**m.get('dependencies',{}),**m.get('peerDependencies',{})}
  for name,v in deps.items():
   if name.startswith(('platzhirsch/','@platzhirsch/')) and (name not in all_packages or all_packages[name]['version']!=v):raise ValueError(p['name']+' incompatible with '+name)
 for folder,key in [('app','require'),('admin-ui','dependencies')]:
  path=root/folder/('composer.json' if folder=='app' else 'package.json');data=packages.read(path)
  for name in data[key]:
   if name in all_packages:data[key][name]=all_packages[name]['version']
  if folder=='app':data['repositories']=[{'type':'composer','url':'http://127.0.0.1:18761/composer/packages.json'}];data.setdefault('config',{})['secure-http']=False
  else:data.pop('workspaces',None);(root/folder/'.npmrc').write_text('@platzhirsch:registry=http://127.0.0.1:18761/npm/\n')
  write(path,data)
 write(root/'modules.lock.json',lock);write(out/'index.json',index);write(root/'module-composition.json',{'request_id':os.environ.get('MODULE_REQUEST_ID',''),'selection':selection})
 return index
class Handler(SimpleHTTPRequestHandler):
 def do_GET(self):
  path=unquote(self.path.split('?')[0]);index=packages.read(Path(self.directory)/'index.json');rows=[p for r in index['repositories'].values() for v in r['versions'].values() for p in v['packages']];base='http://127.0.0.1:18761'
  if path=='/composer/packages.json':
   data={'packages':{}}
   for p in rows:
    if p['kind']=='php':data['packages'].setdefault(p['name'],{})[p['version']]={**p['manifest'],'dist':{'type':'zip','url':base+'/'+p['file'],'shasum':p['sha1']}}
  elif path.startswith('/npm/'):
   name=path[5:];ps=[p for p in rows if p['kind']=='npm' and p['name']==name]
   if not ps:self.send_error(404);return
   data={'name':name,'versions':{p['version']:{**p['manifest'],'dist':{'tarball':base+'/'+p['file'],'shasum':p['sha1']}} for p in ps},'dist-tags':{'latest':ps[-1]['version']}}
  elif path[1:] in {p['file'] for p in rows}:return super().do_GET()
  else:self.send_error(404);return
  blob=json.dumps(data).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(blob)));self.end_headers();self.wfile.write(blob)
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('action',choices=['seed','compose','serve','run']);p.add_argument('--output',type=Path,default=ROOT/'app/resources/module-registry');p.add_argument('--command',nargs=argparse.REMAINDER);a=p.parse_args()
 if a.action=='seed':seed(ROOT,a.output)
 elif a.action=='compose':compose(ROOT,json.loads(os.environ['MODULE_COMPOSITION']),a.output)
 else:
  from functools import partial
  server=ThreadingHTTPServer(('127.0.0.1',18761),partial(Handler,directory=str(a.output.resolve())))
  if a.action=='serve':server.serve_forever()
  else:
   import subprocess,threading
   if not a.command:p.error('--command required')
   thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
   try:result=subprocess.run(a.command,shell=os.name=='nt');code=result.returncode
   finally:server.shutdown();server.server_close();thread.join()
   raise SystemExit(code)
