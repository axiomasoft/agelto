---
audience: dev
type: howto
status: draft
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
трогать; решения — план `2026.08.01-L13-PEST5`, D6/D7).

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
- **PCOV выключен по умолчанию** (`pcov.enabled=0` в `docker/php/conf.d/pcov.ini`, zero-cost) —
  движку Tia он не нужен для реплея, только для (пере)записи графа по изменённым файлам. Включать
  разово через `-d`, **и обязательно указывать `pcov.directory` тем же флагом** — иначе
  Pest 5 `PcovRestarter` перезапускает процесс с `-d pcov.directory=<root>`, теряя переданный
  `pcov.enabled=1` (в перезапущенном процессе он снова читается из `pcov.ini` = `0`):
  ```bash
  docker exec agelto_app php -d pcov.enabled=1 -d pcov.directory=/var/www/html \
      ./vendor/bin/pest --tia --fresh --coverage
  ```
  Без `--coverage` Pest не активирует свой внутренний флаг `Coverage::$coverage` — простого
  `-d pcov.enabled=1` недостаточно даже для первого прогона.
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
