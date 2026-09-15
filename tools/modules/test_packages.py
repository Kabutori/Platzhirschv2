import importlib.util
import io
import json
import subprocess
import tarfile
import tempfile
import unittest
import zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location('packages', Path(__file__).with_name('packages.py'))
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)

class PackagesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        self.manifest('app/packages/reporting/composer.json', {'name':'platzhirsch/reporting','version':'0.2.0','require':{}})
        self.manifest('admin-ui/packages/reporting/package.json', {'name':'@platzhirsch/reporting-ui','version':'0.2.0','files':['src']})
        for folder in ['app/packages/reporting','admin-ui/packages/reporting']:
            path=self.root/folder/'src/test.txt';path.parent.mkdir();path.write_text('source')
        subprocess.run(['git','add','.'],cwd=self.root,check=True)
        subprocess.run(['git','-c','user.name=Test','-c','user.email=test@example.test','commit','-qm','fixture'],cwd=self.root,check=True)
    def manifest(self,path,data):
        f=self.root/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_text(json.dumps(data))
    def test_archives_are_reproducible_and_exclude_untracked_credentials(self):
        (self.root/'app/packages/reporting/src/private.key').write_text('secret')
        a=p.build(self.root,self.root/'out-a','reporting')
        b=p.build(self.root,self.root/'out-b','reporting')
        self.assertEqual(a,b)
        for row in a:
            data=(self.root/'out-a'/row['file']).read_bytes()
            if row['kind']=='php':
                with zipfile.ZipFile(io.BytesIO(data)) as z:
                    self.assertEqual(z.namelist(),['composer.json','src/test.txt'])
            else:
                with tarfile.open(fileobj=io.BytesIO(data),mode='r:gz') as t:
                    self.assertEqual(t.getnames(),['package/package.json','package/src/test.txt'])
        with self.assertRaises(FileExistsError):p.build(self.root,self.root/'out-a')
    def test_version_drift_is_rejected_before_output(self):
        self.manifest('admin-ui/packages/reporting/package.json',{'name':'@platzhirsch/reporting-ui','version':'0.3.0'})
        with self.assertRaisesRegex(ValueError,'versions differ'):p.build(self.root,self.root/'out')
        self.assertFalse((self.root/'out').exists())
    def test_missing_or_wrong_dependency_rejects_composition(self):
        self.manifest('app/packages/reporting/composer.json',{'name':'platzhirsch/reporting','version':'0.2.0','require':{'platzhirsch/billing':'0.1.0'}})
        with self.assertRaisesRegex(ValueError,'no matching package'):p.catalog(self.root)
    def test_unknown_module_rejected(self):
        with self.assertRaisesRegex(ValueError,'Unknown module'):p.build(self.root,self.root/'out','../reporting')

if __name__=='__main__':unittest.main()
