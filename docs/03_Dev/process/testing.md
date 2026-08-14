---
audience: dev
type: howto
status: living
---

# Тестирование

Маршрут, не копия содержимого — источники истины ниже.

## Запуск

```bash
make db:create-test   # разово: настройка agelto_test
make test-php         # ENV=dev по умолчанию; test-preflight + db:create-test + artisan test --compact
make quality          # release-local carrier: audit + static/style/refactor guards + full PHP suite
```

Точечный прогон одного класса/фильтра — `make artisan ARGS="test --filter=<Subject>"`
(**один `--filter` за прогон** — второй игнорируется Symfony Console, не Laravel-баг).

Browser acceptance uses pinned `@playwright/test` 1.62.1 with Chromium. The setup project stores
ignored `storageState` files for SuperAdmin, Operator and Analyst; the responsive admin suite reuses
them and checks desktop/mobile role routes, ordered navigation groups, representative Sections and
table actions, role-specific direct-URL denial and a separate unauthenticated context.
`bash docker/tests/clean-install-e2e.sh` creates a detached worktree from exact `HEAD`, its own
Compose project, database/media volumes, auth state, traces and teardown. By default it creates
candidate-local one-time administrator credentials; `E2E_ADMIN_EMAIL` and
`E2E_ADMIN_PASSWORD_FILE` are optional explicit overrides. It never uses the owner's running stack.

Private-documentation release acceptance is `make documentation:acceptance` without required
external credential inputs. It composes active-SVG rejection, the focused Pest corpus, clean
vendor/VitePress/image checks and the isolated Playwright hash/header/logout/revoke run; its
candidate and image anchors are printed for the release evidence report.

## Правила

- Изоляция от прод/dev БД и media, каноны именования/структуры тестов — skill `testing-rules`.
- Agelto-специфичные оверрайды (только `test('<интент по-русски>', fn)`, без `it()`; гейт
  `Tests\Support\TestEnvironmentGuard`) — `.claude/rules/agelto-reviewer-overrides.md`. Тест падает
  на «не та БД» — сначала проверить этот гейт, не патчить симптом.
