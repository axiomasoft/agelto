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


def metrics(path):
    root = ET.parse(path).getroot()
    project = root.find('./project/metrics')
    if project is None:
        raise ValueError(f'no project coverage metrics in {path}')
    files = {}
    for file in root.findall('.//file'):
        item = file.find('metrics')
        name = file.get('name')
        if item is None or not name or name in files:
            raise ValueError(f'invalid file coverage metrics in {path}')
        files[name] = dict(item.attrib)
    return dict(project.attrib), files


def compare(reference, sharded):
    if quality.normalized_junit(reference / 'coverage.xml') != quality.normalized_junit(sharded / 'coverage.xml'):
        raise ValueError('coverage shard test identities differ from the full run')
    expected = statements(reference / 'coverage-clover.xml')
    actual = statements(sharded / 'coverage-clover.xml')
    different = [key for key in expected.keys() | actual.keys() if expected.get(key) != actual.get(key)]
    if different:
        raise ValueError(f'coverage differs for {len(different)} statements: {different[:10]}')
    if metrics(reference / 'coverage-clover.xml') != metrics(sharded / 'coverage-clover.xml'):
        raise ValueError('coverage metrics differ')
    print(f'coverage matches: {len(expected)} statements')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('usage: compare-coverage-shards.py REFERENCE_DIR SHARDED_DIR')
    compare(Path(sys.argv[1]), Path(sys.argv[2]))
