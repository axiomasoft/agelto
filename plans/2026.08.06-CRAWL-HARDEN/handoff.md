# HANDOFF — 2026-08-07 — after P2.1

**Next:** следующий item фазы P2 по цепочке данных (карта → фильтр → билдер → форма) — **P2.2**
(поле `okpd2Codes` в `EisSearchFilter` и обязательность скоупа профиля).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — §3 Routing: `implementation/high`, `Exec = manual` (structural gate на
`EisSearchFilter`/`Crawler::saving()`, cena ошибки — тихий пропуск профиля-«всё подряд») |
| Context | continue (/clear) — ручной item |
| Суть | P2.2 — обязательность ОКПД2 для fz44-профилей + правка сида fresh-профиля (D18, `research/03-p2-okpd2-design.md` §2.3) |
| Команда | `—` (Exec = manual, нет single-command эквивалента) |

```
/model opus
/effort high
/task:plan-run 2026.08.06-CRAWL-HARDEN P2.2
```

**Done:** P2.1 закрыт 🟠 Done with deviations — таблица `prc_okpd2_nodes` (миграция
`2026_08_07_100078`), модель `Okpd2Node`, фабрика, read-репозиторий `Okpd2NodeReadRepository`
(`externalIds()`/`selectableNodes()`), датафайл `database/data/eis-okpd2-nodes.json` (34 узла,
`coverage: full` — источник `artifacts/eis-okpd2-map.json` P1.2 уже существовал), идемпотентный
сид `Okpd2NodeSeeder` в `DatabaseSeeder` (между `StatusMappingSeeder` и `CrawlerProfileSeeder`), два
тест-файла (7 тестов, 10 assertions, зелено). `plan-lint --baseline HEAD` — 0/0, новых от диффа
item'а 0. Детали — `phases/P2.md` P2.1 Completion Notes.

### P2.1 — карта «код ОКПД2 → id НСИ» (item-коммит `2e36ad56`)

1. **Датафайл ПОЛНЫЙ, не частичный**: к моменту исполнения `artifacts/eis-okpd2-map.json` (P1.2)
   уже существовал в git (P1 терминальна) — 34 узла скопированы без правки значений
   (Implementation Rule 5), `coverage: "full — …"`.