- LLM-вызовы в тестах — ТОЛЬКО через тестовые двойники (`tests/Support/Doubles/`), никаких реальных
  запросов к Gemini (Execution Rules плана EST-AI).
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`,
  `->primaryAnalysisFailed()`); цепочки связей — `->for()`/`->has()` или именованный билдер.

## Tia (Test Impact Analysis)

`tests/Pest.php`: `pest()->tia()->locally()` — TIA включается только в локальном edit-loop.
`always()`, `filtered()` и `baselined()` намеренно отсутствуют: `always()` является альтернативой
`locally()`, `filtered()` не даёт полного набора, а shared baseline появится только в trusted P11.5
workflow (D40).

- **Обычный прогон в разработке** — `make test:edit`: TIA реплеит незатронутые тесты и запускает
  затронутые. `make test:tia-refresh` создаёт свежий graph, а `make test:tia-reset` удаляет только
  project-scoped graph.
- **Приёмочный прогон item'а/фазы — только `make test:full`, `test:profile` или `test:coverage`**:
  каждый несёт `--ci --no-tia`. Replay, focused run и отдельный shard не являются evidence приёмки.
- **Sharding и parallel** — release default остаётся serial. `test:shard` остаётся диагностической
  командой с `--no-tia`; `make test:parallel PROCESSES=2|4` — отдельное доказательство изоляции,
  не acceptance replacement. До первой migration каждый worker обязан пройти exact token DB guard
  в Laravel `setUpTestDatabaseBeforeMigrating`; все app-owned disks переписаны в local root
  `storage/framework/testing/<process>/disks`. Bootstrap выбирает `<process>` из ParaTest
  `TEST_TOKEN` до autoload, поэтому `TMPDIR`, log/auth/artifact roots также process-scoped;
  teardown удаляет только этот root. `Storage::fake()` остаётся явным тестовым double, не safety
  boundary.
- **Кэш графа** — именованный том `app_pest_tia:/var/www/.pest` (не `~/.pest`, домашний `/var/www`
  контейнера не смонтирован volume'ом целиком), переживает `docker compose up -d --force-recreate`.
  Владелец тома и `git safe.directory` для bind-mount `html` (uid хоста ≠ www-data) заданы на
  уровне образа (`docker/php/Dockerfile`) — если граф не пишется после ручной правки Dockerfile,
  проверить `docker exec agelto_app ls -la /var/www/.pest` (должен быть `www-data:www-data`).
- **PCOV включён ПОСТОЯННО в dev** (bind-mount `docker/php/conf.d/pcov-dev.ini` поверх
  `/usr/local/etc/php/conf.d/pcov.ini` — только в `docker-compose.yml`, dev; в проде и в самом
  образе (`Dockerfile`) `docker/php/conf.d/pcov.ini` остаётся `pcov.enabled=0`, zero-cost,
  без изменений). Решение: `pcov.enabled`, переданный флагом `-d` на КАЖДЫЙ вызов, терялся при
  перезапуске воркера (`Pest\Restarters\PcovRestarter` перезапускает процесс с
  `-d pcov.directory=<root>` ТОЛЬКО, не `-d pcov.enabled=1`, — новый процесс читает `pcov.enabled`
  заново из файла на диске, где он снова `0`); постоянный `pcov.enabled=1` в примонтированном
  ini-файле убирает саму причину — `PcovRestarter` находит `pcov.directory` уже верным (задан там
  же явно, не `auto`) и не перезапускает процесс вовсе. Обычный
  `docker exec agelto_app php artisan test` (без `--filter`, без `-d`) теперь сам подхватывает
  pcov — проверено: `Pest\Plugins\Tia\Recorder` детектит драйвер через `ini_get('pcov.enabled')`
  в рантайме, ручной флаг/обходной путь через `./vendor/bin/pest` больше не нужен.
  После правки `docker/php/conf.d/pcov-dev.ini` — `docker compose up -d --force-recreate --no-deps
  app` (bind-mount отдельного ФАЙЛА не подхватывает правку хоста без пересоздания контейнера).
- **Известное ограничение (не от pcov, орфографически смежное).** Полный сюит (`artisan test` без
  `--filter`) сегодня почти целиком «uncached» на КАЖДОМ прогоне, даже без изменений в дереве:
  `Pest\Plugins\Tia\ChangedFiles::workingTreeChanges()` считает «изменёнными» ВСЕ untracked-файлы
  (`git status --porcelain --untracked-files=all`), включая чужой незакоммиченный `config/cms.php`
  (Execution Rules плана `2026.07.31-SYS-INTEGRITY` — «не трогать»); `config/*.php` грузится
  Laravel-бутстрапом на КАЖДОМ тесте, поэтому его «изменённость» инвалидирует edge почти всей
  матрицы. Фикс — не докер/pcov: `config/cms.php` либо коммитится владельцем, либо уходит в
  `.gitignore` (тогда `ChangedFiles::filterIgnored()` его исключит через `git check-ignore`).
  Точечные `--filter`-прогоны затронуты НЕ бывают (изолированный тест-файл реплеится стабильно —
  проверено 3× подряд).
- **Инвалидация** — по git-диффу рабочего дерева относительно последнего записанного графа;
  структурный fingerprint игнорирует чисто косметические правки (комментарий, пробелы) — 0
  незакэшированных тестов на `pint --dirty` без смысловых изменений.

## `--agent`

Выполняет произвольный PHP-сниппет в реальном тестовом контексте (наследует `tests/Pest.php`:
`RefreshDatabase`, `Tests\TestCase`, гейт `TestEnvironmentGuard`) без создания постоянного файла:

```bash
docker exec agelto_app ./vendor/bin/pest --agent='expect(true)->toBeTrue();'
```

- Не заменяет постоянный тест (D10) — только разовая проверка «работает ли то, что я сейчас
  написал»; на изменённое поведение всё равно нужен тест в `tests/`.
- Реальный LLM-вызов внутри сниппета (не `Tests\Support\Doubles\FakeLlmProvider`) —
  блокирующее замечание на тех же основаниях, что и обычный тест.
- `php artisan boost:install` не запускался при установке плагина (D9): Laravel Boost в проекте —
  только MCP, не источник guidelines; правила `--agent` живут в
  `.claude/rules/agelto-reviewer-overrides.md`, а не в сгенерированном Boost-контуре.

## Agent-optimized output

`laravel/pao` — единственный formatter agent output. Docker Make targets forward Codex, Claude,
Cursor and `AI_AGENT` markers into `app`: Pest, Paratest and PHPStan return one structured JSON
result in agent mode. `php artisan test` delegates to Pest and therefore has the same JSON contract;
other Artisan commands retain normal human text output. Не добавлять второй output-wrapper и не
считать presence env marker доказательством — contract проверяет schema фактического stdout на
success и failure.

## Стиль кода

`docker compose exec app ./vendor/bin/pint --dirty --test` — `--dirty` не видит untracked-файлы,
для новых файлов передавать явный список путей.

## Смежное

- `../getting-started.md` — первый прогон тестов после клонирования.
- `db-test-preflight` (skill) — Postgres-контейнер жив, `agelto_test` существует, миграции применены.
