# P1 remediation carrier baseline — 2026-08-10

## Источник и граница

Repo-grounded recon выполнен по current working tree после `## Audit P1 — 2026-08-10`.
Внешние факты заново не проверялись: frozen official-source evidence остаётся в
`findings/P1-docker-make-vite-recon-2026-08-09.md`. Product files не изменялись.

## Наблюдения

- `docker/Makefile` реализует `image:build`, `prod:deploy:code` и `prod:migrate`, но не
  объявленные audit'ом `prod:deploy:php-deps`, `prod:deploy:frontend`,
  `prod:deploy:runtime`, `prod:image:promote`, `prod:deploy:image`.
- `docker/tests/p1-release-contract.sh` проверяет один fake-Docker code-deploy path и вызывает
  make/compose/prod-image/Vite carriers, но пропускает `dev-code-sync.sh` и полную class matrix.
- `docker/tests/vite-contract.sh` ограничен manifest/config checks и host pnpm build; unique
  Compose project, `@vite/client` и hot-file runtime smoke отсутствуют.
- `docker/tests/prod-image-contract.sh` доказывает stage/user/PHP и static Compose boundary,
  но не проверяет secret sentinel в history/final filesystem и независимые project names.
- `docs/03_Dev/process/deploy.md` рекламирует отсутствующие targets; `.ai/guidelines/architecture.md`
  продолжает утверждать yarn и volume-owned production artifacts вопреки D17/D18.
- Layout v2 `plan_status._phase_status()` вычисляет фазу только из item states: terminal P1.1–P1.5
  плюс untouched P1.6 дают `⬜ Not started`; phase-level `plan-design` journal event влияет на
  Update Log, но не на status. Ложный `plan-run in-progress` был бы fabricated execution evidence.

## Вердикт

Audit F1–F2 воспроизводится на current carriers. Изменения локализуются в Make/scripts/tests и
их source-first AI/living docs consumers; Compose/Dockerfile/Vite product topology, уже отмеченная
audit'ом «без находок», не требует повторного проектирования. RAG:— (repo-grounded: перечисленные
файлы, `phases/P1/P1.md` audit block и Task `packages/task/lib/task/plan_status.py`).
