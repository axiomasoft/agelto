# P12 release, rotation, rollback and restore evidence template

> TEMPLATE — NO RUNTIME EVIDENCE. Fixture hashes and adapter logs are not production receipts.

| Evidence field | Runtime value | Owner/check |
|:--|:--|:--|
| release authority/candidate pair |  | repository owner |
| verified pre-release backup ID/hash |  | backup runner |
| migration/schema compatibility |  | release owner |
| bounded Horizon drain result |  | queue operator |
| promoted app/Doctor digests |  | registry/deploy operator |
| readiness/canary result |  | application operator |
| rollback previous digest/compatibility/result |  | rollback owner |
| APP before/next/applied/rolled-back fingerprints |  | credential owner |
| DB before/next/applied/rolled-back fingerprints |  | credential owner |
| REDIS before/next/applied/rolled-back fingerprints |  | credential owner |
| MINIO before/next/applied/rolled-back fingerprints |  | credential owner |
| isolated restore target/manifest/result |  | recovery owner |
| measured rollback/restore duration |  | rehearsal owner |

All values remain blank until the follow-up operator rehearsal executes real providers/services.
