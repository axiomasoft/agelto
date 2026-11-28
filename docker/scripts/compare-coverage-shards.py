#!/usr/bin/env python3
"""Fail if sharded coverage differs from a sequential run on the same candidate."""

import importlib.util
from pathlib import Path
import sys
import xml.etree.ElementTree as ET


ROOT = Path.cwd()
SPEC = importlib.util.spec_from_file_location('release_quality', ROOT / 'docker/scripts/check-release-quality.py')
quality = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(quality)


def statements(path):
    result = {}
    root = ET.parse(path).getroot()
    for file in root.findall('.//file'):
        for line in file.findall('line'):
            if line.get('type') != 'stmt':
                continue
            key = (file.get('name'), line.get('num'))
            if key in result:
                raise ValueError(f'duplicate Clover statement: {key}')
            result[key] = int(line.get('count', '0')) > 0
    if not result:
        raise ValueError(f'no coverage statements in {path}')
    return result


def compare(reference, sharded):
    if quality.normalized_junit(reference / 'coverage.xml') != quality.normalized_junit(sharded / 'coverage.xml'):
        raise ValueError('coverage shard test identities differ from the full run')
    expected = statements(reference / 'coverage-clover.xml')
    actual = statements(sharded / 'coverage-clover.xml')
    different = [key for key in expected.keys() | actual.keys() if expected.get(key) != actual.get(key)]
    if different:
        raise ValueError(f'coverage differs for {len(different)} statements: {different[:10]}')
    print(f'coverage matches: {len(expected)} statements')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('usage: compare-coverage-shards.py REFERENCE_DIR SHARDED_DIR')
    compare(Path(sys.argv[1]), Path(sys.argv[2]))
