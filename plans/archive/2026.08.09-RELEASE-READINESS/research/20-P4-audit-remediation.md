# P4 audit remediation — финальный execution design

## Вход и неизменяемые границы

Источник — `phases/P4/P4.md` → `## Audit P4 — 2026-08-11` и прямая директива владельца от
2026-08-11: исправить все дефекты в текущем проходе, не допускать повторных конфликтов и закрыть
фазу после GREEN remediation без нового design/audit цикла. Single auth route, AzGuard permission
model, traversal/realpath containment, exact VitePress 1.6.4, outside-public boundary, P0–P3/P5+
и пользовательский `AGENTS.md` неизменяемы. RAG:— (repo-grounded: audit scope-payload and owner
message).

## Сведение F1–F8 в один slice

| Находка | Обязательный результат P4.6 |
|:--|:--|
| F1 | `.svg`/`image/svg+xml` исключены из Node/PHP closed allowlists; hostile active SVG отклоняется verifier-ом, forged manifest fail-closed, direct URL не отдаёт bytes. |
| F2 | Composite carrier без внешних credential prerequisites фактически достигает current prod-image, browser и image verdict на одном committed candidate. |
| F3 | Playwright сверяет manifest SHA-256/body, exact MIME и CSP/nosniff/referrer/CORP/cache headers для HTML/CSS/JS/JSON/font, затем direct asset после logout и permission revoke; report хранит full git SHA/image digest/commands/exits. |
| F4 | Documentation build выполняется в detached clean worktree и до Docker build сам вызывает exact pinned `vendor-sync.sh`; ignored source-workspace checkout не копируется и не считается evidence. |
| F5 | P4.1 correction называет P0.4 `04aa63a0`, P0.6 `52fb69c6` + `bf234d23`, P1.5 `0db08b00` + `56aeda8e`, P3.5 `8971b0d6`, P4.1 product/closure anchors и фактический route. |
| F6 | P4.2 correction честно ставит `🟠 Done with deviations` за четыре foreign docs paths commit `8f0c14dc`; исходный product commit не переписывается. |
| F7 | P4.1/P4.2/P4.5 corrections печатают actual/prescribed route, exact item/product/closure anchors и одну canonical line `write-site: pre_mutation: allow · pre_final: allow`. |
| F8 | `journal.jsonl` остаётся единственным write-site; state/bundle/decision/status/closure projections регенерируются; P4 phase handoff и rolling handoff совпадают с current terminality. |

RAG:— (repo-grounded: P4 audit F1–F8, git history and current documentation carriers).

## Порядок внутри P4.6

1. Закрыть seam общим closed-contract изменением: убрать SVG из verifier/PHP MIME maps, добавить
   hostile build, forged manifest и direct response negative regressions.
2. Сделать documentation-build carrier clean-anchor self-contained: detached worktree exact SHA,
   repository-owned vendor sync, frozen pnpm, two identical builds, Docker image inspection.
3. Удалить stale `setpriv` grep из prod-image carrier, заменив его проверкой текущего
   least-privilege secret hand-off behavior через public image/entrypoint outcome.
4. Генерировать candidate-local admin credentials внутри isolated clean-install trap; external
   `E2E_*` могут override только при явной передаче, но их отсутствие не пропускает browser proof.
5. Расширить Playwright exact manifest-driven assertions и два cache-denial перехода: logout и
   permission revoke. Записать full candidate SHA и exact application image digest.
6. Один `private-documentation-acceptance-contract.sh` запускает все focused/product/clean/browser/
   image/quality/Task/citation gates без optional/skip branch.
7. Только после committed GREEN anchor append-only reconcile P4.1/P4.2/P4.5, штатно regenerate
   projections, закрыть P4.6 и фазу P4; обнаруженный red чинится в том же P4.6 до GREEN.

## DoR verdict

Открытых product-развилок нет: D72 выбирает reject вместо CSP/sanitize и фиксирует один terminal
proof. Item последовательный и имеет один candidate anchor, поэтому orchestration не нужна.
Routing — `implementation/high`, `manual`, `Review=full`; current frontier/high допустим только как
явно записанный overshoot. Уточняющих маркеров нет; все F1–F8, anti-scope, commands и
append-only write-sites адресованы.
