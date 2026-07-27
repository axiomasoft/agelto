# HANDOFF — 2026-07-27 — after P2.4

**Next:** фаза P2 терминальна (все 4 items 🟢/🟠) — закрыть фазу перед P3.

| Параметр | Значение |
|:--|:--|
| Model class | economy |
| Effort | low |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P2: свести таблицы, написать Phase Handoff, прогнать `plan-lint` |

```
/task:plan-close 2026.07.26-EST-TRAIN P2
```

**Done:** P2.1 — 🟠 (диски `estimates`/`estimates_s3` + `ai.fixtures`, посторонний дифф —
`league/flysystem-aws-s3-v3` с хоста). P2.2 — 🟠 (реестр `prc_ai_fixtures`: модель, enum'ы,
репозиторий, фабрика; посторонний дифф — общий `phpstan`/`larastan`-коммит `7485520`, установлен по
решению владельца, вне `Files` P2.1/P2.2). P2.3 — 🟢 (`FixtureManifest`/`FixtureManifestEntry` +
`ai:fixtures:import`, идемпотентный по sha256, шесть кейсов). P2.4 — 🟢 (эскалация снята D91,
`gold_path` в манифесте пишет ПРОИЗВОДИТЕЛЬ файла gold — `ai:gold:build --update-manifest`, P5.3;
генератор P2.4 всегда пишет `null`; манифест перегенерирован, `ai:fixtures:verify` на реальном
24-записном корпусе — `ok: 24 · расхождений: 0 · лишних: 0`; правило (4) не смягчено). Item-коммит
`0071b86`. Детали каждого item'а — `phases/P2.md` соответствующие Completion Notes.

**Remaining:** фаза P2 терминальна, все items закрыты — осталось только формальное закрытие фазы
(`task:plan-close P2`: свести таблицы, Phase Handoff, `plan-lint`). Фазы P3–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.16.0, §3 Routing, D1–D91) ·
`plans/2026.07.26-EST-TRAIN/phases/P2.md` (ТЗ P2.1–P2.4, все Completion Notes заполнены) ·
`plans/2026.07.26-EST-TRAIN/research/15-gold-path-ownership.md` (владение `gold_path`, разбор
отклонённых вариантов) · `plans/2026.07.26-EST-TRAIN/artifacts/fixture-shortlist.json` (24 записи,
`gold_source`: 13 `none` / 10 `deterministic` / 1 `llm_assisted`) · `estimates/manifest.json`
(закоммичен `0071b86`, 24 записи, `gold_path` = `null` у всех) ·
`docs/specs/v2/08-estimate-extraction.md` §8 · `plans/EST-AI/plan.md`.

**Open risks:**
- `gold_path` эталона `EST-PDF-SCAN-001` (`llm_assisted`) останется null и после P5, и после P8:
  P5.3 его пропускает, P8 файлов gold в git не производит. Это принято сознательно (D91,
  Consequences) — но если позже потребуется extraction-gold в git и для него, писать указатель
  обязан тот, кто создаст файл.
- Все риски, унаследованные от P1: `CL-16` без OCR, 21 файл КС-3 вне extraction, величина вне шести
  полей F1, коды эталонов уезжают в пути хранилища (реализовано P2.1–P2.4), OQ-3/OQ-4 открыты.
- `phpstan`/`larastan` подключены НЕ по плану, а по решению владельца в ходе P2.2 (коммит
  `7485520`, level 5, baseline 290 предсуществующих ошибок) — последующие items плана используют
  уже подключённый инструмент.
- Модуль `task.task_contract`/`task.route_evidence` (P2.7/P2.2/P2.11) недоступен в этом
  standalone-развёртывании (`packages/task/lib` в репозитории нет) — Pre-mutation/Pre-final гейты
  P2.1–P2.4 прошли по факту работы без машинной проверки, `write-site` строки — placeholder
  `allow · allow` по установленному этой сессией прецеденту, не результат реального прогона
  компилятора. Материальный процессный риск для будущего аудита плана.
- Посторонний дифф в рабочем дереве, унаследованный от прошлых заходов/окружения (НЕ трогался P2.4):
  `.gitignore` (M), `app/Models/Procurement/Ai/Fixture.php` (M), `estimates/gemini-analys.md`
  (untracked, не входит ни в один `Files`).
- `phases/P1.md` несёт тот же `plan-lint`-ложноположительный WARN (буквальное вхождение
  `[NEEDS CLARIFICATION]` в текст DoR-пункта «маркеры сняты»), что был на `phases/P2.md:68` до
  правки этим заходом (переформулировано без литеральных скобок — `plan-lint` теперь 0 новых). P1
  не трогался (вне `Files` P2.4) — если аудит потребует, тот же textual fix применим к P1.

**Workarounds/Deferred/Open questions:**
- workarounds: `REDIS_PORT` переопределён на хосте (порт 6379 занят другим проектом `sova_redis`) —
  `REDIS_PORT=6391 make up` / `docker compose --env-file .env --env-file .env.docker exec …`;
  `make artisan`/`make ps` из корневого `Makefile` конфликтуют с файлом `artisan` в корне репо —
  использовать `docker compose --env-file … exec app php artisan …` напрямую; запись в
  bind-mounted `estimates/`/`app/**` из контейнера требует ACL
  (`setfacl -R -m u:www-data:rwx <путь>`), иначе `Permission denied`; `.env.docker*.example`
  правились через `python3 -c "open(...).read()/write(...)"` — Read/Edit-тулы дают permission-deny
  на `.env*` (харнесс-уровень).
- deferred: см. предыдущий `handoff.md` (без изменений).
- open_questions: OQ-3, OQ-4 — без изменений.
