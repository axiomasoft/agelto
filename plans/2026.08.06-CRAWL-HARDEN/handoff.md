# HANDOFF — 2026-08-08 — after P4.8

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/high

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | high — proxy binding crosses three HTTP paths |
| Context | continue (reset the session context) — ручной item |
| Суть | Выполнить P4.2: привязать прокси к профилю и легализовать direct mode. |
| Команда | — |

```text
$ task:plan-run 2026.08.06-CRAWL-HARDEN P4.2
```

**Done:** P4.8 закрыт 🟠: seed реестра сохраняет UUID при повторе, core boundary больше не знает
type-specific `Estimate`, а все тесты fail-closed для HTTP.

**Remaining:** P4.2–P4.6; отдельный fix-item нужен для 12 предсуществующих Pint-нарушений вне
P4.8.

**Sources of truth:** `plan.md` D119 и §3 Routing · `phases/P4.md` P4.8 ·
`findings/P4.8-validation-gates-2026-08-08.md` · `research/13-p4.8-validation-gates.md` ·
`journal.jsonl`.

**Open risks:** Полный Pint остаётся красным на 12 style issues вне P4.8; remediation — отдельный
узкий fix-item, не расширять P4.2.

**Workarounds/Deferred/Open questions:** workarounds — `PoliteThrottle` и `crawler.throttle.*`
до P4.4; deferred — Pint cleanup; open_questions — —.
