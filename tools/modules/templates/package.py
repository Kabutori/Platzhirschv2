"""Build the packages declared in module.json. Run in a committed module checkout."""
from pathlib import Path
import json,subprocess,zipfile,tarfile,gzip,io,hashlib,re
root=Path(__file__).resolve().parents[1]
meta=json.loads((root/'module.json').read_text())
paths=[Path(x) for x in subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0') if x]
output=root/'dist';output.mkdir(exist_ok=False)
records=[]
for package in meta['packages']:
 source=root/package['source'];kind=package['kind']
 manifest='composer.json' if kind=='php' else 'package.json'
 data=json.loads((source/manifest).read_text()) if (source/manifest).exists() else package
 version=data['version']
 if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)',version):raise ValueError('Fixed semantic version required')
 if version!=package['version']:raise ValueError('module.json and package version differ')
 allowed=['src',manifest] if kind=='php' else data.get('files',['src','build.mjs'])+[manifest]
 entries=[]
 for path in paths:
  full=root/path
  if not full.is_relative_to(source):continue
  rel=full.relative_to(source)
  if not any(rel==Path(a) or rel.is_relative_to(a) for a in allowed):continue
  if full.is_symlink() or any(p.startswith('.') for p in rel.parts) or rel.suffix in ('.key','.pem','.pfx'):raise ValueError('Disallowed package file')
  entries.append((rel.as_posix(),full.read_bytes()))
 blob=io.BytesIO()
 if kind=='php':
  with zipfile.ZipFile(blob,'w',compression=zipfile.ZIP_DEFLATED) as z:
   for name,content in sorted(entries):
    info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,content)
 else:
  with gzip.GzipFile(fileobj=blob,mode='wb',mtime=0,filename='') as gz:
   with tarfile.open(fileobj=gz,mode='w') as t:
    for name,content in sorted(entries):
     info=tarfile.TarInfo('package/'+name);info.size=len(content);info.mode=0o644;t.addfile(info,io.BytesIO(content))
 filename=data['name'].replace('@','').replace('/','-')+'-'+version+('.zip' if kind=='php' else '.tgz')
 content=blob.getvalue();(output/filename).write_bytes(content)
 records.append({'name':data['name'],'version':version,'file':filename,'sha256':hashlib.sha256(content).hexdigest()})
(output/'SHA256SUMS.txt').write_text(''.join(r['sha256']+'  '+r['file']+'\n' for r in records))
(output/'packages.json').write_text(json.dumps({'sourceCommit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'packages':records},indent=2)+'\n')
print(json.dumps(records,indent=2))