2. **Material-отклонение**: item-коммит несёт дифф ВНЕ `Files` item'а —
   `tests/Unit/Architecture/DomainLayoutTest.php`. Подобласть `App\Repositories\Procurement\Platform`
   (введённая самим Scope Included п. 4 этого item'а) не входила в реестр
   `alignedDomainLayoutLayers()`, гейт `DomainLayoutTest` падал. Правка — механическая регистрация
   (одна строка массива), зеркалит уже существующую `App\Models\Procurement\Platform`. Отсюда
   статус `🟠`, а не `🟢` (Status-rule: «дифф вне Files» material независимо от корректности диффа).
3. **Окружение контейнера несло два класса предсуществующих дефектов**, не относящихся к диффу
   item'а: (а) `storage/framework/testing`, `storage/logs/laravel.log` были `700`/без записи для
   `www-data` (владелец `uid 1000` vs рантайм `uid 33`) — починено `chmod -R o+rwx` (не файл, не
   входит в `Files`, ничего трекаемого не менялось), разблокировало 208 тестов; (б) 18 оставшихся
   падений full-прогона (`storage/app/tmp_extract/**` отсутствует, `ini_set('memory_limit', …)`
   запрещён в контейнере, часть `Prompt*`/`GeminiProxyRoutingTest`/`ContourTest`) — предсуществующий
   дефект окружения на файлах вне `Files` item'а (`Prepare/**`, `Screening/**`, `Ai/**`, `Llm/**`);
   не чинился (Scope Excluded по объёму и по чужому Files-периметру), назван в Pending Work
   `phases/P2.md` P2.1.
4. **Посторонний дифф закоммичен отдельно, без правки содержания**: блок `## Audit P1 — 2026-08-07`
   + финальная запись `journal.jsonl` (продукт `plan-audit`, не закоммиченный прошлой сессией) —
   коммит `89e7f261`, ДО item-коммита P2.1. Каталог `plans/archive/2026.07.26-EST-TRAIN`/удаление
   `plans/2026.07.26-EST-TRAIN` НЕ трогался (чужой план, вне скоупа P2.1).

**Remaining:** 45 items из 51 (P2.1 закрыт). Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по
coupling-поверхности (D98); P8 — после всех схемных фаз P2–P6 и после терминальной P1.5 (D4/D83);
P9 — после P8 (D4). P2 исполняется вручную item-за-item (D23), следующий — P2.2 (см. Next выше).

**Sources of truth:** `plan.md` (Meta 0.14.0, §3 Routing построчно, Status Board, D1–D107,
`## 6. Update Log`, `## 7. Contracts`) · `phases/P1.md` (фаза терминальна 🟠, блок `## Audit P1 —
2026-08-07`, вердикт ATTENTION) · `phases/P2.md` (Phase Context/DoR + P2.1 полностью, P2.2–P2.6
скелеты DoR) · `research/03-p2-okpd2-design.md` (дизайн фазы P2) ·
`findings/P1-okpd2-probe.md`, `findings/P1-okpd2-map.md` + `artifacts/eis-okpd2-map.json` (34 узла,
источник датафайла P2.1) · `database/data/eis-okpd2-nodes.json` (новый, промоушен карты) ·
`open-questions.md` (Q9/Q10/Q13/Q15/Q16 — ни один не блокирует P2).

**Open risks (перенесены из P1, не пересматривались):**
- **вежливостный бюджет P1.5 замерен неполно** — правило для будущих живых item'ов (P2.5, P5.4)
  стоит ужесточить в скилле `eis-recon` (снимать только `ctx.request.get()`);
- **`Scope Included` P4.1 п. 7 противоречит D107** (F5 аудита P1): `retry_delay_ms = 500` плоской
  величиной против `supersedes D45`; синхронизацию делает `plan-design` ДО старта P4 (НЕ трогать в
  рамках P2);
- **два дефекта боевого кода из P1.3 не чинились**: плоский retry-backoff без `Retry-After` (адрес
  P4.1), `->lazy()` по мутируемой колонке в `DetailRefetchSelector::due()` (адрес P4.5);
- **`ProcurementStatus::Cancelled` недостижим** (P1.4) — правится в P6.1;
- **разбор карточки не различает «вкладки нет» и «данных ещё нет»** (P1.5, F3) — адресат P6.2;
- **615-ПП разбирается вхолостую** (P1.5, F5) — адресат P6.2;
- **суточная гранулярность даты (P1.1, F4) не была учтена при проектировании P3.3** — правка ТЗ
  через D-запись, вне скоупа P2;
- **18 предсуществующих падений полного прогона тестов** (окружение контейнера `app`,
  `storage/app/tmp_extract`, `memory_limit`) — не адрес P2, но следующий item, если он трогает
  `Prepare/**`/`Screening/**`/`Ai/**`/`Llm/**`, обязан назвать их заново, если не устранены раньше;
- ёмкость бюджета D45 и пороги P5 НЕ калиброваны (Q15); выборка «действительно строительная»
  доказывается только активной пробой P5.4 (D20);
- **третьего `plan-audit design` не будет** (потолок раундов объявлен аудитом захода 2 design);
  `plan-audit P1` (заход 1, режим фазы) — отдельный счётчик, потолка не имеет.

**Workarounds/Deferred/Open questions:** Workarounds — нет (см. п. 3 выше про chmod окружения —
это фикс среды исполнения, не workaround в коде). Deferred: как в предыдущем handoff (деплой,
ретеншн, `ref_okpd2` (Q13), ветка 223-ФЗ (Q10), стабильность id НСИ, закрытость перечней P1.4/P1.5).
Open questions: Q16 закрыт вердиктом P1.5. Q9, Q10, Q13, Q15 — `Exploration`, ни один не блокирует
P2.2.
