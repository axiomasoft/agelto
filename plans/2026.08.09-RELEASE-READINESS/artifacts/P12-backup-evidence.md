# P12 ingress and recovery evidence template

> TEMPLATE — NO RUNTIME EVIDENCE. Blank values are mandatory in the local preparation plan.

| Evidence field | Runtime value | Rehearsal command/owner |
|:--|:--|:--|
| approved host/profile policy ID |  | repository owner |
| DNS A/AAAA observation |  | operator DNS probe |
| NGINX template hash and `nginx -t` receipt |  | operator ingress check |
| certificate identity/expiry and renewal receipt |  | Certbot rehearsal |
| effective public/loopback port inventory |  | host preflight |
| installed unit hashes and enabled timer calendar |  | systemd rehearsal |
| scheduled backup ID/completion epoch |  | `agelto-backup.service` |
| PostgreSQL custom-dump + isolated-restore hash |  | backup runner |
| object current-state/version-history capability verdict |  | pinned MinIO fixture |
| encrypted off-host manifest hash |  | backup runner |
| verified-copy-before-prune receipt |  | retention fixture |
| watchdog stale/failure result |  | watchdog unit |
| redacted alert correlation ID |  | alert receiver owner |
| isolated DB/object/secret/config restore result |  | operator restore rehearsal |
| measured RPO/RTO |  | rehearsal owner |

Local fixture labels and generated hashes must never populate the runtime-value column. External
proof belongs to the follow-up operator rehearsal plan described by `P12-operator-rehearsal-brief.md`.
