"""Master-data fidelity checks; all parsing is offline and no SQL is executed."""

from decimal import Decimal
import hashlib
import json
import unittest

import mysql_concept as concept


class MySqlConceptTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = concept.load_sources()
        cls.manifest = json.loads((concept.MYSQL / 'master-source-manifest.json').read_text())

    def test_reference_bytes_and_seed_literals_match_supplied_masters(self):
        for name, source in self.manifest['sources'].items():
            with self.subTest(table=name):
                actual = hashlib.sha256((concept.MYSQL / 'reference' / (name + '.sql')).read_bytes()).hexdigest()
                self.assertEqual(source['sha256'], actual)
        concept.validate_seed(self.sources)

    def test_source_counts_prices_and_relations_are_preserved(self):
        report = concept.validate_sources(self.sources)
        self.assertEqual({'areas': 1, 'regions': 3, 'branches': 16, 'service_areas': 392,
                          'packages': 2, 'designator_types': 5, 'designators': 724,
                          'designator_package_prices': 1030}, report['counts'])
        self.assertEqual('1054982170.00', report['price_total'])
        self.assertEqual(7, report['zero_prices'])
        self.assertEqual({'2': 306, '3': 724}, report['prices_by_package'])
        self.assertEqual([], report['non_null_category_ids'])
        self.assertEqual([2], report['source_audit_user_ids'])

    def test_migration_snapshot_preserves_all_source_fields_and_exact_prices(self):
        expected = {name: concept.decoded(data) for name, data in concept.expected_seed(self.sources).items()}
        expected = json.loads(json.dumps(expected, default=str))
        actual = json.loads((concept.ROOT / 'database/data/area3-master-data.json').read_text())
        self.assertEqual(expected, actual)

    def test_branch_codes_are_additional_and_do_not_duplicate_denpasar(self):
        records = concept.parse_inserts((concept.MYSQL / 'area3-master-data-concept.sql').read_text())
        rows = concept.decoded(records['branches'])
        self.assertEqual(self.manifest['branch_mapping'], {r['name']: r['kode_branch'] for r in rows})
        self.assertEqual(16, len({r['kode_branch'] for r in rows}))
        denpasar = [r for r in rows if r['name'] == 'DENPASAR']
        self.assertEqual(1, len(denpasar))
        self.assertEqual((14, 'DPS', 'R502'), (denpasar[0]['id_branch'], denpasar[0]['code'], denpasar[0]['kode_branch']))
        pekalongan = next(r for r in rows if r['name'] == 'PEKALONGAN')
        self.assertEqual('0001/LG.000/TA-03R424/11-2026',
                         '0001/LG.000/TA-03' + pekalongan['kode_branch'] + '/11-2026')

    def test_every_table_has_a_distinct_primary_key_and_valid_foreign_keys(self):
        report = concept.validate_schema()
        self.assertEqual(46, report['tables'])
        self.assertEqual(86, report['foreign_keys'])
        self.assertEqual(46, len(set(report['primary_keys'].values())))
        for table, key in {'areas': 'id_area', 'branches': 'id_branch',
                           'project_plans': 'id_pp', 'boq_lines': 'id_boq',
                           'term_actuals': 'id_term_actual', 'volume_balances': 'id_volume_balance'}.items():
            self.assertEqual(key, report['primary_keys'][table])

    def test_parser_preserves_quoted_delimiters_and_rejects_sql_expressions(self):
        text = "-- ignored SQL commands\nDROP TABLE x; INSERT INTO `sample` (`name`, `price`, `note`) VALUES ('a,b; c\\\'d', 0.00, 'line\\nnext'), ('it''s', 1.20, NULL);"
        rows = concept.decoded(concept.parse_inserts(text)['sample'])
        self.assertEqual({'name': "a,b; c'd", 'price': Decimal('0.00'), 'note': 'line\nnext'}, rows[0])
        self.assertEqual("it's", rows[1]['name'])
        for invalid in ("INSERT INTO x (id) VALUES (SLEEP(1));", "INSERT INTO x (id) VALUES (1, 2);", "INSERT INTO x (id) VALUES ('unclosed);"):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                concept.parse_inserts(invalid)


if __name__ == '__main__':
    unittest.main()
