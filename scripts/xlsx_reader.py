import zipfile, re, sys
import xml.etree.ElementTree as ET
NS={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main','r':'http://schemas.openxmlformats.org/officeDocument/2006/relationships'}
def col(ref):
    c=re.match(r'[A-Z]+',ref).group(0); n=0
    for ch in c: n=n*26+ord(ch)-64
    return n-1
def sheets(path):
    z=zipfile.ZipFile(path)
    ss=[]
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si',NS):
            ss.append(''.join(t.text or '' for t in si.iter('{%s}t'%NS['m'])))
    wb=ET.fromstring(z.read('xl/workbook.xml'))
    rels={r.get('Id'):r.get('Target') for r in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
    out={}
    for s in wb.find('m:sheets',NS):
        tgt=rels[s.get('{%s}id'%NS['r'])].lstrip('/')
        if not tgt.startswith('xl/'): tgt='xl/'+tgt
        rows=[]
        for row in ET.fromstring(z.read(tgt)).iter('{%s}row'%NS['m']):
            cells={}
            for c in row.findall('m:c',NS):
                v=c.find('m:v',NS); t=c.get('t')
                if t=='inlineStr': val=''.join(x.text or '' for x in c.iter('{%s}t'%NS['m']))
                elif v is None: continue
                elif t=='s': val=ss[int(v.text)]
                else: val=v.text
                cells[col(c.get('r'))]=val
            if cells: rows.append([cells.get(i,'') for i in range(max(cells)+1)])
        out[s.get('name')]=rows
    return out
if __name__=='__main__':
    import glob
    for f in sorted(glob.glob(sys.argv[1])):
        print('=====',f)
        for n,rows in sheets(f).items():
            print('--- sheet',n,len(rows),'rows')
            for r in rows[:12]: print(r)
