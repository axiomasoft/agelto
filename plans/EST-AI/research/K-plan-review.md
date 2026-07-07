# Отчёт K — adversarial-ревью плана EST-AI (2026-07-07) и статус фиксов

24 находки; все blocker/major и большинство minor исправлены в плане той же датой (см. Update Log). Ниже — сжатый список с резолюцией (для истории; исполнителю руководствоваться ТЕКУЩИМ текстом items).

1. [blocker] P8.1 сидер писал is_protected до создания колонки (P8.2) → ФИКС: миграция is_protected перенесена в P8.1.
2. [blocker] Никто не диспатчил ScreeningJob после prepare → ФИКС: P4.2 Scope + сквозной тест (за флагом ai.screening.enabled).
3. [blocker] P7.4 manual-импорт против NOT NULL prc_documents.procurement_id → ФИКС: D22, nullable в P1.4.
4. [blocker] Конфликт с rustling-jingling-quiche (роспуск docs/specs/v2) + битая ссылка «§4» → ФИКС: D23 (specs живёт до конца EST-AI), ссылки исправлены.
5. [major] 5 устаревших ссылок «удаление Old/ = P8.2» → ФИКС: заменены на P11.1.
6. [major] P6.1 reuse file handle со скрининга (там только срез 4 стр.) → ФИКС: extraction всегда грузит полный prepared-файл; reuse только для документов ≤ N страниц.
7. [major] shadow-анализ vs partial unique и отсутствие purpose у analyses → ФИКС: D24, колонка is_shadow в P9.1 вне unique-предиката.
8. [major] re-run «заменит по ordinal» не работал (unique включает analysis_id) → ФИКС: D24, P9.2 удаляет сметы superseded-анализа транзакционно при persist нового.
9. [major] Дети архива без дедуп-ключа и с неясным статусом → ФИКС: P4.2 — синтетический external_id `{parent_version_id}:{путь}`, дети создаются сразу downloaded.
10. [major] P4.3 «статус при постановке» без промежуточного статуса в enum → ФИКС: новый статус не вводим; анти-редиспатч через ShouldBeUnique (uniqueId=document+stage) + status-guard.
11. [major] P5.1 противоречие по не-PDF → ФИКС: слайсер только PDF; изображения целиком; fail — только нечитаемые форматы.
12. [major] Якорение row_index и семантика incomplete_items в row-range не зафиксированы → ФИКС: обязательные разделы спеки 08 в P1.2.
13. [major] После partial unique firstOrCreate находил superseded → ФИКС: P9.1 Scope — retrofit процессоров на scope active().
14. [minor] P8.1 пропускал screening:global → дописан (все 7 значений enum).
15. [minor] «наивысший приоритет» маршрута двусмысленно → «минимальное значение priority».
16. [minor] Неоднозначный путь v1-prompts → закреплён plans/EST-AI/research/v1-prompts/**.
17. [minor] Косметика счётчиков в plan.md/P12.1 → исправлено.
18. [minor] spatie/laravel-data «преобразователи в JSON Schema» — нативных нет → формулировка P2.3 смягчена (Reflection-маппер — основной путь).
19. [minor] env-ключи и docker-extensions вне scope → добавлено в P3.2 (env.example) и P4.2 (gd/zip/gd в образе — проверка+добавление).
20. [minor] Документы вечно screened при выключенном/упавшем extraction → P7.3 мониторит screened; P6.1 финализирует документ failed при failed_permanently анализа.
21. [minor] cache-hint в двух DTO → только LlmOptions.cacheHandle.
22. [minor] Коррекции «после step-промтов» vs «вне кэша» → коррекции едут в user-части вызова.
23. [minor] Обратный маппер Eloquent→DTO для gold никем не создавался → включён в Scope P9.3.
24. [minor] Абсолютный путь sova в P10.1 → помечен optional (основной источник — research/H).

Проверено ревьюером и НЕ подтвердилось: все Old/-первоисточники, спеки, миграции, enum'ы, doubles, sova существуют; partial unique не конфликтует с FK; AiDocumentType = спека 03 §3.4; счёт items сходится; bcmath в образе есть.
