import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('check_coverage_floor', ROOT / 'docker/scripts/check-coverage-floor.py')
floor = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(floor)


class CoverageFloorTest(unittest.TestCase):
    def test_checks_both_global_and_critical_file_floors(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            budget, report = root / 'budget.json', root / 'coverage.xml'
            budget.write_text(json.dumps({'coverage': {'critical_files': ['app/Test.php'],
                                                       'critical_file_floor': 55, 'global_statement_floor': 80}}))
            template = ('<coverage><project><file name="app/Test.php"><metrics statements="10" coveredstatements="{critical}"/>'
                        '</file><metrics statements="100" coveredstatements="{total}"/></project></coverage>')
            report.write_text(template.format(critical=6, total=80))
            floor.check(budget, report)
            report.write_text(template.format(critical=6, total=79))
            with self.assertRaisesRegex(ValueError, 'Global coverage'):
                floor.check(budget, report)
            report.write_text(template.format(critical=5, total=80))
            with self.assertRaisesRegex(ValueError, 'Critical-file coverage'):
                floor.check(budget, report)


if __name__ == '__main__':
    unittest.main()
