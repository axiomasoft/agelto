# P1 audit remediation design

## Sources

- `phases/P1/P1.md` → `## Audit P1 — 2026-08-10` — F1–F5 и anti-scope.
- `findings/P1-remediation-carrier-baseline-2026-08-10.md` — current Make/test/docs carrier map.
- `research/03-P1-execution-design.md` и D16–D18/D25/D57 — frozen topology/deploy semantics.
- `phases/P0/P0.7.md` и D62 — принятый append-only паттерн audit remediation.

## Декомпозиция

F1 и F2 имеют один coupling hotspot: `docker/Makefile` → focused shell carriers →
`p1-release-contract.sh` → AI/living docs. F3–F5 не создают второй product track: это
append-only reconciliation доказательства уже исполненных P1.1–P1.5. Поэтому выбран один P1.6,
а не серия частично зелёных items. RAG:— (repo-grounded: audit F1–F5 and current Files map).

## Target contract

1. Пять классов остаются literal enum `code | php-deps | frontend | runtime | image-promotion`.
   Make surface даёт отдельный executable path каждому классу и общий fail-closed immutable-tag
   deploy core; различается invalidation/preflight, но production host нигде не строит image.
2. Terminal carrier вызывает focused carriers как public acceptance entrypoints. Он не копирует
   их проверки и не заменяет dynamic Compose/Vite/image evidence grep-ами.
3. Dynamic runs получают unique Compose project/ports и удаляют только собственные resources.
   P2/P3/P12 boundaries остаются открытыми и явно названы.
4. AI-context sync идёт source-first: `.ai/guidelines/architecture.md` → ручной `CLAUDE.md` →
   living docs; managed skill mirrors и AGENTS.md не меняются.
5. После product-коммита reconciliation добавляет новые terminal events P1.1–P1.5 с real anchors;
   старые events/specs не переписываются. P1 закрывается отдельной командой и проверяется новым audit.

## Execution and validation order

Product implementation → focused carriers → terminal carrier → product commit → append-only
P1.1–P1.5 reconciliation → v2 regeneration/checks → P1.6 bookkeeping → phase close → fresh audit.
Один item последователен и меняет общий hotspot; workflow criterion не срабатывает.

## DoR verdict

Scope и anti-scope следуют audit payload; новых external facts или owner gates нет. Required Reads
конечны, route `implementation/high · manual · full` соответствует frozen implementation и цене
ошибки production deploy/test boundary. Неразрешённых markers нет. Item DoR пройден; Layout v2
reopen-status presentation gap не создаёт ambiguity для P1.6, но записан D64 как protocol deviation
и должен быть поднят в Task отдельно, без fabricated execution event.
