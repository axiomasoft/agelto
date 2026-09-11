# P4.1 — B2 live verification facts

Дата: 2026-09-11
Machine artifact: `artifacts/P4-b2-live-verification.json`
Artifact SHA-256: `d607cb2a2db44735a8b23d16c3560647ab8d6752abba42d8ce511db228a0a698`

## Авторизация и границы

- authority SHA-256: `c3b0a313b1d94b59e73ffa6e4ad8e4ccd57a3df40bbb5450d1a3630fd2a7d240`;
- scope SHA-256: `c6d3cba75f4ca5e7d6b4c4eed44a64908354dc456f6ba1f3e7fa58492b1209cf`;
- git anchor: `f60e98c25aa65e8ebf225e4681beca3e796a52b9`;
- в scope четыре registry number; stop-condition сработал на первом после трёх transport errors,
  поэтому остальные три номера не запрашивались;
- B2 израсходовал 3 запроса; A1+B2 суммарно израсходовали 7 из разрешённых 48.

## Наблюдения и reducer

- Запросы карточки `0338300047926000272` для URL types `ea20`, `ok20`, `zk20` завершились
  `network_timeout`; evidence IDs: `b2-card-0338300047926000272-01`…`03`.
- Stop reason: `three-consecutive-transport-errors`.
- Полных before/after replacement pairs: 0. Ни `same_uid_different_hash`, ни пригодный набор
  `changed_uid_observed` не наблюдались.
- По точному порядку reducer D8 branch = `insufficient_evidence`,
  `revalidation_required=true`.
- Artifact не содержит document/page bodies, credentials или signed query parameters; публичные
  request URLs ограничены `regNumber`.

## Branch-specific validation facts

- End-to-end suite: 12 tests passed, 39 assertions. Она проводит прежний uid с новыми bytes через
  selector → `RevalidateDocumentJob` → production downloader → новую `DocumentVersion` → обычный
  `PrepareDocumentJob`.
- Та же suite подтверждает no-op по одинаковому SHA с сохранением текущей pipeline-стадии, bounded
  limit, revision trigger, low-rate due policy и исключение removal/backoff/permanent failure/lease.
- Pint: 11 целевых/условных файлов — green. PHPStan: 4 command/conditional classes — no errors.
- SHA-256 тестов и implementation-файлов записаны в `revalidation_validation` machine artifact;
  evidence IDs: `b2-revalidation-e2e-tests`, `b2-revalidation-static-validation`.

Этот finding фиксирует только наблюдения. Итоговая интерпретация находится в
`research/P4-live-verdict.md`.
