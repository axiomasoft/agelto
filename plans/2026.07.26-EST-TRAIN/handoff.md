# HANDOFF — 2026-07-28 — after P5.3

**Next:** exec-items: task:plan-exec 2026.07.26-EST-TRAIN P5.4 P5.5 P5.6

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — прописанная реализация поверх зафиксированных решений (D5, D19, D44/D45) и готового `ai:gold:build` P5.3, открытых design-решений нет |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Остаток батча B3: `--screening` ветка `ai:gold:build` + §8.5 спеки (P5.4) → `ai:gold:import` (рабочие копии, датасет, P5.5) → `ai:eval:run --task=screening` + `ScreeningEvalMetrics` (P5.6). **Известный блокер входа P5.4** — см. Open risks ниже, читать ПЕРЕД стартом |

```
/task:plan-exec 2026.07.26-EST-TRAIN P5.4 P5.5 P5.6
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations (2/4 🟢, 2/4 🟠). P3 — 🟠 Done with deviations,
терминальна (2/9 🟢: P3.8, P3.9; 7/9 🟠: P3.1–P3.7). P4 — 🟠 Done with deviations, терминальна
(3/5 🟢; 2/5 🟠: P4.4, P4.5). P5 — 🟡 In progress (4/7): P5.1/P5.2/P5.7 — 🟢 (см. предыдущие
handoff); **P5.3 🟢** — `GoldBuilder` (диспетчер `match` gsfx/xlsx-xls, тот же алгоритм, что зонд
P5.7) + `GoldBuildCommand` (`ai:gold:build --code=* --gold-dir= --manifest= --update-manifest
--dry-run`); реальный прогон корпуса: `построено 6 · пропущено 14 · понижено 4 · отклонено 0`
(6 построено — `EST-GSFX-LSR-001` + пять P5.7 `pass by profile`; 4 понижено —
`EST-XLS-KS3-001`/`EST-XLSX-KS2-002`/`EST-XLSX-LSR-002`/`EST-XLSX-LSR-003`, причины из
`research/09` воспроизведены отчётом); байт-идентичность повтора подтверждена;
`--update-manifest` меняет РОВНО четыре поля (`gold_path`/`expected_rows`/`expected_estimates`/
`gold_source`); `ai:fixtures:import`+`ai:fixtures:verify` зелены (24/24, 0 расхождений);
`research/10-gold-corpus-report.md` собран; 6/6 Pest-тестов `GoldBuildTest` — коммит `924fc1e`.

**Remaining:** P5.4–P5.6 (батч B3, остаток) — входы готовы (`ai:gold:build` P5.3, реестр с
`doc_type`/`class`, `ScreeningResult`/`AiDocumentType`). P6–P9 — не начаты (0/3, 0/6, 0/5, 0/6).
Состав плана — 49 items.

**Open risks:**
- **БЛОКЕР ВХОДА P5.4 (обнаружено этим заходом, не устранено кодом — вне Scope P5.3):** реестр
  `prc_ai_fixtures.doc_type` пуст (`NULL`) абсолютно у ВСЕХ 13 негативов корпуса — не спорадически,
  а по всем записям сразу (проверено `Fixture::query()->get(['code','doc_type'])`). P5.4
  Implementation Rules требуют: «отсутствие `doc_type` у негатива — ОТКЛОНЕНИЕ записи… реестр
  обязан его нести» — и явно запрещают исполнителю проставлять значение самому («не хватает
  кейса — эскалация, а не новый case «по дороге»»). Разбор по каждому негативу
  (`research/02-fixture-shortlist.md`): только `NEG-DOCX-CONTRACT-001` однозначно ложится на
  существующий `AiDocumentType::ContractDraft`; для остальных 12 (чертежи, фото, кабельный
  журнал, акты АОСР, расчёты объёмов, обоснования НМЦК) закрытый enum
  (`Estimate`/`TechSpec`/`ContractDraft`/`Protocol`/`Unknown`) не даёт точного соответствия —
  реалистичный gold, вероятно, `Unknown` почти для всех, но это решение владельца по вкусу самого
  ТЗ («спорный случай — Pending Work, решает владелец»), не самодеятельность исполнителя.
  **Следующий item ОБЯЗАН начать с этого**: либо P5.4 идёт штатным путём (реестр отклоняет все 12
  записей индивидуально, `screening.json` строится только на 12 из 24 эталонов, item закрывается
  🟠 Done with deviations с явным перечнем в Pending Work — это НЕ архитектурный тупик, а
  предусмотренное самим ТЗ поведение), либо владелец сначала заполняет `doc_type` реестра (правка
  вне Scope P1.3/P2, отдельный item/D#) до старта P5.4 — выбор пути называет исполнитель P5.4 по
  факту, эскалация п.5 не требуется (Code Guidance item уже прописывает обработку).
- **Инфраструктура (починено этим заходом):** каталог `plans/2026.07.26-EST-TRAIN/` не нёс
  default ACL для `www-data` — команды из контейнера `agelto_app` не могли писать `research/*.md`.
  Исправлено `setfacl -R -m u:www-data:rwx` + `-d` на весь каталог плана; повторной починки не
  требуется.
- **`docker compose` недоступен в сессии**: падает на интерполяции `MINIO_ROOT_PASSWORD`; обход —
  `docker exec agelto_app …` на уже поднятый контейнер той же compose-стопки.
- Посторонний незакоммиченный дифф вне items P1–P5.3 (без изменений этим заходом): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- Остаточные WARN плана (`plan-lint`) — счёт с якорем и атрибуцией K в Completion Notes P5.3
  `phases/P5.md`.
- `phpstan-baseline.neon` правится вне `Files` — кандидат в отдельный item `@property`-аннотаций.
- Docs-sync follow-up (унаследован от P3) и долг детерминированного доопределения
  `prc_estimate_totals.kind` по `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — P5.1–P5.7 (и P5.3) исполняются без него; OQ-4 фазу не блокирует.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (P5.3 закрыт, P5.4 next) ·
`plans/2026.07.26-EST-TRAIN/research/09-gold-grid-criterion.md` (вердикты, вход P5.3) ·
`plans/2026.07.26-EST-TRAIN/research/10-gold-corpus-report.md` (сводный отчёт корпуса, P5.3) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` (49 строк, батч B3) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
