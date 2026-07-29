# HANDOFF — 2026-07-29 — after P1

**Next:** `/task:plan-run 2026.07.28-EST-ARCH P2.1` — гейт-item владельца (контракт-документ с
открытыми design-решениями, сейм типа документа), `Exec = manual`.

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.1 — реестр анализаторов/сейм типа документа: design-item, замораживает контракт для остальных пяти items P2 |

```
/task:plan-run 2026.07.28-EST-ARCH P2.1
```

**Итог закрытия фазы P1 (`plan-close`, 2026-07-29, снят реопен D97).** Фаза P1 — `🟠 Done with
deviations`, 4/8. Раскладка: 4×🟢 (P1.1, P1.2, P1.3, P1.4) + 4×🟠 (P1.5, P1.6, P1.7, P1.8). Предмет
фазы не пересдавался этой сессией — чинился только слой закрытия (`## Phase Handoff` фазы
перезаписан целиком со снятием маркера `> УСТАРЕЛ`, Status Board сведён, Task Contract у всех
восьми items даёт `allow`/`allow` по ретро-прогону предыдущего реопена, `plan-lint` — 0 ERROR /
0 WARN на итоговом дереве).

**Done:** канон имён AI-контура (`root/naming-canon.md`), контракт оси типа документа
(`root/document-type-axis.md`), контракт единого контура обучение↔боевой разбор
(`root/document-contour.md`), схема восьми AI-таблиц по контракту, домен `Llm` (`llm_*`) вынесен из
`Procurement`, оси предмета `prc_ai_prompt_suggestions`. Полные факты — Completion Notes P1.1–P1.8
в `phases/P1.md`; агрегат Known Deviations — `phases/P1.md` → `## Phase Handoff`.

**Remaining:** 29 items ⬜ Not started. Порядок: P2 (6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4).
Батчи B1/B2 и три оставшихся гейта владельца (P2.1, P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plans/2026.07.28-EST-ARCH/plan.md` §2 (правила
исполнения) и §5 (**D1–D99**) · **`root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md` — ЗАМОРОЖЕНЫ** · `research/02-schema-impact-map.md` · `roadmap.md` ·
`phases/P1.md` (`## Phase Handoff` + Completion Notes P1.1–P1.8) ·
`brief/{00-brief,01-refinements}.md` · `open-questions.md`.

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от закрытия: 0**
(на итоговом дереве, после перезаписи `## Phase Handoff` и Status Board).

**Open risks (перенесены из закрытой фазы, наследуются P2+):**
- **Четыре material-отклонения фазы (P1.5/P1.6/P1.7 — дифф вне `Files`; P1.8 — закрытие поверх
  красной Validation 8)** — каждое разобрано в Known Deviations item'а и агрегировано в
  `phases/P1.md` → `## Phase Handoff`.
- **Журнальный разрыв P1.5–P1.8 невосполним (D99):** машинной истории «кто/чем/под каким маршрутом»
  для четырёх items не существует, back-fill запрещён; маршруты названы прозой в отчётах items.
- **Дыра протокола (D98, PROMOTE-CANDIDATE):** `plan-exec.md` разрешает «предупреди и пропусти» И
  журнал, И `plan-lint`, И Task Contract при незаданном `${CLAUDE_PLUGIN_ROOT}` — правка в
  репозитории `swissknifeman`, вне границ этого плана.
- **Практика D89-исхода для гейтов-сторожей инварианта (P1.6/P1.8) без D-записи** — не решена вне
  скоупа реопена: предмет для следующего касания `plan-design`.
- **`config/ai.php` несёт докблочные упоминания старых имён таблиц** — правится при следующем
  касании файла (P1.5 Open risks).
- Три строки Pending Work про `CLAUDE.md` живут до P7.4 (D60/D64). Боевой прогон P5.2 — за
  владельцем (D94).
- **Периметр coupling для строковых значений enum'ов** — держать в уме на P2.5 (расщепление
  `PromptStep`/`LlmPurpose`): проверять не только call-site'ы кейса, но и голые литералы значений в
  тестовых фикстурах (находка P1.7).
- **Периметр `Files` собирается не только от ЧИТАТЕЛЕЙ колонки (D96), но и от ПИСАТЕЛЕЙ новой
  связи и от фабрик моделей** — держать при сборке `Files` items P2+ (находка P1.6, F6).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6, впредь
  требуется у P2.1, P3.1, P4.2 · `pint --dirty` автофикс — точечными Edit-правками (host-запуск
  заблокирован permission-гейтом сессии, P1.5) · `docker exec agelto_app` вместо
  `docker compose exec app` при сбое интерполяции `.env` (P1.7, не воспроизвелось на P1.8) ·
  ретро-прогон границ Task Contract за закрытые items (реопен D97) — легален как ВОССТАНОВЛЕНИЕ
  факта, но не как способ закрывать items впредь
- deferred: ревизия формы оставшихся гейтов — снята D89 · BigQuery-адаптер биллинга (D34) ·
  HITL-правка эталона (D48) · пере-замер F1 боевым прогоном (D52) · остаточная дупликация
  `estimate:dataset:import` (D57) · перенос дрейфа в `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант
  «во всём сервисном слое нет прямых обращений к `LlmCall`» — в план НЕ вводится (D70/D79) ·
  переезд экранов транспорта из `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) ·
  expression-индексы по `type_profile` — не заводятся до 10 000 строк реестра (§6.4) ·
  эксплуатационный отбор «какие документы пускать в дорогой разбор» — вне схемы (D92) ·
  `resources/ai/prompts/README.md` пример front-matter без `document_type` — правится при следующем
  касании промтов · `config/ai.php` докблочные упоминания старых имён таблиц — правятся при
  следующем касании файла · канонизация D89-исхода для гейтов-сторожей инварианта (см. Open risks) —
  предмет следующего `plan-design` · правка escape-hatch `plan-exec.md` под `topology.json` (D98) —
  репозиторий `swissknifeman`, вне этого плана
- open_questions: в границах плана — НЕТ. OQ-1…OQ-14 закрыты. Вне плана: P7.6 плана
  `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга) — тратит реальные деньги, несёт два гейта
  владельца
