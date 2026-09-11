"""Extract reference controls for a reproducible, reviewable design checklist."""
import argparse, csv, hashlib, json
from html.parser import HTMLParser
from pathlib import Path
class Controls(HTMLParser):
    def __init__(self):
        super().__init__(); self.conditions=[]; self.active=None; self.rows=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='sc-if': self.conditions.append(a.get('value',''))
        if tag=='button': self.active={'line':self.getpos()[0],'conditions':' / '.join(self.conditions),'action':a.get('onclick',''),'text':''}
    def handle_data(self,data):
        if self.active is not None:self.active['text']+=' '+data.strip()
    def handle_endtag(self,tag):
        if tag=='sc-if' and self.conditions:self.conditions.pop()
        if tag=='button' and self.active is not None:
            self.active['text']=' '.join(self.active['text'].split());self.rows.append(self.active);self.active=None
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('reference');p.add_argument('output');args=p.parse_args()
    raw=Path(args.reference).read_bytes();parser=Controls();parser.feed(raw.decode('utf-8'))
    assessment=json.loads(Path(__file__).with_name('assessment.json').read_text())
    if hashlib.sha256(raw).hexdigest()!=assessment['reference_sha256']: raise ValueError('Andere Vorlage: Abgleich muss erneut geprüft werden.')
    if set(assessment['controls'])!={f'B{i:03}' for i in range(1,len(parser.rows)+1)}: raise ValueError('Button-Abgleich ist unvollständig.')
    with Path(args.output).open('w',encoding='utf-8',newline='') as f:
        writer=csv.writer(f,lineterminator='\n');writer.writerow(['ID','Vorlagenzeile','Bedingungen','Aktion','Beschriftung','Abnahme','Codeabgleich','Umsetzung','Restabweichung'])
        for i,row in enumerate(parser.rows,1):writer.writerow([f'B{i:03}',row['line'],row['conditions'],row['action'],row['text'],assessment['controls'][f'B{i:03}'].get('Abnahme','Visuelle Einzelabnahme offen'),*[assessment['controls'][f'B{i:03}'][k] for k in ['Codeabgleich','Umsetzung','Restabweichung']]])
    print(f'{len(parser.rows)} Buttons; SHA-256 {hashlib.sha256(raw).hexdigest()}')
