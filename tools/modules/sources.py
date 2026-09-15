"""Verify or import the pinned module repository sources used by application builds.

An authenticated local Git checkout is required for import. No credentials are stored.
"""
from pathlib import Path
import argparse, hashlib, json, subprocess, shutil
ROOT=Path(__file__).resolve().parents[2]
LOCK=ROOT/'modules.lock.json'
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest()
def tracked(root):return [Path(p) for p in subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0') if p]
def verify(root=ROOT):
 lock=json.loads((root/'modules.lock.json').read_text())
 errors=[]
 for repo in lock['repositories']:
  for package in repo['packages']:
   target=root/package['target']
   actual={p.relative_to(target).as_posix():digest(p) for p in target.rglob('*') if p.is_file() and 'node_modules' not in p.parts and '__pycache__' not in p.parts and p.relative_to(target).as_posix() not in package.get('retained',[])}
   if actual!=package['files']:errors.append(package['target'])
 if errors:raise ValueError('Source copies differ from pinned repositories: '+', '.join(errors))
 return len(lock['repositories'])
def import_checkout(name, checkout, commit):
 lock=json.loads(LOCK.read_text());entry=next((r for r in lock['repositories'] if r['repository']==name or r['repository'].split('/')[-1]==name),None)
 if entry is None:raise ValueError('Unknown repository')
 actual=subprocess.check_output(['git','rev-parse','HEAD'],cwd=checkout,text=True).strip()
 if actual!=commit or len(commit)!=40:raise ValueError('Check out the exact requested full commit first')
 if subprocess.check_output(['git','status','--porcelain'],cwd=checkout).strip():raise ValueError('Module checkout must be clean')
 meta=json.loads((checkout/'module.json').read_text())
 if meta['repository']!=entry['repository']:raise ValueError('Wrong repository metadata')
 expected={(p['source'],p['target']) for p in entry['packages']}
 if {(p['source'],p['target']) for p in meta['packages']}!=expected:raise ValueError('Package mapping changed; review separately')
 files=tracked(checkout);prepared=[]
 for p in meta['packages']:
  source=checkout/p['source'];target=ROOT/p['target'];new={}
  # Import only the existing package boundary, including newly added src files.
  manifest='composer.json' if p['kind']=='php' else 'package.json'
  data=json.loads((source/manifest).read_text()) if (source/manifest).exists() else p
  allowed=['src',manifest] if p['kind']=='php' else data.get('files',['src','build.mjs'])+[manifest]
  for f in files:
   full=checkout/f
   if not full.is_relative_to(source):continue
   rel=full.relative_to(source)
   if not any(rel==Path(a) or rel.is_relative_to(a) for a in allowed):continue
   if full.is_symlink() or '..' in rel.parts or any(x.startswith('.') for x in rel.parts):raise ValueError('Unsupported package file')
   new[rel.as_posix()]=full.read_bytes()
  if not new:raise ValueError('Empty package')
  prepared.append((p,target,new))
 # Refuse to overwrite local package edits before replacing any directory.
 verify()
 for p,target,new in prepared:
  old=next(x for x in entry['packages'] if x['target']==p['target'])
  for oldfile in old['files']:
   (target/oldfile).unlink(missing_ok=True)
  target.mkdir(parents=True,exist_ok=True)
  for name,content in new.items():
   f=target/name;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(content)
  p['files']={name:hashlib.sha256(data).hexdigest() for name,data in sorted(new.items())}
 entry['commit']=commit;entry['packages']=[p for p,_,_ in prepared]
 LOCK.write_text(json.dumps(lock,indent=2)+'\n')
 print('Imported '+entry['repository']+' at '+commit+'. Update application version pins and lockfiles, then run all checks.')
if __name__=='__main__':
 parser=argparse.ArgumentParser(description=__doc__);sub=parser.add_subparsers(dest='command',required=True)
 sub.add_parser('verify')
 p=sub.add_parser('import');p.add_argument('repository');p.add_argument('--checkout',type=Path,required=True);p.add_argument('--commit',required=True)
 args=parser.parse_args()
 try:
  if args.command=='verify':print(f'{verify()} pinned module repositories verified')
  else:import_checkout(args.repository,args.checkout.resolve(),args.commit)
 except (ValueError,OSError,subprocess.CalledProcessError) as e:parser.exit(1,str(e)+'\n')
