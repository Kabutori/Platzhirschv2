"""Extract reference controls for a reproducible, reviewable design checklist."""
import argparse, csv, hashlib
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
    with Path(args.output).open('w',encoding='utf-8',newline='') as f:
        writer=csv.writer(f);writer.writerow(['ID','Vorlagenzeile','Bedingungen','Aktion','Beschriftung','Abnahme'])
        for i,row in enumerate(parser.rows,1):writer.writerow([f'B{i:03}',row['line'],row['conditions'],row['action'],row['text'],'Einzelabnahme offen'])
    print(f'{len(parser.rows)} Buttons; SHA-256 {hashlib.sha256(raw).hexdigest()}')
