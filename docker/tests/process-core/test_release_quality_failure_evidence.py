"""A failed release sample must leave actionable reports for the CI artifact."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/collect-release-quality-evidence.sh'


class ReleaseQualityFailureEvidenceTest(unittest.TestCase):
    def test_после_падения_сохраняет_его_junit_и_предыдущие_результаты(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            script = root / 'repo/docker/scripts/collect-release-quality-evidence.sh'
            script.parent.mkdir(parents=True)
            shutil.copyfile(SCRIPT, script)
            binaries = root / 'bin'
            binaries.mkdir()
            container = root / 'container'
            evidence = root / 'evidence'
            docker = binaries / 'docker'
            docker.write_text('''#!/usr/bin/python3
import os, pathlib, shutil, sys
args = sys.argv[1:]
root = pathlib.Path(os.environ['FAKE_CONTAINER_ROOT'])
if 'exec' in args:
    if args[-1] == 'pwd':
        print('/container')
    else:
        (root / 'storage/app/release-quality').mkdir(parents=True, exist_ok=True)
elif 'cp' in args:
    source = args[args.index('cp') + 1].removeprefix('app:/container/')
    try:
        shutil.copyfile(root / source, args[-1])
    except FileNotFoundError:
        sys.exit(1)
else:
    sys.exit(2)
''')
            python = binaries / 'python3'
            python.write_text('''#!/usr/bin/python3
import os, pathlib, sys
evidence, name = pathlib.Path(sys.argv[3]), sys.argv[4]
(evidence / (name + '.txt')).write_text('sample output: ' + name)
report = pathlib.Path(os.environ['FAKE_CONTAINER_ROOT']) / 'storage/app/release-quality' / (name + '.xml')
report.write_text('<testsuites><testcase name="' + name + '"/></testsuites>')
sys.exit(1 if name == 'serial-2' else 0)
''')
            docker.chmod(0o755)
            python.chmod(0o755)
            env = dict(os.environ, PATH=f'{binaries}:{os.environ["PATH"]}',
                       FAKE_CONTAINER_ROOT=str(container), COMPOSE_PROJECT_NAME='agelto_core_test',
                       ENV_FILE='rig.env', RELEASE_QUALITY_EVIDENCE_DIR=str(evidence))
            result = subprocess.run(['bash', str(script), 'serial'], env=env, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('sample output: serial-2', result.stderr)
            self.assertIn('serial-1', (evidence / 'serial-1.xml').read_text())
            self.assertIn('serial-2', (evidence / 'serial-2.xml').read_text())
            self.assertTrue((evidence / 'serial-1.milliseconds').is_file())
            self.assertFalse((evidence / 'provenance-serial.json').exists())


if __name__ == '__main__':
    unittest.main()
