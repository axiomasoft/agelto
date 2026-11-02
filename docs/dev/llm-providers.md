---
audience: dev
type: reference
---

# LLM providers

Llm владеет provider-neutral operation plan, schema validation, wire compilation,
single-use transport, generic request/receipt ledger, budget и file/cache carriers.
Обратных импортов Procurement нет. Consumer linkage хранит prc_analysis_llm_requests;
AnalysisCallCoordinator реализует Llm-owned ProviderSendGate.

## Runtime и provisioning

Analysis использует StructuredCallExecutor и узкий GeminiGenerateContentClient.
Клиент чисто кодирует запрос/декодирует ответ. HTTP выполняет PreparedProviderTransport,
полученный после совместного financial/network claim.
Prism adapter, ordinary PoolOutboundProxy, post-call-only recorder и Procurement-aware cache
manager удалены. Synchronous adapters/callers без owning gate бросают UnsupportedConsumer до HTTP,
включая OpenAI-compatible, eval/critic/shadow callers.

config/llm.php принимает LLM_ANALYSIS_PROFILE, LLM_ANALYSIS_PROFILES_JSON и credential refs.
JSON — configuration boundary; дальше используются typed immutable objects. Профиль явно задаёт:

- context: provider/account/API/model, route policy, generation и thinking options;
- origin: разрешённый canonical origin;
- pricing: currency/version, conservative input/output/thinking maxima, prices/fixed charge,
  thinking applicability, bound evidence и valid_until;
- period_id: UUID либо пустую строку для current account/currency period;
- credential_key: ссылку в credential registry.

Live модель и нулевая цена автоматически не выбираются. Account-specific capabilities,
доступность и pricing evidence проверяются при provisioning. Parsing и runtime composition
не открывают DB connection и не вызывают API. Пустой профиль не создаёт paid work.
Secrets не входят в recipe, queue, transitions или logs.

process:sync-analysis --period-start=... --period-end=... --cap=... --reason=...
синхронизирует account policy, period, handler authorities и target catalog.
Опциональны --profile и --hard-exposure. Команда не делает provider HTTP;
route membership provision-ится через общий network owner.

## Request, деньги и send

llm_provider_requests — logical UUID authority с unique key:
SHA256(recipe hash + typed input/window hash + purpose + semantic ordinal + replay intent).
llm_provider_calls — physical UUID authority. LlmCall отображает эту таблицу и запрещает
Eloquent save/delete; consumer relations принадлежат Procurement.

До send создаются intent, physical call, deterministic receipt upload intent и conservative
reserve. Unknown/expired pricing блокирует authorization. Account-period lock защищает cap;
thinking/output не считаются дважды. Initial unknown policy: halt новых billable send при count>=1,
automatic_resend=false. Concurrent in-flight unknown могут превысить trigger; exposure остаётся
зарезервированным, включая прежние periods после rollover.

Authorization не разрешает HTTP. Executor компилирует immutable wire request и вызывает gate.
Coordinator ждёт slot вне transaction, затем короткая transaction проверяет полный prefix:
control → handler → lane/storage → subject → work → finance → provider → network.
Provider/network claims атомарны. Pause/hold/cancel/expired lease/retired route/cooldown до claim
дают zero HTTP. Reuse operation/capability не создаёт второй send.
Сеть, object I/O и sleep не выполняются под DB locks.

## Receipt и recovery

Immutable response сохраняется по deterministic physical-call key с hash до model parsing.
Receipt capture фиксирует financial facts после cancel/lease loss, без разрешения business writes.
Полную таблицу применяет общий Process reconciler:

| Durable факт | Действие |
| --- | --- |
| Authorization без claim | CAS revoke, not_sent, settlement 0; bounded новая authorization |
| Claim без verified receipt | unknown, reserve retained, blocked(provider_result_unknown) |
| Successful generation | pinned resume_from_receipt, zero new send/reserve |
| Proven nonbillable error | settlement 0, bounded explicit physical retry/new reserve |
| Error с unproven charge | blocked unknown; error body не model JSON |
| Cache/file/lookup success | exact metadata recovery, следующий distinct declared purpose |
| Cancelled/superseded work | financial history only, zero publication |

Raw existence не означает successful generation. Время и daily billing aggregates не доказывают
not_billed отдельной operation. Operator replay получает новый durable replay intent и budget;
old exposure сохраняется. Blocked-provider scan имеет отдельный reconciliation due и работает
без Redis wakeup. Receipt/hash verification выполняется до locked transaction.

## Structured data, assets и отчётность

Canonical PHP Data/schema attributes дают draft 2020-12. Opis выполняет полную local validation
до hydration; provider subset явно компенсируется локально. Remote refs выключены.
Decimal fields — строки. Finish/refusal/empty/unsupported content имеют closed outcomes.
См. [estimate extraction](ai/estimate-extraction.md).

File/cache identity включает account/API/model и semantic system/schema/content ordering.
TTL/404 recovery ограничен и использует immutable stored bytes. Cache create/refresh/storage,
file upload и lookup имеют собственные purpose/accounting/pricing. Unknown cache operation
не разрешает hidden generation fallback. Asset receipt не парсится как model JSON.

ai:caches list читает canonical assets, --hits — factual usage; unowned prune явно Unsupported.
ai:cost-report и UI показывают settled cost, unknown usage/charge и retained reserve отдельно,
в явной валюте. Exact ledger сохраняет numeric(24,8); отчёт округляет до microcurrency.
Текущий config price не используется для выдуманной исторической cache savings.

Financial provenance/pinned receipts не подпадают под blanket log-partition TTL.
Financial tables имеют отдельный retention owner. UI не реконструирует отсутствующий request
как якобы отправленный. Protected raw не попадает в safe telemetry.

Deterministic T13/T15/T22/T35 используют isolated PostgreSQL и local HTTP fixtures без paid API.
Они доказывают fencing, recovery, schema и financial/network accounting; real-model accuracy
не заявляется. Coordinator выполняет acceptance, два clean installs и полный no-TIA regression.
