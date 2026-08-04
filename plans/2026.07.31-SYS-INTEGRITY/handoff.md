# HANDOFF — 2026-08-04 — after P2.12

**Next:** «ЗАПУСК ВРУЧНУЮ: implementation/high» — `Exec = manual` по §3 Routing (P2.12), модель и
effort ставит владелец до команды, `plan-run` их не пинит. Item P2.12 остаётся `🟡 In progress`:
вся бесплатная работа сделана и проверена, куплен должен быть РОВНО ОДИН платный прогон.

| Параметр | Значение |
|:--|:--|
| Model | sonnet (implementation) |
| Thinking | high — предмет: платный невоспроизводимый замер и ветвление «второй прогон по условию» (§3 Routing) |
| Context | continue (/clear) — ручной item |
| Суть | Купить единственный платный `estimate:eval:run --split=all` на УЖЕ подготовленном дереве, прочитать его режимом `noise-aware --noise-pair=4,5`, дозаписать §8.18 и закрыть P2.12 |

Голый промпт (копипаст без правок):

```
Продолжи item P2.12 плана 2026.07.31-SYS-INTEGRITY (plans/2026.07.31-SYS-INTEGRITY).
Состояние: вся бесплатная часть item'а выполнена заходом 2026-08-04 (opus/high) и лежит
НЕзакоммиченной в рабочем дереве — девять файлов промтов обоих канонических корней уже несут
удаления дублей схемы (34 308 → 32 339 байт, ни один файл не вырос), БД синхронизирована
(`ai:prompts:sync` + `--check` зелёные), якорный тест-гейт И1–И4 закоммичен отдельно (`a770baf`),
перечень удалённого с носителем каждой строки — `research/03-prompt-contract.md` §8.18.
Validation-клаузы (1)–(7) и (9) ТЗ прогнаны и зелёные; не прогнана только (8) — платный замер.

Прочти: plans/2026.07.31-SYS-INTEGRITY/phases/P2.md — item P2.12 целиком (Implementation Rules,
Validation, Pending Work) и research/03-prompt-contract.md §8.15–§8.18.

Сделай ровно это:
1. Пред-гейт перед платным прогоном (обязателен по ТЗ): `docker exec agelto_app php artisan test
   --no-tia --filter=PromptComposition` зелёный · `ai:prompts:sync --check` без дрейфа · активных
   protected-записей больше одной ни на один ключ нет · md5 девяти файлов совпадают с §8.18
   (`global 649bd567 · screening:global 44e80d6e · screening:classify 7691b0a7 ·
   prompt:critic 1dbfc0bf · extraction:global aaf886c6 · extraction:discovery dace6ab6 ·
   extraction:passport b5b4c077 · extraction:rows 4a6aa05f · extraction:totals 3c843d1c`).
   Расхождение md5 — СТОП: дерево не то, на котором собран отчёт.
2. ОДИН платный прогон: `docker exec -e AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300 agelto_app php
   artisan estimate:eval:run --split=all` (контур D23 менять нельзя: extract_* маршруты —
   gemini-3.1-pro-preview + {"max_tokens":65536,"thinking_level":"LOW"}).
3. Приёмка ТОЛЬКО режимом noise-aware: `docker exec agelto_app php artisan estimate:eval:compare
   --base=12 --candidate=<id> --criterion=noise-aware --noise-pair=4,5 --format=md`.
   Ветвление выписано заранее (D110), решать по ходу нечего: accept → закрытие; reject при
   максимальном превышении порога ≤ 2× → покупается ВТОРОЙ прогон того же кандидата на том же
   дереве без единой правки текста, пара «кандидат/кандидат′» становится современной нулевой парой
   и вердикт пересчитывается против неё; reject при превышении > 2× → откат текстов
   (`git checkout -- <девять путей>` + `ai:prompts:sync`) без второго прогона и закрытие 🟠.
   Третьей редакции текстов нет ни в каком виде; подгонка текста под метрику после замера
   запрещена.
4. Дозапиши §8.18 в research/03-prompt-contract.md: номер прогона, вердикт дословно, профиль
   вызовов против #12/#13/#14, вердикт по гипотезе §8.16 «длиннее системная часть → скупее вывод»
   (кандидат ВПЕРВЫЕ на фазе КОРОЧЕ канона — это и есть разделяющий замер).
5. Закрой item по топологии двух коммитов: item-коммит по девяти путям промтов (при accept) либо
   пустой Scope (при откате), затем bookkeeping плана + handoff + plan-lint.

Не трогай в дереве чужой дифф: M .env.example, M CLAUDE.md, untracked config/cms.php,
database/seeders/User/, estimates/gemini-analys.md, .swissknife*/, plans/**/.claude/.
```

**Сделано этим заходом (P2.12, plan-run, opus/high — бесплатная часть).**

