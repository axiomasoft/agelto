# HANDOFF — 2026-07-07 — after P0.0

**Next:** /task:plan-exec EST-AI P1.1
**Done:** план создан и прошёл ПЯТЬ итераций владельца + два adversarial-ревью (research/K, research/L): 12 фаз, 40 items; разведки A–L в plans/EST-AI/research/ (Old/-процессы/промты/поля, состояние v2, Gemini-2026, аудит схемы БД, транспорт/обучение локальной LLM, структура доков + SOVA-референс, колоночный диф v1-БД, infra-практики, ревью плана K — 24 находки, pre-exec аудит L с внешней верификацией perplexity-web — 20 находок/4 HIGH, все вшиты); 36 решений в plan.md §5. Routing-отклонение: P12 — только opus/Fable. Закрытых items нет.
**Remaining:** все фазы P1–P12; закрытых items нет.
**Sources of truth:** plans/EST-AI/plan.md · plans/EST-AI/phases/P1.md · docs/specs/v2/{01-database,03-document-pipeline,04-llm-providers,06-roadmap}.md · plans/EST-AI/research/*.md (особенно L — лимиты провайдера/EOL/lifecycle)
**Open risks:**
- **Модели EOL** (D27): линейка 2.5 умирает 2026-10-16 (ai.google.dev/deprecations) — тюнить дёшево сейчас, но config несёт eol_date+successor, P7.3 алертит; при затяжке плана до осени переключить сиды на 3.x (раскомментировать строку-преемника).
- тексты промтов v1 могут быть недоступны (P1.1 — живая v1-БД/дамп/Old/database/database.sqlite; эскалация владельцу).
- typed-поддержка Gemini cachedContents в Prism v0.100.x подтверждена (research/L finding 8), но refresh/delete/list кэшей могут отсутствовать — эскалационный HTTP-клиент в P3.2 в силе; structured output Prism — свой Schema DSL, нужен маппер (P3.2).
- правка опубликованных миграций (P1.4/P8.1/P9.1) допустима ТОЛЬКО пока данных нет — ЯВНЫЙ гейт «окружение dev/test» добавлен; прод/данные → alter-миграции.
- docs/specs/v2 живёт до конца плана (D23) — не исполнять роспуск specs из черновика rustling-jingling-quiche параллельно.
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: Batch API (−50%, D7); RAR/7z; LibreOffice-конверсия xlsx как замена Mpdf (D35, рычаг); Ghostscript-даунскейл скан-PDF (finding 20, рычаг); generic ai_extractions до второго экстрактора (D13); контакты-сущность под CRM; полный 9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip (P7.5 Pending); прод-хранилище/cron бэкапов и MinIO ILM-раскатка (P7.7 Pending); shadow CI-гейт после реального голдена; калибровка порогов P6.4 (окно/страницы) и confidence-borderline (P5.2); переключение сидов на 3.x-модели (D27, по приближении EOL); Langfuse/registry промтов
- open_questions: доступ к прод-БД v1 для выгрузки plt_prompt_rules (P1.1); подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip (P7.5); хост/расписание бэкапов и pgBackRest-vs-pg_dump (P7.7); дата целевого переключения на 3.x-модели (D27)
