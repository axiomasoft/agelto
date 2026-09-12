---
audience: operator
type: reference
status: living
---

# Production commands

Для текущего ручного режима без CI: [полный manual runbook](manual-deployment.md).
На настроенном host используйте exact wrapper (от deploy):

```bash
cd /srv/agelto/repository
/srv/agelto/bin/compose ps
/srv/agelto/bin/compose logs --tail=100 app horizon scheduler
/srv/agelto/bin/compose logs --follow --tail=100 app horizon scheduler
make prod:readiness COMPOSE_PROD=/srv/agelto/bin/compose
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
```

`logs --follow` завершается Ctrl-C без остановки services. Wrapper фиксирует project,
production env и оба Compose files. Не выполняйте `prod:config` с выводом всего environment
после добавления external credentials. Registry-oriented `prod:deploy:*` здесь не используются.

Текущий source-derived inventory: `DEPLOYMENT_COMMAND_MATRIX.md` в корне checkout, generated from root
Make delegation, `docker/Makefile` `.PHONY` targets and declared P12 script modes.
Архивный P12 snapshot остаётся provenance, не обновляется при каждом изменении runtime scripts.

Перед копированием команд проверяйте текущую матрицу:

```bash
python3 docker/scripts/check-command-reference.py check \
  --output DEPLOYMENT_COMMAND_MATRIX.md
```

A stale/missing/duplicate/wrong-environment or unguarded destructive row fails closed. Local fixture
output is never runtime evidence; external/mutating rows remain blank until the follow-up rehearsal.
