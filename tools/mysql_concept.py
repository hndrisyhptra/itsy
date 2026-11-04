#!/usr/bin/env python3
"""Read-only SQL-dump parsing and concept seed validation; never connects to a DB."""

import argparse
from collections import Counter
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
MYSQL = ROOT / 'database/mysql'
TABLES = ('areas', 'regions', 'branches', 'service_areas', 'packages',
          'designator_types', 'designators', 'designator_package_prices')
BRANCH_CODES = {
    'PEKALONGAN': 'R424', 'SEMARANG': 'R428', 'SURAKARTA': 'R426',
    'YOGYAKARTA': 'R409', 'MAGELANG': 'R425', 'PURWOKERTO': 'R423',
    'MADIUN': 'R529', 'MALANG': 'R531', 'SIDOARJO': 'R535',
    'JEMBER': 'R536', 'DENPASAR': 'R502', 'LAMONGAN': 'R504',
    'SURABAYA': 'R501', 'MATARAM': 'R546', 'KUPANG': 'R547', 'FLORES': 'R505',
}


def statements(text):
    """Split only outside strings/comments. SQL content is data, never evaluated."""
    out, quote, i = [], None, 0
    while i < len(text):
        c = text[i]
        if quote:
            out.append(c)
            if c == '\\' and quote != '`':
                i += 1
                if i >= len(text):
                    raise ValueError('Unterminated SQL escape')
                out.append(text[i])
            elif c == quote:
                if i + 1 < len(text) and text[i + 1] == quote:
                    i += 1
                    out.append(text[i])
                else:
                    quote = None
        elif c in "'\"`":
            quote = c
            out.append(c)
        elif text.startswith('/*', i):
            end = text.find('*/', i + 2)
            if end == -1:
                raise ValueError('Unterminated SQL comment')
            i = end + 1
            out.append(' ')
        elif c == '#' or (text.startswith('--', i) and
                           (i + 2 == len(text) or text[i + 2].isspace())):
            end = text.find('\n', i)
            i = len(text) if end == -1 else end
            out.append('\n')
        elif c == ';':
            if ''.join(out).strip():
                yield ''.join(out).strip()
            out = []
        else:
            out.append(c)
        i += 1
    if quote:
        raise ValueError('Unterminated SQL string')
    if ''.join(out).strip():
        raise ValueError('Missing SQL statement terminator')


def split_top(text):
    """Split comma-separated literals/tuples without splitting quoted text."""
    result, start, depth, quote, i = [], 0, 0, None, 0
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\':
                i += 1
            elif c == quote:
                if i + 1 < len(text) and text[i + 1] == quote:
                    i += 1
                else:
                    quote = None
        elif c in "'\"`":
            quote = c
        elif c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
            if depth < 0:
                raise ValueError('Unbalanced SQL tuple')
        elif c == ',' and depth == 0:
            result.append(text[start:i].strip())
            start = i + 1
        i += 1
    if quote or depth:
        raise ValueError('Unbalanced SQL values')
    result.append(text[start:].strip())
    return result


def value(token):
    if token == 'NULL':
        return None
    if re.fullmatch(r'-?\d+(?:\.\d+)?', token):
        return Decimal(token) if '.' in token else int(token)
    if len(token) >= 2 and token[0] == token[-1] == "'":
        result, i = [], 1
        escapes = {'0': '\0', 'n': '\n', 'r': '\r', 't': '\t', 'b': '\b', 'Z': '\x1a'}
        while i < len(token) - 1:
            c = token[i]
            if c == '\\':
                i += 1
                result.append(escapes.get(token[i], token[i]))
            elif c == "'":
                if token[i + 1] != "'":
                    raise ValueError('Unexpected quote inside literal')
                result.append("'")
                i += 1
            else:
                result.append(c)
            i += 1
        return ''.join(result)
    raise ValueError('Only SQL NULL, numbers and quoted literals are supported')


def parse_inserts(text):
    records = {}
    for statement in statements(text):
        if not re.match(r'INSERT\b', statement, re.I):
            continue
        match = re.fullmatch(r'INSERT INTO `?(\w+)`?\s*\((.*?)\)\s*VALUES\s*(.*)', statement, re.S | re.I)
        if not match:
            raise ValueError('Unsupported INSERT syntax')
        table, columns, body = match.groups()
        columns = [c.strip().strip('`') for c in columns.split(',')]
        if any(not re.fullmatch(r'\w+', c) for c in columns) or len(set(columns)) != len(columns):
            raise ValueError('Invalid/duplicate column name')
        data = records.setdefault(table, {'columns': columns, 'rows': []})
        if columns != data['columns']:
            raise ValueError('Inconsistent INSERT column order')
        for row in split_top(body):
            if not (row.startswith('(') and row.endswith(')')):
                raise ValueError('Expected SQL literal tuple')
            tokens = split_top(row[1:-1])
            if len(tokens) != len(columns):
                raise ValueError('INSERT column/value count mismatch')
            for token in tokens:
                value(token)
            data['rows'].append(tokens)
    return records


