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
