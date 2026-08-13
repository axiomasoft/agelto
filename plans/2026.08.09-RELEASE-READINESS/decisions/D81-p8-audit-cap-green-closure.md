---
id: D81
date: 2026-08-13
status: accepted
item: P8.7
items: [P8.1, P8.2, P8.3, P8.4, P8.5, P8.6, P8.7]
supersedes: [D80]
superseded_by: null
---
## Решение

Владелец отклоняет принятие F1–F4 второго Audit P8 как постоянного долга и требует довести фазу
до полностью GREEN. D81 supersedes D80 только в части terminal audit/closure topology: фактический
P8.6 product `8caf8acc`, оба audit block, journal и evidence остаются append-only provenance, но
P8 не закрывается оранжевой и не запускает Audit 3. `migration-version` повышается `0.1.10` →
`0.1.11`, добавляется один P8.7 и Phase Handoff инвалидируется маркером D81. RAG:—
(repo-grounded: Audit P8 round 2 and owner directive 2026-08-13).

P8.7 принимает под точный Files-контур пять test paths, material изменённых P8.6 вне его sealed
границы, восстанавливает в `ProxyBindingResolverTest` отдельный registry с собственным proxy и
negative assertion против cross-registry leakage, а existing P8.5 carrier расширяет до style
проверки каждого P8.7/P8.6 PHP path с машинно выведенным точным count. Ложная строка «9 files
clean» не переписывается: новый evidence carrier явно supersedes её exact command/path list и
candidate SHA. RAG:— (repo-grounded: Audit P8 round 2 F1/F3 and diff `8caf8acc^..8caf8acc`).

AUDIT-CAP: P8.7 обязан закрыть без waiver; третьего Audit P8 нет. Эта узкая remediation grant
разрешает именно P8.7 после второго audit round и не меняет лимит аудитов либо права иных item'ов.
Владелец разрешает повторить исходные Validation для P8.7 только в fresh native roots с
provider-attested маршрутами; новых terminal attempts недостаточно без route evidence.

Только на одном committed GREEN P8.7 candidate разрешены append-only corrections P8.2/P8.6:
current states обязаны стать `🟢 Done` с `Pending Work: —`, `Known Deviations: —` и canonical
write-site; P8.7 закрывается тем же образом. Затем state→bundle→decisions→status→closure
пересобираются владельцами и фаза закрывается штатным `task:plan-close`. Closing root обязан
вызвать `phase_close_gate` с `owner_decision="D81"`; P9 запрещена при любом red/unknown, неверном
count, undeclared product path, unresolved owner gate или named deviation. RAG:— (repo-grounded:
Audit P8 round 2 F2/F4 and Layout v2 projection order).

**Consequences:** D80 остаётся источником P8.6 intent и доказанного factory repair, но его
требование GREEN Audit round 2 больше не является исполнимым successor: round 2 уже RED и cap
исчерпан. Цена решения — ещё один bounded product/evidence commit и полный повтор release carrier;
выигрыш — воспроизводимая exact ownership topology и нулевой остаточный долг перед P9.

## Почему

Все четыре finding относятся к одному candidate boundary: удалённый guard и остальные test drifts
появились в одном P8.6 commit, а ложный count и success-as-deviation описывают этот же carrier.
Один P8.7 может законно владеть путями, восстановить semantic negative и на том же SHA доказать
полный acceptance; разделение снова создало бы промежуточную ложную terminality.

## Alternatives

1. Закрыть P8 оранжевой с F1–F4. Отклонено прямой директивой владельца.
2. Остановить план. Отклонено: defects bounded и обратимы.
3. Переписать P8.6 Files, evidence или journal задним числом. Отклонено: ломает provenance.
4. Запустить третий Audit P8. Отклонено: audit cap исчерпан, а D81 задаёт literal GREEN carrier.
5. Один P8.7 с exact test ownership, восстановленным negative и direct close. Выбрано.

## Consequences

План содержит 84 item. P8.7 исполняется `implementation/high`, `manual`, `Review=full` на одном
Agelto product/evidence candidate и отдельном bookkeeping commit. Любой новый frozen/public/product
дефект чинится внутри P8.7, если он входит в declared Files; выход за них требует нового точного
owner scope, но не может быть скрыт waiver. После GREEN close P8 следующий item — P9.1.
