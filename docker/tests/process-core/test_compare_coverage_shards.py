import importlib.util
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('compare_coverage_shards', ROOT / 'docker/scripts/compare-coverage-shards.py')
compare = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(compare)


class CompareCoverageShardsTest(unittest.TestCase):
    def test_detects_changed_tests_and_covered_lines(self):
        with tempfile.TemporaryDirectory() as temp:
            reference, sharded = Path(temp) / 'reference', Path(temp) / 'sharded'
            reference.mkdir()
            sharded.mkdir()
            junit = '<testsuites><testcase classname="C" name="A" assertions="1"/></testsuites>'
            clover = ('<coverage><project><file name="/app/A.php"><line num="2" type="stmt" count="1"/>'
                      '<metrics statements="1" coveredstatements="1"/></file>'
                      '<metrics statements="1" coveredstatements="1"/></project></coverage>')
            for directory in (reference, sharded):
                (directory / 'coverage.xml').write_text(junit)
                (directory / 'coverage-clover.xml').write_text(clover)
            compare.compare(reference, sharded)
            (sharded / 'coverage-clover.xml').write_text(clover.replace('count="1"', 'count="0"'))
            with self.assertRaisesRegex(ValueError, 'coverage differs'):
                compare.compare(reference, sharded)
            (sharded / 'coverage-clover.xml').write_text(clover.replace('coveredstatements="1"', 'coveredstatements="0"'))
            with self.assertRaisesRegex(ValueError, 'coverage metrics differ'):
                compare.compare(reference, sharded)
            (sharded / 'coverage-clover.xml').write_text(clover)
            (sharded / 'coverage.xml').write_text(junit.replace('name="A"', 'name="B"'))
            with self.assertRaisesRegex(ValueError, 'test identities differ'):
                compare.compare(reference, sharded)


if __name__ == '__main__':
    unittest.main()
