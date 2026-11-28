import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


MODULE_PATH = Path(__file__).resolve().parents[2] / 'scripts/release-quality-groups.py'
SPEC = importlib.util.spec_from_file_location('release_quality_groups', MODULE_PATH)
groups = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(groups)


class ReleaseQualityGroupsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.evidence = self.root / 'evidence'
        self.evidence.mkdir()
        fixtures = self.root / 'tests/Fixtures/ProcessCore'
        fixtures.mkdir(parents=True)
        self.dependencies = {'proxyma': {'commit': 'abc'}}
        (fixtures / 'runtime-inputs.json').write_text(json.dumps({'repositories': self.dependencies}))
        (fixtures / 'manifest.json').write_text('{}')
        self.head = 'a' * 40
        for group, samples in groups.GROUPS.items():
            group_dir = self.evidence / f'release-quality-group-{self.head}-{group}'
            group_dir.mkdir()
            for sample in samples:
                (group_dir / f'{sample}.milliseconds').write_text('100')
                (group_dir / f'{sample}.xml').write_text('<testsuites><testcase name="ok" assertions="1"/></testsuites>')
                (group_dir / f'{sample}.txt').write_text('passed')
            proof = {'format': 'release-quality-group/v1', 'group': group, 'candidate': self.head,
                     'samples': samples, 'dependencies': self.dependencies,
                     'manifest_sha256': groups.sha256(fixtures / 'manifest.json'),
                     'runtime_inputs_sha256': groups.sha256(fixtures / 'runtime-inputs.json')}
            (group_dir / f'provenance-{group}.json').write_text(json.dumps(proof))
        (self.evidence / f'release-quality-group-{self.head}-coverage/coverage-clover.xml').write_text('<coverage/>')
        self.output = self.root / 'output'

    def test_all_groups_are_required_and_bound_to_one_candidate(self):
        groups.verify(self.evidence, self.output, self.head, self.root)
        self.assertTrue((self.output / 'serial-1.xml').is_file())
        with self.assertRaises(FileNotFoundError):
            groups.verify(self.evidence, self.root / 'wrong', 'b' * 40, self.root)
        (self.evidence / f'release-quality-group-{self.head}-selected/provenance-selected.json').unlink()
        with self.assertRaises(FileNotFoundError):
            groups.verify(self.evidence, self.root / 'missing', self.head, self.root)

    def test_different_fixtures_and_nonpassing_sample_fail_closed(self):
        group_dir = self.evidence / f'release-quality-group-{self.head}-random'
        proof_path = group_dir / 'provenance-random.json'
        proof = json.loads(proof_path.read_text())
        proof['manifest_sha256'] = 'wrong'
        proof_path.write_text(json.dumps(proof))
        with self.assertRaisesRegex(ValueError, 'different dependencies or fixtures'):
            groups.verify(self.evidence, self.output, self.head, self.root)
        proof['manifest_sha256'] = groups.sha256(self.root / 'tests/Fixtures/ProcessCore/manifest.json')
        proof_path.write_text(json.dumps(proof))
        (group_dir / 'random-104729.xml').write_text('<testsuites><testcase><skipped/></testcase></testsuites>')
        with self.assertRaisesRegex(ValueError, 'no passing executed evidence'):
            groups.verify(self.evidence, self.output, self.head, self.root)

    def test_assertion_free_evidence_is_rejected(self):
        xml = self.evidence / f'release-quality-group-{self.head}-profile/profile.xml'
        xml.write_text('<testsuites><testcase name="empty" assertions="0"/></testsuites>')
        with self.assertRaisesRegex(ValueError, 'no passing executed evidence'):
            groups.verify(self.evidence, self.output, self.head, self.root)

    def test_group_cannot_override_another_groups_sample(self):
        wrong_group = self.evidence / f'release-quality-group-{self.head}-random'
        (wrong_group / 'serial-1.xml').write_text('<testsuites><testcase><failure/></testcase></testsuites>')
        groups.verify(self.evidence, self.output, self.head, self.root)
        result = (self.output / 'serial-1.xml').read_text()
        self.assertNotIn('<failure', result)

    def test_a_group_cannot_claim_another_candidate(self):
        proof_path = self.evidence / f'release-quality-group-{self.head}-serial/provenance-serial.json'
        proof = json.loads(proof_path.read_text())
        proof['candidate'] = 'b' * 40
        proof_path.write_text(json.dumps(proof))
        with self.assertRaisesRegex(ValueError, 'candidate'):
            groups.verify(self.evidence, self.output, self.head, self.root)

    def test_writing_proof_rejects_untracked_or_modified_files(self):
        metadata = self.root / 'docker/tests/process-core/runtime-inputs/metadata.json'
        metadata.parent.mkdir(parents=True)
        metadata.write_text(json.dumps({'candidate_head': self.head, 'dependencies': self.dependencies}))
        group_dir = self.evidence / f'release-quality-group-{self.head}-profile'
        with patch.object(groups, 'candidate', return_value=self.head), \
                patch.object(groups.subprocess, 'check_output', return_value='?? app/extra.php\n'):
            with self.assertRaisesRegex(ValueError, 'clean worktree'):
                groups.write('profile', group_dir, self.root)

    def test_требует_и_проверяет_происхождение_каждого_шарда_покрытия(self):
        with self.assertRaisesRegex(ValueError, 'shard provenance missing'):
            groups.verify(self.evidence, self.output, self.head, self.root, require_coverage_shards=True)
        records = []
        for name in groups.SHARDS:
            shard = self.evidence / f'release-quality-group-{self.head}-{name}'
            shard.mkdir()
            (shard / f'{name}.xml').write_text('<testsuites><testcase name="ok" assertions="1"/></testsuites>')
            (shard / f'{name}.php').write_text('<?php return [];')
            (shard / f'{name}.milliseconds').write_text('100')
            (shard / f'provenance-{name}.json').write_text('{}')
            records.append({'group': name, 'junit_sha256': groups.sha256(shard / f'{name}.xml'),
                            'coverage_sha256': groups.sha256(shard / f'{name}.php'),
                            'duration_sha256': groups.sha256(shard / f'{name}.milliseconds'),
                            'provenance_sha256': groups.sha256(shard / f'provenance-{name}.json')})
        coverage = self.evidence / f'release-quality-group-{self.head}-coverage'
        proof_path = coverage / 'provenance-coverage.json'
        proof = json.loads(proof_path.read_text())
        proof['coverage_shards'] = records
        proof_path.write_text(json.dumps(proof))
        (coverage / 'coverage.milliseconds').write_text('400')
        groups.verify(self.evidence, self.output, self.head, self.root, require_coverage_shards=True)
        (self.evidence / f'release-quality-group-{self.head}-coverage-2/coverage-2.php').write_text('tampered')
        with self.assertRaisesRegex(ValueError, 'does not match merged provenance'):
            groups.verify(self.evidence, self.root / 'tampered', self.head, self.root, require_coverage_shards=True)


if __name__ == '__main__':
    unittest.main()
