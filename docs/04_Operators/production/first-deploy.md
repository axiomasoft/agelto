---
audience: operator
type: runbook
status: living
---

# Контракт первого запуска

Для человека используйте линейную главу [«Первый запуск»](deployment.md#4-первый-запуск-точная-последовательность):
в ней приведены реальные команды для image pair, services, migrations, seed, initial admin, buckets,
workers, readiness, Doctor, HTTPS и browser-проверки.

`docker/scripts/first-production-deploy.sh check` — машинно-проверяемый справочный порядок стадий;
он не запускает production и полезен только для аудита carrier. Его hermetic-проверка:

```bash
bash docker/tests/first-production-deploy-contract.sh
```