def load_sources():
    sources = {}
    for table in TABLES:
        records = parse_inserts((MYSQL / 'reference' / (table + '.sql')).read_text())
        if set(records) != {table}:
            raise ValueError('Reference dump must contain exactly its named table')
        sources[table] = records[table]
    return sources


def decoded(data):
    return [dict(zip(data['columns'], map(value, row))) for row in data['rows']]


def expected_seed(sources):
    seed = {name: {'columns': list(data['columns']), 'rows': [list(r) for r in data['rows']]}
            for name, data in sources.items()}
    branches = seed['branches']
    branches['columns'].append('kode_branch')
    names = set()
    for row in branches['rows']:
        name = value(row[branches['columns'].index('name')])
        names.add(name)
        row.append("'" + BRANCH_CODES[name] + "'")
    if names != set(BRANCH_CODES):
        raise ValueError('Branch code mapping must cover exactly the source branches')
    return seed


def write_seed(sources):
    seed = expected_seed(sources)
    lines = [
        '-- CONCEPT DATA ONLY. Review with area3-schema-concept.sql; not deployed.',
        '-- INSERT literals copied from the eight supplied reference dumps.',
        '-- No DROP/USE/credentials/FK disabling or original dump commands are included.',
        '-- created_by/updated_by retain source numeric audit references, not login users.',
        '-- DENPASAR/R502 appeared twice in the request; its one source row is retained.',
        'SET NAMES utf8mb4;',
        "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';",
        "SET time_zone = '+00:00';",  # preserve source TIMESTAMP interpretation
        'START TRANSACTION;',
    ]
    for name, data in seed.items():
        lines += ['', '-- ' + name + ': ' + str(len(data['rows'])) + ' source rows',
                  'INSERT INTO `' + name + '` (' + ', '.join('`' + c + '`' for c in data['columns']) + ') VALUES',
                  ',\n'.join('(' + ', '.join(row) + ')' for row in data['rows']) + ';']
    lines += ['', 'COMMIT;', '']
    (MYSQL / 'area3-master-data-concept.sql').write_text('\n'.join(lines))


def validate_sources(sources):
    rows = {t: decoded(data) for t, data in sources.items()}
    pks = {t: next(c for c in sources[t]['columns'] if c.startswith('id_')) for t in TABLES}
    keys = {}
    for table, records in rows.items():
        ids = [row[pks[table]] for row in records]
        if len(ids) != len(set(ids)):
            raise ValueError('Duplicate source primary key: ' + table)
        keys[table] = set(ids)
        unique = ('workzone',) if table == 'service_areas' else ('code',)
        if table == 'designator_package_prices':
            unique = ('designator_id', 'package_id')
        values = [tuple(r[c] for c in unique) for r in records]
        if len(values) != len(set(values)):
            raise ValueError('Duplicate source natural key: ' + table)
    relations = [('regions', 'area_id', 'areas'), ('branches', 'region_id', 'regions'),
                 ('service_areas', 'branch_id', 'branches'), ('service_areas', 'region_id', 'regions'),
                 ('designators', 'designator_type_id', 'designator_types'),
                 ('designator_package_prices', 'designator_id', 'designators'),
                 ('designator_package_prices', 'package_id', 'packages')]
    for table, col, parent in relations:
        if any(r[col] is not None and r[col] not in keys[parent] for r in rows[table]):
            raise ValueError('Orphan source reference: ' + table + '.' + col)
    branches = {r['id_branch']: r for r in rows['branches']}
    inconsistent = [r['id_service_area'] for r in rows['service_areas']
                    if r['region_id'] is not None and r['region_id'] != branches[r['branch_id']]['region_id']]
    if inconsistent:
        raise ValueError('Service Area/Branch region disagreement: ' + str(inconsistent))
    prices = rows['designator_package_prices']
    if any(r['price'] < 0 for r in prices):
        raise ValueError('Negative source price')
    return {
        'counts': {t: len(records) for t, records in rows.items()},
        'price_total': str(sum((r['price'] for r in prices), Decimal('0'))),
        'zero_prices': sum(r['price'] == 0 for r in prices),
        'prices_by_package': {str(p): sum(r['package_id'] == p for r in prices) for p in sorted(keys['packages'])},
        'designators_by_type': dict(Counter(str(r['designator_type_id']) for r in rows['designators'])),
        'non_null_category_ids': sorted({r['designator_category_id'] for r in rows['designators'] if r['designator_category_id'] is not None}),
        'source_audit_user_ids': sorted({r[c] for records in rows.values() for r in records
                                         for c in ('created_by', 'updated_by') if r.get(c) is not None}),
    }


