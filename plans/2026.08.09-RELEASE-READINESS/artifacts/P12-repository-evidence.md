# P12 repository evidence template

> TEMPLATE — NO RUNTIME EVIDENCE. Local fake-adapter output must not fill this table.

| Evidence field | Runtime value | Owner/check |
|:--|:--|:--|
| GitHub host-key official source/date/fingerprint |  | repository operator |
| Agelto public-key fingerprint |  | GitHub enrollment |
| AzGuard public-key fingerprint |  | GitHub enrollment |
| canonical Agelto origin and full candidate SHA |  | repository sync |
| canonical AzGuard origin and D19 full SHA |  | vendor manifest/sync |
| detached/clean/untracked status for both trees |  | repository sync |
| read-only authentication/push-denial result |  | GitHub probe |
| enrollment/rotation/revocation correlation |  | repository owner |

Private keys, tokens, profile values, raw `known_hosts` entries and SSH diagnostics are forbidden.
Empty values are not a current-plan deviation and become evidence only in the follow-up rehearsal.
