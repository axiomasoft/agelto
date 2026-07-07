# HANDOFF — 2026-07-07 — after P0.0

**Next:** /task:plan-exec EST-AI P1.1
**Done:** план создан и прошёл четыре итерации владельца + adversarial-ревью: 12 фаз, 39 items; разведки A–K в plans/EST-AI/research/ (Old/-процессы/промты/поля, состояние v2, Gemini-2026, аудит схемы БД, транспорт/обучение локальной LLM, структура доков + SOVA-референс, колоночный диф v1-БД — доменных потерь нет, infra-практики observability/деплой/конкурентность, ревью плана — 24 находки, все blocker/major исправлены); 26 решений в plan.md §5. Routing-отклонение: P12 — только opus/Fable.
**Remaining:** все фазы P1–P12; закрытых items нет.
**Sources of truth:** plans/EST-AI/plan.md · plans/EST-AI/phases/P1.md · docs/specs/v2/{01-database,03-document-pipeline,04-llm-providers,06-roadmap}.md · plans/EST-AI/research/*.md
**Open risks:** тексты промтов v1 могут быть недоступны (P1.1 — живая v1-БД/дамп/Old/database/database.sqlite; эскалация владельцу); typed-поддержка Gemini cachedContents в Prism — сверить перед P3.2 (research/G §Расхождения п.4); правка опубликованных миграций (P1.4/P8.1/P9.1) допустима только пока данных нет — уйдёт в прод раньше → пересборка на alter-миграции; docs/specs/v2 живёт до конца плана (D23) — не исполнять роспуск specs из черновика rustling-jingling-quiche параллельно с EST-AI.
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: Batch API (−50%, D7); RAR/7z; generic ai_extractions до второго экстрактора (D13); контакты-сущность под CRM; полный 9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip (P7.5 Pending); shadow CI-гейт после реального голдена; калибровка порога P6.4; Langfuse/registry промтов
- open_questions: доступ к прод-БД v1 для выгрузки plt_prompt_rules (P1.1); подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5)
