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

Output (cartella --out): ripartizione.csv, ripartizione_verifiche.csv (segnalazioni da far verificare agli
operatori) e ripartizione_report.txt (da leggere prima di importare).
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
        self.old_zones = defaultdict(set)      # (via, loc) -> zone gia' assegnate nel database
        self.old_rows = defaultdict(int)
        for via, loc, zona in db.execute('SELECT via, localita, zona_sigla FROM smistamento_vie'):
            self.by_loc[loc].append(via)
            if zona != 'DA ASSEGNARE':
                self.old_zones[(via, loc)].add(zona)
                self.old_rows[(via, loc)] += 1
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


def parity_class(tipo):
    return {'PARI_INTERVALLO': 0, 'DISPARI_INTERVALLO': 1}.get(tipo)   # None = qualsiasi parita'


def find_overlaps(rules):
    """Intervalli (non singoli civici) della stessa via che si sovrappongono con zone diverse."""
    by_street = defaultdict(list)
    for (via, loc, tipo, da, a), zona in rules.items():
        if tipo.endswith('INTERVALLO') and da != a:
            by_street[(via, loc)].append((tipo, da, a, zona))
    out = []
    for (via, loc), items in by_street.items():
        items.sort(key=lambda x: (x[1], x[2]))
        for i in range(len(items)):
            for j in range(i + 1, len(items)):
                t1, d1, a1, z1 = items[i]
                t2, d2, a2, z2 = items[j]
                p1, p2 = parity_class(t1), parity_class(t2)
                if z1 != z2 and d2 <= a1 and (p1 is None or p2 is None or p1 == p2):
                    out.append((via, loc, f'{t1} {d1}-{a1} ({z1}) e {t2} {d2}-{a2} ({z2})'))
    return out


