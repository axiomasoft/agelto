# HANDOFF — 2026-07-28 — after P5.2

**Next:** run-items: task:plan-run 2026.07.26-EST-TRAIN P5.7

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — разметка девяти реальных форм доказывается адресом и цитатой; неверная привязка колонки становится «внешней истиной», против которой потом считается F1 |
| Capabilities | — |
| Context | same-session — item |
| Суть | Разметить профилем КАЖДЫЙ из девяти табличных эталонов первой волны, прогнать `artifacts/P5.7-profile-run.php`, вынести вердикт `pass by profile`/`downgrade` по каждому и дописать таблицу вердиктов + раздел «Понижения `gold_source`» в `research/09` |

```
/task:plan-run 2026.07.26-EST-TRAIN P5.7

РЕЖИМ: штатное исполнение по ТЗ item'а (P5.2 закрыт 🟢, машинерия и методика зафиксированы — НЕ трогать, D35)
ВХОД: plans/2026.07.26-EST-TRAIN/phases/P5.md → item P5.7 целиком · research/17-p5.2-form-profile.md §1/§3/§6 · research/01-corpus-analysis.md §5/§6 · research/07-multi-estimate-anchoring.md · estimates/manifest.json
СКОУП: 1) по каждому из девяти табличных эталонов класса est — прочитать реальные строки шапки/блока позиций зондом §6 research/17, написать estimates/gold/<CODE>.profile.json по дословной цитате; 2) написать artifacts/P5.7-profile-run.php (манифест → профиль → GridRecognitionGate → SheetGoldMapper+GoldValidator при pass) и прогнать через artisan tinker; 3) дописать в research/09 таблицу вердиктов (ПОСЛЕ раздела методики) + раздел «Понижения gold_source» + раздел «Что это значит для P5.3»; 4) дефект кода P5.2 (не «неудобство») — чинить с прогоном тестов P5.2 до зелёного и записью в Known Deviations
НЕ ТРОГАТЬ: пороги/правила ролей/контракт профиля P5.2 (правка только новым D#); запись *.gold.json, обход реестра, манифест (P5.3); эталоны классов NEG/.gsfx/PDF; простановка is_verified (P8.3); explicit_rows как способ добрать покрытие вместо описания правила формы
```

**Done:** P1 — 🟢 4/4. P2 — 🟠 Done with deviations (2/4 🟢, 2/4 🟠). P3 — 🟠 Done with deviations,
терминальна (2/9 🟢: P3.8, P3.9; 7/9 🟠: P3.1–P3.7). P4 — 🟠 Done with deviations, терминальна
(3/5 🟢; 2/5 🟠: P4.4, P4.5). P5 — 🟡 In progress (2/7): **P5.1 🟢** — `.gsfx` → gold §8.2 закрыт по
факту реального эталона; **P5.2 🟢** — профиль формы, `GridRecognitionGate` (четыре условия D117),
`SheetGoldMapper` (дословный §8.2), `research/09` (методика и пороги), 25 Pest-тестов — коммит
`ee0eb9d`.

**Remaining:** **P5.7** (профили девяти реальных эталонов, прогон, вердикты — исполняется ДО P5.3,
D118) → P5.3–P5.6 (сборка gold, screening-эталоны, датасет, ветка eval). P6–P9 — не начаты (0/3,
0/6, 0/5, 0/6). Состав плана — 49 items.

**Open risks:**
- **Порядок items фазы P5 не совпадает с номерами**: P5.7 исполняется четвёртым, между P5.2 и P5.3
  (D118). Продублировано в Status Board фазы, Phase Context, §3 Routing, `roadmap.md` и блокквотом
  в самом item'е — но исполнитель, идущий «по номерам», всё равно может уйти в P5.3 раньше времени.
- **Два внешних долга решения D117**: правило «gold дословен документу» (итог позиции НЕ переносится
  в позицию) обязано попасть во вход P7.1, «паспортные поля книжного gold пусты» — во вход P7.3;
  иначе метрика накажет модель за корректный вывод. Носитель — `research/09` §5 (уже записано).
- **`docker compose` недоступен в сессии**: падает на интерполяции `MINIO_ROOT_PASSWORD`; обход —
  `docker exec agelto_app …` на уже поднятый контейнер той же compose-стопки.
- **Эфемерные логи тулинга в bookkeeping-коммите**: буквальный `git add plans/<PLAN-ID>` затягивает
  `.claude/`/`.swissknife/` внутри каталога плана — стажировать явными путями.
- Посторонний незакоммиченный дифф вне items P1–P5 (без изменений этим заходом): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- Остаточные WARN плана (`plan-lint`, дерево закрытия P5.2 — 6 WARN / 0 ERROR): ложные срабатывания
  детектора маркеров незавершённости в DoR P1/P5 (самореферентная строка чеклиста, маркеров
  фактически 0) и заявленной оркестрации в DoR P1/P2/P5 (без `workflows/`-скрипта, D29) + одна
  запись Update Log длиннее 300 символов — детали и разбор K=1 см. phases/P5.md P5.2 Known Deviations.
- `phpstan-baseline.neon` правится вне `Files` — кандидат в отдельный item `@property`-аннотаций.
- Docs-sync follow-up (унаследован от P3) и долг детерминированного доопределения
  `prc_estimate_totals.kind` по `label`/`@Caption` (`research/06` §7) — владелец не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (боевой прогон `ai:eval:run` обеих задач гейтится
доступом к провайдеру) — P5.1–P5.7 исполняются без него; OQ-4 фазу не блокирует.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P5.md` (P5.2 закрыт, P5.7 next, порядок исполнения) ·
`plans/2026.07.26-EST-TRAIN/research/09-gold-grid-criterion.md` (методика и пороги, входные
зависимости P7.1/P7.3) · `plans/2026.07.26-EST-TRAIN/research/17-p5.2-form-profile.md` (разбор
эскалации, контракт профиля, зонды) · `plans/2026.07.26-EST-TRAIN/roadmap.md` (49 строк, P5.7 между
P5.2 и P5.3) · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
