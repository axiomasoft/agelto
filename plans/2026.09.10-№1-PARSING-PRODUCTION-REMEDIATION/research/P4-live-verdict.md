# P4.1 — live A1/B2 verdict

Дата: 2026-09-11
Решение: fail-closed, D8 branch `insufficient_evidence`

## Digest-bound inputs

- A1 finding SHA-256:
  `bdcfc510bb16267a6ed4e737093b936a39885025173125c66253888788579e5c`;
- B2 finding SHA-256:
  `2b9f897ddc5cb86da80cea993b7ec05e6709b671f556d9c23b8d265b5bba022f`;
- authority SHA-256:
  `c3b0a313b1d94b59e73ffa6e4ad8e4ccd57a3df40bbb5450d1a3630fd2a7d240`;
- scope SHA-256:
  `c6d3cba75f4ca5e7d6b4c4eed44a64908354dc456f6ba1f3e7fa58492b1209cf`.

## Синтез

ЕИС не вернула ни listing pages для A1, ни attachment snapshots для B2: оба прогона независимо
достигли утверждённого stop-condition после трёх последовательных network timeout. Использовано 7
запросов из общего ceiling 48. Поэтому live evidence не подтверждает multi-page boundary ordering и
не образует ни одной полной replacement pair.

Это не counterexample к исправлению A1 и не свидетельство mutable/immutable `uid`; это ограничение
доступности источника в конкретном owner-authorized окне. Для B2 точный reducer D8 требует ветвь
`insufficient_evidence`, а значит pilot defer запрещён и bounded revalidation обязательна.

## Принятое техническое следствие

Реализован единый ограниченный selector с двумя триггерами:

- немедленный подбор существующих attachments после индексации новой procurement revision;
- страховочный low-rate sweep раз в шесть часов, не более 25 документов за тик, с due-порогом
  30 дней по умолчанию.

Removal, future backoff, permanent failure и активный lease блокируют подбор. Job использует тот же
production `DocumentDownloader`, `ProxyResolver`, `PlatformRequestBudget`, `SourceTlsTrust`, storage
verification и content-addressed finalization. Совпавший SHA не создаёт версию и не откатывает
pipeline status; новый SHA создаёт `DocumentVersion`, событие `document_replaced`, переводит
документ в `Downloaded` и отдаёт его обычному preparation dispatcher.

## Остаточные ограничения и handoff

- A1 live evidence остаётся `insufficient_live_boundary_evidence`; кодовый multi-page/day-precision
  контракт остаётся покрыт regression suite предыдущей фазы, но живые страницы в этом окне не
  наблюдались.
- B2 не даёт универсальной характеристики поведения ЕИС `uid`; риск закрыт механизмом проверки
  bytes, а не предположением.
- Production rollout по-прежнему запрещён. Следующий шаг — P4.2 по маршруту
  `cold-start-root: whole-plan-independent-audit` с новой provider session/context route evidence,
  отличной от executor P4.1.
