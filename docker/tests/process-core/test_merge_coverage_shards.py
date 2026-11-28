import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('merge_coverage_shards', ROOT / 'docker/scripts/merge-coverage-shards.py')
merge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(merge)


class MergeCoverageShardsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.directory = self.root / 'groups'
        self.directory.mkdir()
        self.head = 'a' * 40
        fixtures = self.root / 'tests/Fixtures/ProcessCore'
        fixtures.mkdir(parents=True)
        self.dependencies = {'proxyma': {'commit': 'abc'}}
        (fixtures / 'runtime-inputs.json').write_text(json.dumps({'repositories': self.dependencies}))
        (fixtures / 'manifest.json').write_text('{}')
        for group, samples in merge.groups.SHARDS.items():
            shard = self.directory / f'release-quality-group-{self.head}-{group}'
            shard.mkdir()
            (shard / f'{group}.xml').write_text(f'<testsuites><testsuite><testcase classname="C" name="{group}" assertions="1"/></testsuite></testsuites>')
            (shard / f'{group}.milliseconds').write_text('100')
            (shard / f'{group}.php').write_text('<?php return [];')
            proof = {'format': 'release-quality-group/v1', 'group': group, 'candidate': self.head,
                     'samples': samples, 'dependencies': self.dependencies,
                     'manifest_sha256': merge.groups.sha256(fixtures / 'manifest.json'),
                     'runtime_inputs_sha256': merge.groups.sha256(fixtures / 'runtime-inputs.json')}
            (shard / f'provenance-{group}.json').write_text(json.dumps(proof))

    def shard(self, group):
        return self.directory / f'release-quality-group-{self.head}-{group}'

    def test_merges_disjoint_passing_cases_and_sums_runtime(self):
        suites, duration, _ = merge.validate(self.directory, self.head, self.root)
        self.assertEqual(len(suites.findall('.//testcase')), 4)
        self.assertEqual(duration, 400)

    def test_missing_or_nonpassing_shard_is_rejected(self):
        (self.shard('coverage-2') / 'coverage-2.php').unlink()
        with self.assertRaisesRegex(ValueError, 'serialized coverage missing'):
            merge.validate(self.directory, self.head, self.root)
        (self.shard('coverage-2') / 'coverage-2.php').write_text('placeholder')
        (self.shard('coverage-2') / 'coverage-2.xml').write_text('<testsuites><testcase assertions="1"><failure/></testcase></testsuites>')
        with self.assertRaisesRegex(ValueError, 'no passing executed evidence'):
            merge.validate(self.directory, self.head, self.root)

    def test_duplicate_case_or_wrong_candidate_is_rejected(self):
        xml = self.shard('coverage-2') / 'coverage-2.xml'
        xml.write_text((self.shard('coverage-1') / 'coverage-1.xml').read_text())
        with self.assertRaisesRegex(ValueError, 'duplicate test'):
            merge.validate(self.directory, self.head, self.root)
        xml.write_text('<testsuites><testcase classname="C" name="unique" assertions="1"/></testsuites>')
        proof = self.shard('coverage-2') / 'provenance-coverage-2.json'
        content = json.loads(proof.read_text())
        content['candidate'] = 'b' * 40
        proof.write_text(json.dumps(content))
        with self.assertRaisesRegex(ValueError, 'invalid candidate'):
            merge.validate(self.directory, self.head, self.root)


if __name__ == '__main__':
    unittest.main()
