import unittest,tempfile,io,zipfile,tarfile,hashlib,json
from pathlib import Path
from registry import safe_extract,seed,ROOT
class RegistryTests(unittest.TestCase):
 def test_seed_contains_all_repositories_and_verified_archives(self):
  with tempfile.TemporaryDirectory() as d:
   out=Path(d);index=seed(ROOT,out);self.assertEqual(18,len(index['repositories']))
   for repo in index['repositories'].values():
    for p in repo['versions'][repo['installed']]['packages']:
     self.assertEqual(p['sha256'],hashlib.sha256((out/p['file']).read_bytes()).hexdigest())
 def test_zip_traversal_rejected_before_any_write(self):
  data=io.BytesIO()
  with zipfile.ZipFile(data,'w') as z:z.writestr('safe.txt','ok');z.writestr('../outside','bad')
  with tempfile.TemporaryDirectory() as d:
   with self.assertRaises(ValueError):safe_extract(data.getvalue(),'php',Path(d))
   self.assertEqual([],list(Path(d).iterdir()))
 def test_tar_links_rejected(self):
  data=io.BytesIO()
  with tarfile.open(fileobj=data,mode='w:gz') as t:
   x=tarfile.TarInfo('package/link');x.type=tarfile.SYMTYPE;x.linkname='/etc/passwd';t.addfile(x)
  with tempfile.TemporaryDirectory() as d:
   with self.assertRaises(ValueError):safe_extract(data.getvalue(),'npm',Path(d))

class CompositionTests(unittest.TestCase):
 def test_changed_release_uses_immutable_archives_and_rejects_retagging(self):
  from unittest.mock import patch
  import registry
  with tempfile.TemporaryDirectory() as d:
   root=Path(d);target=root/'app/packages/test';target.mkdir(parents=True);(target/'composer.json').write_text('{}')
   (root/'admin-ui').mkdir();(root/'app/composer.json').write_text(json.dumps({'require':{'platzhirsch/test':'0.1.0'}}));(root/'admin-ui/package.json').write_text(json.dumps({'dependencies':{}}))
   p={'name':'platzhirsch/test','kind':'php','source':'.','target':'app/packages/test','version':'0.1.0','manifest':{'name':'platzhirsch/test','version':'0.1.0'}}
   (root/'modules.lock.json').write_text(json.dumps({'repositories':[{'repository':'Kabutori/platzhirsch-test','commit':'a'*40,'packages':[{**p,'files':{}}]}]}))
   data=io.BytesIO()
   with zipfile.ZipFile(data,'w') as z:z.writestr('composer.json',json.dumps({'name':'platzhirsch/test','version':'0.2.0'}))
   blob=data.getvalue();hash=hashlib.sha256(blob).hexdigest()
   meta={'sourceCommit':'b'*40,'packages':[{'name':'platzhirsch/test','version':'0.2.0','file':'module.zip','sha256':hash}]}
   def api(path,token,binary=False):
    if '/releases/tags/' in path:return {'draft':False,'prerelease':False,'assets':[{'name':'packages.json','id':1},{'name':'module.zip','id':2}]}
    return json.dumps(meta).encode() if path.endswith('/1') else blob
   baseline={'repositories':{'platzhirsch-test':{'installed':'0.1.0','versions':{'0.1.0':{'commit':'a'*40,'packages':[p]}}}}}
   out=root/'artifacts';out.mkdir()
   with patch.object(registry,'seed',return_value=baseline),patch.object(registry,'github',side_effect=api),patch.dict('os.environ',{'MODULE_REPOSITORY_TOKEN':'test'}):
    index=registry.compose(root,{'platzhirsch-test':{'version':'0.2.0','commit':'b'*40}},out)
    self.assertEqual('0.2.0',index['repositories']['platzhirsch-test']['installed'])
    self.assertEqual('0.2.0',json.loads((root/'app/composer.json').read_text())['require']['platzhirsch/test'])
    self.assertEqual('b'*40,json.loads((root/'modules.lock.json').read_text())['repositories'][0]['commit'])
    with self.assertRaisesRegex(ValueError,'Release changed'):
     registry.compose(root,{'platzhirsch-test':{'version':'0.2.0','commit':'c'*40}},out)