def validate_schema():
    sql = (MYSQL / 'area3-schema-concept.sql').read_text()
    tables, keys, references, pk_names = {}, {}, [], []
    for statement in statements(sql):
        match = re.fullmatch(r'CREATE TABLE (\w+) \((.*)\) ENGINE=.*', statement, re.S)
        if match:
            name, body = match.groups()
            if name in tables:
                raise ValueError('Duplicate table: ' + name)
            columns, unique_keys, primary = {}, [], []
            for line in split_top(body):
                field = re.match(r'`?(\w+)`?\s+((?:CHAR|VARCHAR|BIGINT|SMALLINT|TINYINT|INT|BOOLEAN|DATETIME|TIMESTAMP|DATE|DECIMAL|TEXT|JSON)(?:\(\d+(?:,\d+)?\))?(?: UNSIGNED)?)\s*(.*)', line, re.I | re.S)
                if field:
                    col, kind, tail = field.groups()
                    if col in columns or col == 'id':
                        raise ValueError('Duplicate or generic id column: ' + name + '.' + col)
                    kind = re.sub(r'^(BIGINT|INT|SMALLINT|TINYINT)\(\d+\)', r'\1', kind.upper())
                    columns[col] = kind
                    if 'PRIMARY KEY' in tail:
                        primary.append(col)
                        unique_keys.append((col,))
                    elif re.search(r'\bUNIQUE\b', tail):
                        unique_keys.append((col,))
            for cols in re.findall(r'(?:UNIQUE(?: KEY)?(?: `?\w+`?)?|PRIMARY KEY)\s*\(([^)]+)\)', body):
                unique_keys.append(tuple(x.strip().strip('`') for x in cols.split(',')))
            for cols in re.findall(r'PRIMARY KEY\s*\(([^)]+)\)', body):
                primary.extend(x.strip().strip('`') for x in cols.split(','))
            if len(primary) != 1 or not primary[0].startswith('id_') or primary[0] in pk_names:
                raise ValueError('Every table needs one distinct named primary key: ' + name)
            pk_names += primary
            tables[name], keys[name] = columns, unique_keys
            local_refs = re.findall(r'FOREIGN KEY \(([^)]+)\) REFERENCES (\w+)\s*\(([^)]+)\)', body)
            for source, target, dest in local_refs:
                if target not in tables:
                    raise ValueError('Forward or missing FK target: ' + target)
                references.append((name, source, target, dest))
        elif statement.startswith('ALTER TABLE boq_lines'):
            for col, kind in re.findall(r'ADD (\w+) ((?:CHAR|INT)(?:\(\d+\))?(?: UNSIGNED)?)', statement):
                tables['boq_lines'][col] = kind
            for cols in re.findall(r'ADD UNIQUE \(([^)]+)\)', statement):
                keys['boq_lines'].append(tuple(c.strip() for c in cols.split(',')))
            references += [('boq_lines', a, b, c) for a, b, c in re.findall(r'FOREIGN KEY \(([^)]+)\) REFERENCES (\w+)\s*\(([^)]+)\)', statement)]
        else:
            raise ValueError('Unexpected schema statement')
    for table, unique_keys in keys.items():
        if any(c not in tables[table] for key in unique_keys for c in key):
            raise ValueError('Unique index refers to unknown column: ' + table)
    for table, source, target, dest in references:
        src = tuple(c.strip() for c in source.split(','))
        dst = tuple(c.strip() for c in dest.split(','))
        if len(src) != len(dst) or dst not in keys[target]:
            raise ValueError('FK must reference matching unique key: ' + str((table, source, target, dest)))
        for a, b in zip(src, dst):
            if tables[table].get(a) != tables[target].get(b) or a not in tables[table] or b not in tables[target]:
                raise ValueError('Missing/incompatible FK columns: ' + str((table, a, target, b)))
    constraints = re.findall(r'CONSTRAINT (\w+)', sql)
    if len(constraints) != len(set(constraints)):
        raise ValueError('Duplicate constraint name')
    return {'tables': len(tables), 'foreign_keys': len(references), 'primary_keys': dict(zip(tables, pk_names))}


def validate_seed(sources):
    text = (MYSQL / 'area3-master-data-concept.sql').read_text()
    for statement in statements(text):
        if statement in ('SET NAMES utf8mb4',
                         "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION'",
                         "SET time_zone = '+00:00'", 'START TRANSACTION', 'COMMIT'):
            continue
        if not statement.startswith('INSERT INTO '):
            raise ValueError('Unexpected/destructive seed statement')
    actual = parse_inserts(text)
    if actual != expected_seed(sources):
        raise ValueError('Concept seed differs from source literals or requested branch mapping')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--write-seed', action='store_true', help='Generate only the concept data file; never import it')
    args = parser.parse_args()
    sources = load_sources()
    report = validate_sources(sources)
    if args.write_seed:
        write_seed(sources)
    validate_seed(sources)
    schema = validate_schema()
    report['schema'] = schema
    report['source_sha256'] = {t: hashlib.sha256((MYSQL / 'reference' / (t + '.sql')).read_bytes()).hexdigest() for t in TABLES}
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
