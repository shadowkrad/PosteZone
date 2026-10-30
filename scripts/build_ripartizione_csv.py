#!/usr/bin/env python3
"""
Converte i file "RipartizioneUfficio ... APP.xlsx" nel CSV per l'importazione dal pannello admin
(Importa -> "Sostituisci le regole delle vie presenti nel file").

Uso:
    python scripts/build_ripartizione_csv.py "RipartizioneUfficio*.xlsx" [--db data/postezone.db] [--out import]

Per ogni riga del file Excel:
  - il nome della via e' DUG + Complemento + Denominazione (es. CORSO + ITALIA);
  - se la via esiste gia' nello stradario del database (stesse parole, in qualsiasi ordine, oppure stesse
    parole significative con un solo candidato) si usa il nome del database, altrimenti la via e' nuova;
  - la zona e' "Numero PTL" senza spazi ("25 - A" -> "25A");
  - "Range Civici" diventa una regola: TUTTI/SNC -> TUTTI, "5; 123" -> DISPARI_INTERVALLO 5-123,
    "4; 58" -> PARI_INTERVALLO 4-58, "74" -> INTERVALLO 74-74. Le parti dopo il civico (72/C, 47/1) vengono ignorate.

Output (cartella --out): ripartizione.csv e ripartizione_report.txt (da controllare prima di importare).
"""
import argparse
import csv
import difflib
import glob
import os
import re
import sqlite3
import unicodedata
import sys
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from xlsx_reader import sheets  # noqa: E402

STOP = {'E', 'DEGLI', 'DEL', 'DELLA', 'DELLE', 'DEI', 'DI', 'DA', 'IL', 'LA', 'LE', 'L', 'D', 'DELL', 'ALLA', 'AL', 'ALL',
        'VIA', 'VICOLO', 'PIAZZA', 'VIALE', 'CORSO', 'STRADA', 'LOCALITA', 'LARGO'}

NUM = r'(\d+)\s*(?:/\s*[A-Za-z0-9]+(?:/[A-Za-z0-9]+)?)?'
RE_RANGE = re.compile(NUM + r'\s*;\s*' + NUM)
RE_SINGLE = re.compile(NUM)


def words(text):
    # Senza accenti (LOCALITÀ = LOCALITA') e con l'apostrofo trattato come spazio
    text = unicodedata.normalize('NFKD', text.upper())
    text = ''.join(c for c in text if not unicodedata.combining(c))
    text = text.replace("'", ' ').replace('’', ' ')
    return re.sub(r'[^A-Z0-9\s]', ' ', text).split()


def parse_range(value):
    """Ritorna (tipo, da, a) oppure None se il formato non e' riconosciuto."""
    v = value.strip().upper()
    if v in ('TUTTI', 'SNC', ''):
        return ('TUTTI', '', '')
    m = RE_RANGE.fullmatch(v)
    if m:
        da, a = int(m.group(1)), int(m.group(2))
        if da > a:
            da, a = a, da
        if da % 2 == a % 2:
            return ('PARI_INTERVALLO' if da % 2 == 0 else 'DISPARI_INTERVALLO', da, a)
        return ('INTERVALLO', da, a)
    m = RE_SINGLE.fullmatch(v)
    if m:
        n = int(m.group(1))
        return ('INTERVALLO', n, n)
    return None


