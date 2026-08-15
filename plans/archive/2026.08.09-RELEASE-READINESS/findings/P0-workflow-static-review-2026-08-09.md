# P0 workflow static review — 2026-08-09

## Scope

Read-only reviewer сверил plan-owned copies `wf-research-fanout.js` и
`wf-audit-adversarial.js` с `packages/task/workflows/README.md` и design-only матрицей
`workflow-craft`. Скрипты не исполнялись.

## Verdict

**RED**

1. Research template превращает `null` verify result в `verify: []`, а затем
   считает тему успешной; failure не попадает в `dropped`/`failed`.
2. Audit template отбрасывает failed finders, может счесть падение всех
   finders чистым audit, молча fallback-ит dedup и превращает verify failure в
   `PLAUSIBLE`; failures не возвращаются.
3. Заявленный per-topic `asOf` не подмешан в prompt/label, поэтому cache-bust
   не работает.
4. Часть control-flow prompts не завершается явным требованием «финальный
   текст = данные по схеме, не сообщение человеку».

## Disposition

RED блокирует выдачу scripts. Правка plan-owned copies изменила бы логику
канонических templates, что запрещено slot-only contract; исправление
Swissknifeman marketplace находится вне scope этого plan-design. D15 перевёл P0.3–P0.5
в последовательную цепь и тем снял orchestration criterion.
