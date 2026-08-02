# HANDOFF — 2026-08-02 — after P2.11

**Next:** «ЗАПУСК ВРУЧНУЮ: opus/high»

| Параметр | Значение |
|:--|:--|
| Model | opus (frontier) |
| Thinking | high — авторский текст под инвариантами И1–И4, качество проверяется только платным замером |
| Context | continue (/clear) — ручной item |
| Суть | Повторная редакция девяти файлов промтов ОТ отклонённой редакции `storage/app/p26-rejected/`, под И1–И4 (`research/03` §8.14); постоянный якорный тест-гейт директивной силы; ОДИН платный eval-замер, база сравнения #12; провал → откат текстов И эскалация (D106), не третья редакция |
| Команда | `—` (Exec=manual, нет single-command эквивалента) |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P2.12
```

**Сделано этим заходом (P2.11, plan-exec).**

Фикс `PromptCanonSynchronizer` на откат канона вниз. Обе половины сервиса — `deactivateStale()`
(пишет) и `drift()` (сверяет) — сравнивали `$prompt->version < $latest` и были слепы к откату
канона ВНИЗ: после провала и отката P2.6 в `llm_prompts` оставались активными и канонная, и
осиротевшая старшая версия ключа, `PromptComposer` склеивал в системную часть ОБЕ, а
`ai:prompts:sync --check` при этом рапортовал «дрейфа не обнаружено».

Условие заменено на «активна РОВНО версия канона» (`$prompt->version !== $latest`) в
`deactivateStale()`; `drift()` дополнительно различает откат («откат канона: активна версия новее
файла») от протухания («устаревшая версия ещё активна в БД») — оператор видит, что именно
произошло. Новый регрессионный тест (`tests/Feature/Ai/PromptsSyncTest.php`) доказан красным без
фикса `git stash` (1 failed: `Expected status code 1 but received 0`) → `git stash pop` → зелёный.

Item-коммит `dea6f15` (2 файла, 33 вставки/1 удаление) — дифф РОВНО по `Files`.

**Done:**

- **P2 4/12** 🟡 (терминальны 10 из 12: P2.1 🟢, P2.2 🟢, P2.10 🟢, P2.11 🟢, P2.3 🟠, P2.4 🟠,
  P2.5 🟠, P2.6 🟠, P2.8 🟠, P2.9 🟠).
- **P1 4/10** — фаза закрыта повторно `🟠` после реопена D82.

**Remaining:**

- **P2.12** — повторная редакция текстов промтов, один платный замер (следующий шаг, manual).
- **P2.7** — доки/спека/скил + приёмочный прогон фазы (Inputs ждут P2.12; P2.11 доставлен).
- Далее по очереди фаз (D63): P3 → P5 → P6 → P4 → P7.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — Meta, Routing §3, Status Board §4, D-лог §5.
- `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` — ТЗ P2.12 целиком (следующий item), P2.11
  Completion Notes, Phase Context фазы.
- `plans/2026.07.31-SYS-INTEGRITY/research/03-prompt-contract.md` — §7 (целевой скелет), §8.13
  (числа и профиль вызовов M4), §8.14 (диагноз, инварианты И1–И4, что снимать законно).
- `storage/app/p26-rejected/` — девять файлов отклонённой редакции (gitignored, вне диффа): БАЗА
  правки P2.12, переписывать с нуля не нужно.
- `plans/2026.07.31-SYS-INTEGRITY/roadmap.md` — исполнительская карта и гейты владельца.

**Open risks:**

- **Диагноз D104 достаточен для объяснения, но не доказан достаточным для приёмки.** Три
  механизма названы; вклад каждого не измерялся сознательно. Второй красный замер означает
  причину вне них — предусмотрена остановка эскалацией (D106), а не третья попытка.
- **Платный замер P2.12 невоспроизводим.** Контур обязан быть идентичен замерам M0₃, M3c и M4 (`options`
  шести extraction-маршрутов `{"max_tokens":65536,"thinking_level":"LOW"}`,
  `-e AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300`) — иначе сравнение с базой #12 недействительно (D23).
- Незакоммиченный чужой диф в дереве: `M .env.example` · untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`, `plans/**/.claude/`,
  `plans/**/.swissknife/`. Не трогать, не коммитить; фактический состав снимать
  `git status --porcelain` перед каждым item-коммитом.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** временный `AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300` при eval-прогонах (не
  персистентно) и локальный `docker-compose.override.yml` с MTU докер-сети (gitignored) —
  оба введены P2.5 и нужны P2.12.
- **Deferred:** типизированный носитель семантики кейсов enum (`RowType`) — OQ-6, вне границ P2
  (D107); дефект `bcsub` на `EST-XLSX-KS2-001` и Prism `unhandled finish reason` —
  PROMOTE-CANDIDATE, названы в Pending Work P2.10/P2.5.
- **Open questions:** OQ-3, OQ-4 (`Exploration`), OQ-5 (`Decision pending`), OQ-6 (`Exploration`)
  — `open-questions.md`.
