import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location('check_sample_budget', ROOT / 'docker/scripts/check-sample-budget.py')
sample = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(sample)


class SampleBudgetTest(unittest.TestCase):
    def test_профиль_использует_тот_же_бюджет_что_итоговая_проверка(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            budget = root / 'budget.json'
            budget.write_text(json.dumps({'budgets_milliseconds': {'profile': 4_200_000}}))
            duration = root / 'profile.milliseconds'
            duration.write_text('2800000')
            sample.check('profile', budget, root)
            duration.write_text('3115109')
            with self.assertRaisesRegex(ValueError, 'below the measured floor'):
                sample.check('profile', budget, root)

    def test_serial_использует_медиану_и_отклоняет_неизвестные_группы(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            budget = root / 'budget.json'
            budget.write_text(json.dumps({'budgets_milliseconds': {'serial': 60000}}))
            for n, value in enumerate((10000, 20000, 30000, 40000, 90000), 1):
                (root / f'serial-{n}.milliseconds').write_text(str(value))
            sample.check('serial', budget, root)
            with self.assertRaisesRegex(ValueError, 'unknown budgeted'):
                sample.check('random', budget, root)


if __name__ == '__main__':
    unittest.main()
