import unittest
from check import compatible,compare
class CompatibilityTest(unittest.TestCase):
 def test_input_required_type_enum_and_bounds_are_protected(self):
  self.assertTrue(compatible({'type':'string'},{'type':'integer'}))
  self.assertTrue(compatible({'type':'object','required':[]},{'type':'object','required':['password']}))
  self.assertTrue(compatible({'type':'integer','maximum':100},{'type':'integer','maximum':50}))
  self.assertTrue(compatible({'enum':['a','b']},{'enum':['a']}))
  self.assertFalse(compatible({'enum':['a']},{'enum':['a','b']}))
 def test_response_guarantees_are_preserved(self):
  self.assertTrue(compatible({'type':'object','required':['id']},{'type':'object','required':[]},response=True))
  self.assertTrue(compatible({'type':'string'},{'type':['string','null']},response=True))
  self.assertFalse(compatible({'type':'object','properties':{'id':{'type':'integer'}}},{'type':'object','properties':{'id':{'type':'integer'},'name':{'type':'string'}}},response=True))
 def test_removed_operations_and_auth_changes_fail(self):
  old={'test':{'method':'GET','scope':'test:read'}}
  self.assertTrue(compare(old,{}));self.assertTrue(compare(old,{'test':{'method':'GET','scope':'test:write'}}))
if __name__=='__main__':unittest.main()