Дифф удалений по шести из девяти файлов (`+15/−28` строк, все 15 добавленных — сшивки, поимённо
названные в §8.18): сняты литералы полей и правила, носителем которых является схема —
`ScreeningResult` (`document_type`, `confidence`, `ignore_reason`), `DiscoveryMarkerLine::$marker`,
`PassportResponse` (`estimate_number`, `currency`, `base_year`, `price_level`, `totals`, хвост
`rows_expected`), `RowAttributes` (три разреза КС-3), `IncompleteRow::$reason` (оба литерала),
`TotalLine::$value`. Каждый носитель подтверждён ЧТЕНИЕМ кода контракта, не таблицей. Ни одна
остающаяся формулировка не переписана; блоки безопасности, якорные фразы И1–И4, порядок абзацев —
дословно на месте. Объёмы: 34 308 → 32 339 байт (−5.7 %), ни один файл не вырос — кандидат впервые
на фазе КОРОЧЕ канона (обе отклонённые редакции были +9.1 %).

Найдено расхождение таблицы §8.15 с кодом: строка «`extraction:totals` · «НДС по умолчанию 20 %» ·
носитель `extraction:global`» носителем НЕ подтверждена (в `extraction:global` правила нет) —
фрагмент сохранён по Implementation Rules, факт в §8.18 и Pending Work item'а.

Платный замер (Validation #8) НЕ куплен — решение владельца 2026-08-04 «остановиться до замера».

**Done:**

- **P2 5/13** 🟡 In progress (терминальны 11 из 13: P2.1 🟢, P2.2 🟢, P2.10 🟢, P2.11 🟢, P2.13 🟢,
  P2.3 🟠, P2.4 🟠, P2.5 🟠, P2.6 🟠, P2.8 🟠, P2.9 🟠; **P2.12 🟡 In progress**, P2.7 ⬜).
- **P1 4/10** — фаза закрыта повторно `🟠` после реопена D82 (без изменений в этом заходе).

**Remaining:**

- **P2.12** — остался РОВНО один шаг: платный замер + чтение `noise-aware` + дозапись §8.18 +
  закрытие (голый промпт выше).
- **P2.7** — доки/спека/скил + приёмочный прогон фазы.
- Далее по очереди фаз (D63): P3 → P5 → P6 → P4 → P7.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — Meta, Routing §3 (строка P2.12), Status Board §4,
  Decision Log D108–D110.
- `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` — ТЗ P2.12 целиком + его Pending Work / Known
  Deviations этого захода.
- `plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` — **§8.18** (новая: что снято, с
  каким носителем, объёмы, md5 кандидата), §8.17 (вердикты `noise-aware`), §8.16 (переоценка
  контура), §8.15 (таблица трассировки), §8.14 (диагноз M4/M5).
- `plans/2026.07.31-SYS-INTEGRITY/findings/P2-eval-resolution-2026-08-02.md` — сырьё §8.16/§8.17.
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` — OQ-7 (переписывание текстов сверх
  дедупликации, `Exploration`; P2.12 не блокирует).

**Open risks:**

- **Кандидат живёт НЕзакоммиченным в рабочем дереве.** Так предписано ТЗ (ветка отката —
  `git checkout --` по девяти путям), но любой `git checkout`/`stash` посторонним заходом
  уничтожит дифф без следа. Восстановление после потери — по §8.18 (перечень удалений построчный),
  но это ручная работа; md5 девяти файлов в §8.18 позволяют проверить, тот ли кандидат меряется.
- **Нулевая пара — одна, и она с ДРУГОГО дерева.** Пороги `noise-aware` посчитаны по паре #4/#5
  (дерево `d91f86f`, до P2.4/P2.5). Условный второй прогон P2.12 (полоса неоднозначности ≤2×) даёт
  СОВРЕМЕННУЮ пару и снимает риск ровно там, где он решает исход.
- **Расхождение §8.15 с кодом** (строка про НДС 20 %) — таблица в этой строке описывает
  ОТКЛОНЁННУЮ редакцию M5, а не канон. Читать §8.15 как перечень к удалению можно только с
  подтверждением носителя чтением кода, как и предписано ТЗ.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`**, которого `docker-compose.yml:180` требует через
  `${...:?}` — БЕЗ него не интерполируется НИ ОДНА команда `docker compose`. Этот заход обошёл
  прямым `docker exec agelto_app …` (контейнеры не пересоздавались). Чинить владельцу, в `.env`.
- Незакоммиченный чужой диф в дереве: `M .env.example`, `M CLAUDE.md` · untracked
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`, `plans/**/.claude/`, `plans/**/.swissknife/`. Не трогать, не
  коммитить; состав снимать `git status --porcelain` перед каждым item-коммитом.
  `pint --dirty --test` флагует `config/cms.php` (`single_blank_line_at_eof`) — тот же посторонний
  файл, не Scope P2.12.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** вместо `docker compose exec …` — прямой `docker exec agelto_app …` (обходит
  требование `MINIO_ROOT_PASSWORD` при интерполяции compose-файла); эквивалент по эффекту, в
  Validation-командах ТЗ читать как ту же команду.
- **Deferred:** типизированный носитель семантики кейсов enum (`RowType`) — OQ-6, вне границ P2
  (D107); переписывание текстов сверх дедупликации — OQ-7 (D110); дефект `bcsub` на
  `EST-XLSX-KS2-001` и Prism `unhandled finish reason` — PROMOTE-CANDIDATE, названы в Pending Work
  P2.10/P2.5.
- **Open questions:** OQ-3, OQ-4 (`Exploration`), OQ-5 (`Decision pending`), OQ-6, OQ-7
  (`Exploration`) — `open-questions.md`.
