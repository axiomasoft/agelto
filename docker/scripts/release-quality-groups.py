#!/usr/bin/env python3
"""Bind independently collected release samples to one candidate and fixture set."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET


GROUPS = {
    'serial': [f'serial-{i}' for i in range(1, 6)],
    'profile': ['profile'],
    'coverage': ['coverage'],
    'random': [f'random-{seed}' for seed in (104729, 130363, 155921)],
    'selected': [f'selected-serial-{i}' for i in range(1, 4)],
}
SHARDS = {f'coverage-{i}': [f'coverage-{i}'] for i in range(1, 5)}
WRITABLE_GROUPS = {**GROUPS, **SHARDS}


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def candidate():
    return subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()


def validate_samples(group, directory):
    for sample in WRITABLE_GROUPS[group]:
        duration = int((directory / f'{sample}.milliseconds').read_text().strip())
        cases = ET.parse(directory / f'{sample}.xml').getroot().findall('.//testcase')
        if (duration <= 0 or not cases or sum(int(case.get('assertions', '0')) for case in cases) <= 0
                or any(any(case.find(tag) is not None for tag in ('failure', 'error', 'skipped')) for case in cases)):
            raise ValueError(f'{sample} has no passing executed evidence')
    if group == 'coverage' and not (directory / 'coverage-clover.xml').is_file():
        raise ValueError('coverage report missing')
    if group in SHARDS and not (directory / f'{group}.php').is_file():
        raise ValueError(f'{group} serialized coverage missing')


def write(group, directory, root):
    validate_samples(group, directory)
    head = candidate()
    if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=all'], text=True).strip():
        raise ValueError('release samples require a clean worktree')
    runtime = json.loads((root / 'docker/tests/process-core/runtime-inputs/metadata.json').read_text())
    if runtime.get('candidate_head') != head:
        raise ValueError('runtime candidate differs from checkout')
    proof = {
        'format': 'release-quality-group/v1', 'group': group, 'candidate': head,
        'samples': WRITABLE_GROUPS[group], 'dependencies': runtime['dependencies'],
        'manifest_sha256': sha256(root / 'tests/Fixtures/ProcessCore/manifest.json'),
        'runtime_inputs_sha256': sha256(root / 'tests/Fixtures/ProcessCore/runtime-inputs.json'),
    }
    (directory / f'provenance-{group}.json').write_text(json.dumps(proof, sort_keys=True, indent=2) + '\n')


def verify(directory, output, head, root):
    runtime_inputs = root / 'tests/Fixtures/ProcessCore/runtime-inputs.json'
    baseline = (json.loads(runtime_inputs.read_text())['repositories'],
                sha256(root / 'tests/Fixtures/ProcessCore/manifest.json'), sha256(runtime_inputs))
    for group, samples in GROUPS.items():
        group_dir = directory / f'release-quality-group-{head}-{group}'
        proof = json.loads((group_dir / f'provenance-{group}.json').read_text())
        if proof.get('format') != 'release-quality-group/v1' or proof.get('group') != group or proof.get('candidate') != head or proof.get('samples') != samples:
            raise ValueError(f'{group} has invalid candidate or sample provenance')
        identity = (proof['dependencies'], proof['manifest_sha256'], proof['runtime_inputs_sha256'])
        if identity != baseline:
            raise ValueError(f'{group} has different dependencies or fixtures')
        validate_samples(group, group_dir)
    output.mkdir(parents=True, exist_ok=False)
    for group, samples in GROUPS.items():
        group_dir = directory / f'release-quality-group-{head}-{group}'
        names = [f'provenance-{group}.json']
        for sample in samples:
            names.extend((f'{sample}.xml', f'{sample}.milliseconds', f'{sample}.txt'))
        if group == 'coverage':
            names.append('coverage-clover.xml')
        for name in names:
            shutil.copyfile(group_dir / name, output / name)


def main():
    root = Path.cwd()
    if len(sys.argv) == 4 and sys.argv[1] == 'write' and sys.argv[2] in WRITABLE_GROUPS:
        write(sys.argv[2], Path(sys.argv[3]), root)
    elif len(sys.argv) == 4 and sys.argv[1] == 'verify':
        verify(Path(sys.argv[2]), Path(sys.argv[3]), candidate(), root)
    else:
        raise ValueError('usage: release-quality-groups.py write GROUP DIR | verify GROUP_DIR OUTPUT_DIR')


if __name__ == '__main__':
    main()