def similar_names(strad, new_streets, matched):
    """Vie nuove con nome quasi uguale a una via esistente non abbinata (stessa tipologia, resto del nome simile)."""
    out = []
    for loc, names in new_streets.items():
        existing = [v for v in strad.vias(loc) if (v, loc) not in matched]
        by_first = defaultdict(list)
        for v in existing:
            by_first[words(v.split(' ')[0])[0] if words(v.split(' ')[0]) else ''].append(v)
        for n in sorted(names):
            first, _, rest = n.partition(' ')
            fw = words(first)
            cands = {v: v.partition(' ')[2] for v in by_first.get(fw[0] if fw else '', [])}
            best = difflib.get_close_matches(rest, list(cands.values()), n=1, cutoff=0.8)
            if best:
                out.append((loc, n, next(v for v, r in cands.items() if r == best[0])))
    return out


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
    flags = []                       # (via, loc, motivo): voci da far verificare a un operatore
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
            if not zona:
                continue

            via = strad.find(loc, name)
            is_new = via is None
            if is_new:
                via = name
            else:
                matched[(via, loc)] = name

            parsed = parse_range(r[13])
            if parsed is None:
                unparsed.append((loc, name, r[13].strip()))
                flags.append((via, loc, f'Riga NON importata: intervallo civici "{r[13].strip()}" non riconosciuto (zona {zona}). Inserire le regole a mano.'))
                continue

            if is_new:
                new_streets[loc].add(name)
            per_file[os.path.basename(path)] += 1

            tipo, da, a = parsed
            key = (via, loc, tipo, da, a)
            if key in rules and rules[key] != zona:
                conflicts.append((loc, via, tipo, da, a, rules[key], zona))
                rng = 'tutti i civici' if tipo == 'TUTTI' else f'{tipo} {da}-{a}'
                flags.append((via, loc, f"Conflitto nei dati: {rng} con zone diverse ({rules[key]} tenuta, {zona} scartata). Verificare quale e' corretta."))
            else:
                rules.setdefault(key, zona)

    overlaps = find_overlaps(rules)
    flags += [(v, l, f'Intervalli sovrapposti con zone diverse: {d}. Verificare.') for v, l, d in overlaps]

    # Zone gia' assegnate nel database e diverse da quelle del nuovo file
    new_zones = defaultdict(set)
    for (via, loc, *_), zona in rules.items():
        new_zones[(via, loc)].add(zona)
    replaced_zones = []
    for key in matched:
        old = strad.old_zones.get(key, set())
        nuove = new_zones.get(key, set())
        if old and old - nuove:
            replaced_zones.append((key, old, nuove))
            flags.append((key[0], key[1], f"La zona precedente ({', '.join(sorted(old))}) e' stata sostituita dal file Excel ({', '.join(sorted(nuove))}). Verificare."))

    near = similar_names(strad, new_streets, matched)
    for loc, n, v in near:
        flags.append((n, loc, f'Nome simile a "{v}" gia\' presente nello stradario: possibile duplicato da unire.'))
        flags.append((v, loc, f'Nome simile a "{n}" importato dall\'Excel: possibile duplicato da unire (questa via resta senza zona).'))

    os.makedirs(args.out, exist_ok=True)
    csv_path = os.path.join(args.out, 'ripartizione.csv')
    with open(csv_path, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(['via', 'localita', 'tipo_civico', 'da_civico', 'a_civico', 'zona_sigla', 'barcode'])
        for (via, loc, tipo, da, a), zona in sorted(rules.items(), key=lambda kv: (kv[0][1], kv[0][0], str(kv[0][2:]))):
            w.writerow([via, loc, tipo, da, a, zona, ''])

    flags_path = os.path.join(args.out, 'ripartizione_verifiche.csv')
    unique_flags = sorted(set(flags), key=lambda x: (x[1], x[0], x[2]))
    with open(flags_path, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(['via', 'localita', 'motivo'])
        for via, loc, motivo in unique_flags:
            w.writerow([via, loc, motivo])

    overwritten = sum(strad.old_rows[k] for k in matched)
    streets = {(k[0], k[1]) for k in rules}
    lines = []
    lines.append('REPORT IMPORTAZIONE RIPARTIZIONE UFFICIO')
    lines.append('File: ' + ', '.join(os.path.basename(p) for p in paths))
    lines.append(f'Regole generate: {len(rules)}  su {len(streets)} vie')
    lines.append(f"Vie gia' presenti nello stradario (regole sostituite): {len(matched)}")
    lines.append(f'Vie nuove (non presenti nello stradario): {sum(len(v) for v in new_streets.values())}')
    lines.append(f"Righe DB con zona gia' assegnata che verranno sostituite: {overwritten}")
    lines.append(f'Segnalazioni da verificare generate: {len(unique_flags)} (file ripartizione_verifiche.csv)')
    lines.append('Righe lette per file: ' + ', '.join(f'{k}={v}' for k, v in per_file.items()))
    if unknown_loc:
        lines.append(f'ATTENZIONE comuni non presenti nel database: {dict(unknown_loc)}')
    lines.append('')
    lines.append('Vie nuove per comune: ' + ', '.join(f'{k}={len(v)}' for k, v in sorted(new_streets.items())))
    lines.append('')
    lines.append(f'== Range civici non riconosciuti, riga saltata ({len(unparsed)}) ==')
    lines += [f'  {a} | {b} | "{c}"' for a, b, c in unparsed]
    lines.append('')
    lines.append(f'== Conflitti: stessa via e stesso intervallo con zone diverse, tenuta la prima ({len(conflicts)}) ==')
    lines += [f'  {c[0]} | {c[1]} | {c[2]} {c[3]}-{c[4]}: {c[5]} / {c[6]}' for c in conflicts]
    lines.append('')
    lines.append(f'== Intervalli sovrapposti con zone diverse ({len(overlaps)}) ==')
    lines += [f'  {l} | {v} | {d}' for v, l, d in overlaps]
    lines.append('')
    lines.append(f"== Zone gia' assegnate e sostituite ({len(replaced_zones)}) ==")
    lines += [f'  {k[1]} | {k[0]}: {sorted(o)} -> {sorted(n)}' for k, o, n in replaced_zones]
    lines.append('')
    lines.append(f'== Vie nuove simili a vie esistenti, possibili varianti di nome ({len(near)}) ==')
    lines += [f'  {a} | Excel: {b}  ~  DB: {c}' for a, b, c in near]
    with open(os.path.join(args.out, 'ripartizione_report.txt'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')

    print('\n'.join(lines[:10]))
    print(f'\nScritti: {csv_path}, {flags_path} e ripartizione_report.txt')


if __name__ == '__main__':
    main()
