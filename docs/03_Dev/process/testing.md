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
```

Точечный прогон одного класса/фильтра — `make artisan ARGS="test --filter=<Subject>"`
(**один `--filter` за прогон** — второй игнорируется Symfony Console, не Laravel-баг).

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

`tests/Pest.php`: `pest()->tia()->always()->locally()` — движок включён всегда локально
(`->always()`), `--filtered` и CI-baseline (`->baselined()`) не заводим (`.ai/guidelines/*` не
трогать; решения — план `plans/archive/2026.08.01-L13-PEST5.md`, D6/D7).

- **Обычный прогон в разработке** — просто `docker exec agelto_app php artisan test` /
  `./vendor/bin/pest`: Tia реплеит закэшированный результат для незатронутых тестов (единицы
  секунд вместо ~35 с полного сюита), прогоняет заново только затронутые правкой.
- **Приёмочный прогон item'а/фазы плана — ТОЛЬКО `--no-tia`** (D8): реплей — инструмент цикла
  разработки, не инструмент сдачи; правило продублировано в
  `.claude/rules/agelto-reviewer-overrides.md`.
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

## Стиль кода

`docker compose exec app ./vendor/bin/pint --dirty --test` — `--dirty` не видит untracked-файлы,
для новых файлов передавать явный список путей.

## Смежное

- `../getting-started.md` — первый прогон тестов после клонирования.
- `db-test-preflight` (skill) — Postgres-контейнер жив, `agelto_test` существует, миграции применены.
