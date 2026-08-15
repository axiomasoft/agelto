# Rollback and restore preparation

Image rollback stops unless the previous immutable digest is known and schema compatibility is
explicitly green. Its order is stop → compatibility → previous digest → promote → readiness. It
does not delete volumes, rebuild on the host, run `migrate:fresh` or silently accept an incompatible
database.

Data restore requires a verified backup manifest and an isolated `*_rehearsal` database/bucket
identity that contains neither `prod` nor `production`. The fixture verifies the manifest, creates
the isolated target, restores PostgreSQL/object/config state and checks readiness. Production
identity and destructive flags fail before the adapter is called.

The follow-up rehearsal supplies the owner gate, real backup ID, isolated target, measurements and
go/no-go decision. Current P12 produces no rollback/restore runtime receipt.
