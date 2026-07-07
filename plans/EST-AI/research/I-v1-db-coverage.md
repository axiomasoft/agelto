# Отчёт I — колоночный диф БД v1 → v2 (финальная проверка перед удалением Old/)

Источники: Old/database/migrations/** (24) + Old/Modules/Sys/database/migrations/** (2) против database/migrations/** (47). Сверено построчно с research/tz-v2/v1-columns.md и docs/specs/v2/07-carryover-from-v1.md — обе записки точны.

## Вердикт по группам (a=прямой эквивалент, b=jsonb, c=нет покрытия, d=осознанно неактуально)
1. **Заказчики**: ord_customers → org_organizations — полностью (a); v2 богаче (uuid, eruz_number, region_code, attributes).
2. **Закупки**: ord_orders + plt_crawler_links → prc_procurements (+registries/revisions/fetches) — полностью (a): reg_number→source_number, edition_number→revisions.source_edition, статусы, цены, все даты, okved+additional, detail_url, error-блок→detail_*, crawler_run_id→revisions/fetches. v2 богаче (ikz, etp_number, advance_percent, source_guid, first/last_seen_at, ignored_at).
3. **Лоты/позиции**: ord_lots→prc_lots (dirty_data→attributes (b)); ord_purchase_objects+ord_lot_infos→prc_items (okpd2→classifier_code+okpd2_ref_id; additional_info→characteristics; строковые числа lot_infos — (d) техдолг не наследуем). v2 добавляет ktru_ref_id, position_number.
4. **Сметы**: в v1 БД смет НЕ БЫЛО (только DTO-схема в коде + неиспользуемый ord_purchase_objects). v2 материализует и превосходит: passport jsonb, raw_result(+ref) — эталон для дообучения, base_year, price_level, validation_status, rows_expected/extracted, items.code/attributes/row_index, resources.overhead, totals.parent_id. Доменных потерь нет.
5. **Документы/AI**: attachments+document_analysis → documents+versions+ai_analyses — полностью (a), morph→явные FK, fingerprint→content_hash (сильнее), cache_*/rules_hash/lease/parent_id(мульти-смета→несколько estimates+progress). analysis_responses → llm_calls+analyses: prompt→request_ref+prompt_version_set, data/state→result/progress (b), токены + cached_tokens+cost_micros (a+). plt_prompt_rules→prc_ai_prompts — точное (a). analysis_logs→operation_logs+llm_calls (d). v2 добавляет dataset_examples/providers/routes/eval_* — инфраструктура дообучения.
6. **Краулинг**: platforms/regions/crawlers/runs/analytics/ignore_links/sys_proxies/sys_logs → prc_* — (a); курсорные поля runs → cursor jsonb (b); partial-unique WHERE status='running' ВОСПРОИЗВЕДЁН (crw_runs_crawler_id_running_unique) ✓; proxy pivot/справочник сервисов (d); v2 добавляет prc_fetches (пофетчевый аудит).
7. **Гео/media/users** — (d): гео заменено region_code + отложенный ГАР; media → storage_path; users/2FA свои.

## Потери (c) — ВСЕ инфраструктурные, не доменные
1. sys_logs.ip_address — мусор/low.
2. plt_document_analysis.options(json) — вход-конфиг анализа, в v2 из routes/prompts; почти не заполнялось — low.
3. plt_crawlers.state(json) crawler-level — состояние ушло в runs.cursor — low.
4. sys_proxies.last_error снимок — покрыт историей prc_proxy_checks.error — negligible.
ВЫВОД: доменных потерь нет; ничего для обучения LLM не теряется.

## Артефакты с ДАННЫМИ в Old/ (гейт перед rm)
- **Old/database/database.sqlite (86 KB, НЕ в git!)** — локальная dev-БД, может содержать реально собранные links/analyses; при rm пропадёт безвозвратно. ПРОВЕРИТЬ содержимое перед удалением (sqlite3 нет в окружении — через artisan/вручную).
- Old/database/seeders/data/locations.zip (3.6 MB, в git) — датасет ФИАС/КЛАДР; гео отброшено, но референс переиспользуем (имена регионов по region_code) — сохранить копию при удалении.
- Old/database/seeders/data/countries.zip — тривиально.
- Других SQL/CSV/дампов в Old/ нет.

## Дополнение к 07-carryover-from-v1.md
§2/§3 дока корректны; сверх них: подтверждён partial-unique инвариант в v2; закрыты 4 (c)-хвоста; флаг на database.sqlite.
