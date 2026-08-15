# P10 remediation matrix

Дата: 2026-08-13. Матрица сводит terminal reports P10.1–P10.4; один finding имеет ровно
одну disposition. `fixed` означает, что ниже названы public regression seam и product path.

| Audit ID | Severity | Disposition | Owner | Evidence / regression seam | Reason, trigger and follow-up |
|:--|:--|:--|:--|:--|:--|
| P10.2-S01 | high | fixed | P10.5 | `GeminiProxyRoutingTest`: legacy env value still yields `NoProxyAvailableException`; `PoolOutboundProxy` delegates solely to `ProxyResolver`. | Removes the D29 bypass without changing resolver country/health policy. |
| P10.2-S02 | medium | deferred | release owner | No product byte change. | A fixture maximum size is an admission-policy value not present in D30/D39. Before release, owner must choose the limit and its storage/worker budget; then add form/service MIME+size vectors. |
| P10.2-S03 | high | fixed | P10.5 | `StorageLifecycleTest`: `mc` command vector excludes S3 key and secret. | Alias credentials now travel through the scoped `MC_HOST_agelto-storage-lifecycle` environment, not child argv. |
| P10.3-D01 | low | fixed | P10.5 | `rg -n 'GracefullyStoppable' app tests` has no producer/consumer after deletion. | Removes the unused graceful-stop template rather than preserving speculative generality. |
| P10.3-D02 | low | deferred | P10.6 | Existing `EstimateSchemaTest` remains the behavior seam. | P10.6 owns the living static-analysis guide correction; trigger is its documentation gate. |
| P10.4-R01 | high | fixed | P10.5 | `AnalysisStoreRepositoryConcurrencyTest`: two PostgreSQL workers return one active analysis id. | Unique violation is recovered by rereading the active partial-index winner. |
| P10.4-R02 | high | fixed | P10.5 | `OpenAiCompatibleProviderTest`: configured missing key throws before Prism receives a call; `_FILE`/env resolution remains `SecretValue`. | `api_key_env = null` remains the explicit no-auth route. |
| P10.4-T01 | medium | deferred | P11 | `ProcurementIngestConcurrencyTest` / `CrawlerRunManagerTest` are the topology seams. | Process-isolated fixture namespace/cleanup is P11 scope; no product workaround is accepted here. |

## Static-analysis inventory

P10.5 removed the 34 configured PHPStan findings recorded by P10.1 without changing the
baseline, adding ignores/excludes, or lowering the level: `phpstan analyse` is green at the
existing project configuration. The direct Larastan `level=max`, no-baseline inventory is now
`1591` errors over `266` paths (down from P10.1’s `1637`/`271`); it remains explicit P10.6
quality-gate input, where the committed baseline must be deleted and max must become green.
