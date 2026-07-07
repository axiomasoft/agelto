# HANDOFF — 2026-07-07 — after P0.0

**Next:** /task:plan-exec EST-AI P1.1
**Done:** план создан и прошёл три итерации владельца: 12 фаз, 37 items; разведка Old/ (A–C), состояние v2 (D), research Gemini (E), аудит схемы БД (F), транспорт/обучение локальной LLM (G), структура документации (H, вкл. дельту по референсу SOVA и единую доку D20) — в plans/EST-AI/research/; 21 архитектурное решение в plan.md §5. Routing-отклонение: P12 (AI-контекст: скилы + CLAUDE.md-хаб) — только opus/Fable.
**Remaining:** все фазы P1–P12; закрытых items нет.
**Sources of truth:** plans/EST-AI/plan.md · plans/EST-AI/phases/P1.md · docs/specs/v2/{01-database,03-document-pipeline,04-llm-providers,06-roadmap}.md · plans/EST-AI/research/*.md
**Open risks:** тексты промтов v1 могут быть недоступны (P1.1 — нужна живая v1-БД или дамп; эскалация владельцу); typed-поддержка Gemini cachedContents в Prism — сверить перед P3.2 (research/G §Расхождения п.4); правка опубликованных миграций в P1.4/P8.2/P9.1 допустима только пока данных нет — если ветка уйдёт в прод раньше, эти items требуют пересборки на alter-миграции; CLAUDE.md ссылается на ещё не существующие docs/02_Workflow//03_Dev — создаются P10.1 (пересечение с черновиком plans/rustling-jingling-quiche.md — совместимо, см. research/H).
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: Batch API (−50%, D7); RAR/7z-стратегии; generic ai_extractions до второго экстрактора (D13); контакты-сущность под CRM (аудит F ось 5); полный 9-префиксный ренейм (D11 — отклонён); shadow CI-гейт после реального голдена; калибровка порога P6.4; Langfuse/registry промтов (research/H §4)
- open_questions: доступ к прод-БД v1 для выгрузки plt_prompt_rules (P1.1); подтверждение владельца перед git rm Old/ (P11.1)
