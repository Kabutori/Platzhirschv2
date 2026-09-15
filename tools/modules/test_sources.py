import importlib.util,json,subprocess,tempfile,unittest,hashlib
from pathlib import Path
spec=importlib.util.spec_from_file_location('sources',Path(__file__).with_name('sources.py'))
s=importlib.util.module_from_spec(spec);spec.loader.exec_module(s)
class SourcesTest(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
  self.root=Path(self.temp.name)/'application';self.repo=Path(self.temp.name)/'module';self.root.mkdir();self.repo.mkdir()
  self.previous=(s.ROOT,s.LOCK);s.ROOT=self.root;s.LOCK=self.root/'modules.lock.json';self.addCleanup(lambda:setattr(s,'ROOT',self.previous[0]));self.addCleanup(lambda:setattr(s,'LOCK',self.previous[1]))
  self.target=self.root/'widget-embed';(self.target/'src').mkdir(parents=True);(self.target/'src/widget.js').write_text('old\n');(self.target/'test-server.mjs').write_text('retained')
  package={'name':'widget','kind':'asset','version':'0.1.0','source':'.','target':'widget-embed','retained':['test-server.mjs']}
  lock={'repositories':[{'repository':'Kabutori/widget','commit':'a'*40,'packages':[{**package,'files':{'src/widget.js':hashlib.sha256(b'old\n').hexdigest()}}]}]}
  s.LOCK.write_text(json.dumps(lock))
  (self.repo/'src').mkdir();(self.repo/'src/widget.js').write_text('new\n');(self.repo/'module.json').write_text(json.dumps({'repository':'Kabutori/widget','packages':[package]}))
  self.git('init','-q');self.git('add','.');self.git('-c','user.name=Test','-c','user.email=test@example.test','commit','-qm','fixture');self.commit=self.git('rev-parse','HEAD').strip()
 def git(self,*args):return subprocess.check_output(['git',*args],cwd=self.repo,text=True)
 def test_import_pins_commit_preserves_application_files_and_verifies(self):
  s.import_checkout('widget',self.repo,self.commit)
  self.assertEqual((self.target/'src/widget.js').read_text(),'new\n');self.assertEqual((self.target/'test-server.mjs').read_text(),'retained')
  self.assertEqual(json.loads(s.LOCK.read_text())['repositories'][0]['commit'],self.commit)
  self.assertEqual(s.verify(self.root),1)
 def test_local_changes_and_wrong_commit_are_not_overwritten(self):
  with self.assertRaises(ValueError):s.import_checkout('widget',self.repo,'b'*40)
  (self.target/'src/widget.js').write_text('local change')
  with self.assertRaises(ValueError):s.import_checkout('widget',self.repo,self.commit)
  self.assertEqual((self.target/'src/widget.js').read_text(),'local change')
 def test_windows_line_endings_do_not_change_canonical_source_hash(self):
  (self.target/'src/widget.js').write_bytes(b'old\r\n')
  self.assertEqual(s.verify(self.root),1)
if __name__=='__main__':unittest.main()
