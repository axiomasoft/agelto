# HANDOFF — 2026-08-07 — after P2.2

**Next:** P2.2 ЭСКАЛИРОВАН (🔴 Blocked) — исполнение остановлено на конфликте правил: структурный
гейт скоупа ломает 40 тестов в файлах вне `Files` item'а и делает форму Filament неспособной
сохранить fz44-профиль до P2.4. ТЗ правит `plan-design`, не исполнитель.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — редизайн границы P2.2/P2.4 и периметра `Files`, открытое design-решение |
| Context | NEW SESSION — шаг-не-item (`plan-design` читает план с диска заново) |
| Суть | Синхронизировать ТЗ P2.2 с фактурой: периметр `Files` (фикстуры краулера) + кто несёт поле ОКПД2 в форме |

```
/task:plan-design 2026.08.06-CRAWL-HARDEN P2.2
```

**Done:** P2.1 закрыт 🟠 Done with deviations (item-коммит `2e36ad56`) — таблица `prc_okpd2_nodes`,
модель `Okpd2Node`, фабрика, read-репозиторий `Okpd2NodeReadRepository`, датафайл
`database/data/eis-okpd2-nodes.json` (34 узла), идемпотентный `Okpd2NodeSeeder`. Детали —
`phases/P2.md` P2.1 Completion Notes.

P2.2 — работа выполнена, но НЕ закрыта и НЕ закоммичена (см. Open risks). Написано и лежит в
рабочем дереве: поле `okpd2Codes` в `EisSearchFilter` (+ `ALLOWED_KEYS`, `rules()` с
`required|array|min:1` и regex формы кода), контракт
`App\Dto\Procurement\Crawler\Contracts\RequiresScope` (новый, untracked), гейт `Crawler::assertScope()`
в `saving()`, оба сидируемых профиля ЕИС с `okpd2Codes: ['43']` (+ переписанный докблок
`seedFreshProfile()`), строка-нарушитель `okpd2` → `stageUnknown` в обоих тестах, шесть новых тестов
контракта. Целевой прогон `--filter='CrawlerFilterContract|CrawlerForm'`: `CrawlerFilterContractTest`
зелёный целиком (10 тестов), `CrawlerFormTest` — 2 падения по причине из вопроса 2 ниже.

**Remaining:** 45 items из 51 (закрыт только P2.1). P2.2 возвращается в очередь после редизайна.
Порядок жёсткий в трёх местах: P2 → P3 → P4 → P5 по coupling-поверхности (D98); P8 — после всех
схемных фаз P2–P6 и после терминальной P1.5 (D4/D83); P9 — после P8 (D4).

**Sources of truth:** `plan.md` (Meta 0.14.0, §3 Routing построчно, Status Board, D1–D107,
`## 6. Update Log`, `## 7. Contracts`) · `phases/P2.md` (P2.1 закрыт, **P2.2 — блок
`🔴 ESCALATION` + Pending Work с обоими вопросами и замерами**, P2.3–P2.6 скелеты DoR) ·
`research/03-p2-okpd2-design.md` §2.3/§2.4/§3.4/§5 · `findings/P1-okpd2-probe.md` §3 ·
`database/data/eis-okpd2-nodes.json` · `open-questions.md` (Q9/Q10/Q13/Q15/Q16 — ни один не
блокирует P2).

**Open risks:**
- **Рабочее дерево несёт НЕЗАКОММИЧЕННЫЙ дифф P2.2** по шести путям (`app/Dto/…/EisSearchFilter.php`,
  `app/Dto/…/Contracts/RequiresScope.php` — untracked, `app/Models/…/Crawler.php`,
  `database/seeders/…/CrawlerProfileSeeder.php`, `tests/Feature/Crawler/CrawlerFilterContractTest.php`,
  `tests/Feature/Filament/CrawlerFormTest.php`). С ним полный прогон красный (**48 failed / 165 passed**
  на подмножестве против **6 failed / 202 passed** на `HEAD` `32d51900`). Дифф сохранён СОЗНАТЕЛЬНО —
  редизайну не придётся переписывать реализацию; перед любой чужой работой в дереве его надо либо
  застэшить, либо доработать по решению `plan-design`.
- **Периметр `Files` P2.2 занижен**: 13 тестовых файлов вне него заводят fz44-краулеры с
  `filter = null` (вопрос 1 в Pending Work item'а).
- **`RegistryFactory` отдаёт код случайно** (`fake()->randomElement(['fz44','fz223'])`) — после
  включения гейта краулеры на дефолтном реестре становятся флаки. Адрес — тот же редизайн.
- **Форма Filament мертва для fz44 между P2.2 и P2.4** (вопрос 2, несущий).
- Перенесены из P1, не пересматривались: вежливостный бюджет P1.5 замерен неполно (ужесточить
  `eis-recon` перед P2.5/P5.4) · `Scope Included` P4.1 п. 7 противоречит D107 (синхронизация до
  старта P4) · два дефекта боевого кода из P1.3 (плоский retry-backoff без `Retry-After` → P4.1;
  `->lazy()` по мутируемой колонке в `DetailRefetchSelector::due()` → P4.5) ·
  `ProcurementStatus::Cancelled` недостижим → P6.1 · разбор карточки не различает «вкладки нет» и
  «данных ещё нет» → P6.2 · 615-ПП разбирается вхолостую → P6.2 · суточная гранулярность даты (P1.1
  F4) не учтена при проектировании P3.3 · ёмкость бюджета D45 и пороги P5 НЕ калиброваны (Q15) ·
  выборка «действительно строительная» доказывается только активной пробой P5.4 (D20) ·
  третьего `plan-audit design` не будет.
- **~19 предсуществующих падений полного прогона** (окружение контейнера `app`:
  `storage/app/tmp_extract`, запрет `ini_set('memory_limit')`; файлы `Prepare/**`, `Screening/**`,
  `Ai/**`, `Llm/**`, `CoreKnowsNoDocumentTypeTest`, `SchemaHardeningTest`) — к диффу P2.2 отношения
  не имеют, названы заново.
- **Посторонний дифф в дереве, не тронут**: удаление `plans/2026.07.26-EST-TRAIN/**` и untracked
  `plans/archive/2026.07.26-EST-TRAIN/` — архивация чужого плана, начатая вне этой сессии.

**Workarounds/Deferred/Open questions:** Workarounds — нет. Deferred: как в предыдущем handoff
(деплой, ретеншн, `ref_okpd2` (Q13), ветка 223-ФЗ (Q10), стабильность id НСИ, закрытость перечней
P1.4/P1.5). Open questions: два вопроса P2.2 (периметр `Files`; кто несёт поле ОКПД2 в форме) —
оба в Pending Work item'а, оба адресованы `plan-design`. Q9, Q10, Q13, Q15 — `Exploration`, ни один
не блокирует.
