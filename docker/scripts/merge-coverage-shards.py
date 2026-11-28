#!/usr/bin/env python3
"""Combine coverage only after proving every isolated shard belongs to this candidate."""

import importlib.util
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


ROOT = Path.cwd()
sys.path.insert(0, str(ROOT / 'docker/tests/process-core'))
import rig  # noqa: E402

SPEC = importlib.util.spec_from_file_location('release_quality_groups', ROOT / 'docker/scripts/release-quality-groups.py')
groups = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(groups)


def validate(directory, head, root):
    inputs = root / 'tests/Fixtures/ProcessCore/runtime-inputs.json'
    baseline = (json.loads(inputs.read_text())['repositories'],
                groups.sha256(root / 'tests/Fixtures/ProcessCore/manifest.json'), groups.sha256(inputs))
    cases = set()
    suites = ET.Element('testsuites')
    duration = 0
    for group, samples in groups.SHARDS.items():
        shard = directory / f'release-quality-group-{head}-{group}'
        proof = json.loads((shard / f'provenance-{group}.json').read_text())
        if (proof.get('format') != 'release-quality-group/v1' or proof.get('group') != group
                or proof.get('candidate') != head or proof.get('samples') != samples):
            raise ValueError(f'{group} has invalid candidate or sample provenance')
        if (proof['dependencies'], proof['manifest_sha256'], proof['runtime_inputs_sha256']) != baseline:
            raise ValueError(f'{group} has different dependencies or fixtures')
        groups.validate_samples(group, shard)
        root_xml = ET.parse(shard / f'{group}.xml').getroot()
        for case in root_xml.findall('.//testcase'):
            key = (case.get('classname', ''), case.get('name', ''))
            if key in cases:
                raise ValueError(f'duplicate test across coverage shards: {key}')
            cases.add(key)
        suites.extend(list(root_xml) if root_xml.tag == 'testsuites' else [root_xml])
        duration += int((shard / f'{group}.milliseconds').read_text().strip())
    return suites, duration, baseline


def merge(directory, head, root):
    suites, duration, baseline = validate(directory, head, root)
    output = directory / f'release-quality-group-{head}-coverage'
    output.mkdir()
    ET.ElementTree(suites).write(output / 'coverage.xml', encoding='utf-8', xml_declaration=True)
    (output / 'coverage.milliseconds').write_text(f'{duration}\n')
    (output / 'coverage.txt').write_text(''.join(
        (directory / f'release-quality-group-{head}-{group}' / f'{group}.txt').read_text()
        for group in groups.SHARDS))
    rig.identity()
    rig.readiness()
    rig.destructive_guard()
    target = '/var/www/html/storage/app/coverage-shards'
    rig.compose('exec', '-T', 'app', 'mkdir', '-p', target)
    for group in groups.SHARDS:
        source = directory / f'release-quality-group-{head}-{group}' / f'{group}.php'
        rig.compose('cp', str(source), f'app:{target}/{group}.php')
    php = r"require 'vendor/autoload.php'; $r=(new SebastianBergmann\CodeCoverage\Serialization\Merger())->merge(array_slice($argv, 1)); SebastianBergmann\CodeCoverage\Report\Facade::fromSerializedData($r)->renderClover('/var/www/html/storage/app/coverage-shards/coverage-clover.xml');"
    rig.compose('exec', '-T', 'app', 'php', '-d', 'memory_limit=2G', '-r', php,
                *[f'{target}/{group}.php' for group in groups.SHARDS])
    rig.compose('cp', f'app:{target}/coverage-clover.xml', str(output / 'coverage-clover.xml'))
    proof = {'format': 'release-quality-group/v1', 'group': 'coverage', 'candidate': head,
             'samples': groups.GROUPS['coverage'], 'dependencies': baseline[0],
             'manifest_sha256': baseline[1], 'runtime_inputs_sha256': baseline[2],
             'coverage_shards': [{
                 'group': group,
                 'junit_sha256': groups.sha256(directory / f'release-quality-group-{head}-{group}' / f'{group}.xml'),
                 'coverage_sha256': groups.sha256(directory / f'release-quality-group-{head}-{group}' / f'{group}.php'),
                 'duration_sha256': groups.sha256(directory / f'release-quality-group-{head}-{group}' / f'{group}.milliseconds'),
                 'provenance_sha256': groups.sha256(directory / f'release-quality-group-{head}-{group}' / f'provenance-{group}.json'),
             } for group in groups.SHARDS]}
    groups.validate_samples('coverage', output)
    (output / 'provenance-coverage.json').write_text(json.dumps(proof, sort_keys=True, indent=2) + '\n')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('usage: merge-coverage-shards.py GROUP_DIR')
    merge(Path(sys.argv[1]), groups.candidate(), ROOT)