class Stradario:
    """Indice delle vie del database per comune, per trovare il nome canonico."""

    def __init__(self, db):
        self.by_loc = defaultdict(list)
        self.zone_assegnate = defaultdict(int)
        for via, loc, zona in db.execute('SELECT via, localita, zona_sigla FROM smistamento_vie'):
            self.by_loc[loc].append(via)
            if zona != 'DA ASSEGNARE':
                self.zone_assegnate[(via, loc)] += 1
        self.cache = {}

    def vias(self, loc):
        return sorted(set(self.by_loc[loc]))

    def find(self, loc, name):
        key = (loc, name)
        if key in self.cache:
            return self.cache[key]
        w = words(name)
        found = None
        vias = self.vias(loc)
        for v in vias:
            if words(v) == w:
                found = v
                break
        if not found:
            for v in vias:
                if sorted(words(v)) == sorted(w):
                    found = v
                    break
        if not found:
            core = set(w) - STOP
            cand = [v for v in vias if core and set(words(v)) - STOP == core]
            if len(cand) == 1:
                found = cand[0]
        if not found:
            # Stesso nome scritto con spazi diversi (SETTE PONTI / SETTEPONTI)
            joined = ''.join(x for x in w if x not in STOP)
            cand = [v for v in vias if joined and ''.join(x for x in words(v) if x not in STOP) == joined]
            if len(cand) == 1:
                found = cand[0]
        self.cache[key] = found
        return found


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('files', nargs='+', help='file xlsx (accetta anche pattern con *)')
    ap.add_argument('--db', default='data/postezone.db')
    ap.add_argument('--out', default='import')
    args = ap.parse_args()

    paths = sorted({p for pattern in args.files for p in glob.glob(pattern)})
    if not paths:
        sys.exit('Nessun file trovato.')

    db = sqlite3.connect(args.db)
    strad = Stradario(db)

    rules = {}                       # (via, loc, tipo, da, a) -> zona
    conflicts, unparsed = [], []
    matched, new_streets = {}, defaultdict(set)
    per_file = Counter()
    unknown_loc = Counter()

    for path in paths:
        rows = list(sheets(path).values())[0]
        for r in rows[4:]:
            r = r + [''] * (14 - len(r))
            if not r[11].strip():
                continue
            loc = r[6].strip().upper()
            if loc not in strad.by_loc:
                unknown_loc[loc] += 1
            name = ' '.join(x.strip() for x in (r[9], r[10], r[11]) if x.strip()).upper()
            zona = re.sub(r'\s*-\s*', '', r[5].strip().upper())
            parsed = parse_range(r[13])
            if not zona:
                continue
            if parsed is None:
                unparsed.append((loc, name, r[13].strip()))
                continue

            via = strad.find(loc, name)
            if via:
                matched[(via, loc)] = name
            else:
                via = name
                new_streets[loc].add(name)
            per_file[os.path.basename(path)] += 1

            tipo, da, a = parsed
            key = (via, loc, tipo, da, a)
            if key in rules and rules[key] != zona:
                conflicts.append((loc, via, tipo, da, a, rules[key], zona))
            else:
                rules.setdefault(key, zona)

    os.makedirs(args.out, exist_ok=True)
    csv_path = os.path.join(args.out, 'ripartizione.csv')
    with open(csv_path, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(['via', 'localita', 'tipo_civico', 'da_civico', 'a_civico', 'zona_sigla', 'barcode'])
        for (via, loc, tipo, da, a), zona in sorted(rules.items(), key=lambda kv: (kv[0][1], kv[0][0], str(kv[0][2:]))):
            w.writerow([via, loc, tipo, da, a, zona, ''])

    # Vie nuove molto simili a vie gia' presenti: probabili varianti di nome da controllare a mano
    near = []
    for loc, names in new_streets.items():
        existing = [v for v in strad.vias(loc) if (v, loc) not in matched]
        for n in sorted(names):
            best = difflib.get_close_matches(n, existing, n=1, cutoff=0.86)
            if best:
                near.append((loc, n, best[0]))

    overwritten = sum(strad.zone_assegnate[k] for k in matched)
    streets = {(k[0], k[1]) for k in rules}
    lines = []
    lines.append('REPORT IMPORTAZIONE RIPARTIZIONE UFFICIO')
    lines.append('File: ' + ', '.join(os.path.basename(p) for p in paths))
    lines.append(f'Regole generate: {len(rules)}  su {len(streets)} vie')
    lines.append(f'Vie gia\' presenti nello stradario (regole sostituite): {len(matched)}')
    lines.append(f'Vie nuove (non presenti nello stradario): {sum(len(v) for v in new_streets.values())}')
    lines.append(f'Righe DB con zona gia\' assegnata che verranno sostituite: {overwritten}')
    lines.append('Righe lette per file: ' + ', '.join(f'{k}={v}' for k, v in per_file.items()))
    if unknown_loc:
        lines.append(f'ATTENZIONE comuni non presenti nel database: {dict(unknown_loc)}')
    lines.append('')
    lines.append(f'Vie nuove per comune: ' + ', '.join(f'{k}={len(v)}' for k, v in sorted(new_streets.items())))
    lines.append('')
    lines.append(f'== Range civici non riconosciuti, riga saltata ({len(unparsed)}) ==')
    lines += [f'  {a} | {b} | "{c}"' for a, b, c in unparsed]
    lines.append('')
    lines.append(f'== Conflitti: stessa via e stesso intervallo con zone diverse, tenuta la prima ({len(conflicts)}) ==')
    lines += [f'  {c[0]} | {c[1]} | {c[2]} {c[3]}-{c[4]}: {c[5]} / {c[6]}' for c in conflicts]
    lines.append('')
    lines.append(f'== Vie nuove simili a vie esistenti, possibili varianti di nome ({len(near)}) ==')
    lines += [f'  {a} | Excel: {b}  ~  DB: {c}' for a, b, c in near]
    with open(os.path.join(args.out, 'ripartizione_report.txt'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')

    print('\n'.join(lines[:10]))
    print(f'\nScritti: {csv_path} e ripartizione_report.txt')


if __name__ == '__main__':
    main()
