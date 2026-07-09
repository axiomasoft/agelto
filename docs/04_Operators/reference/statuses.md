---
audience: operator
type: reference
status: draft
---

# Статусы и колонки админки

Здесь будет: каждый статус/колонка админки не-инженерным языком — `DocumentStatus` (включая
`unprocessable` + `UnprocessableReason`, D32: «почему документ не удалось обработать»),
`AnalysisStatus`, `validation_status`, `review_status`. Сверяется с enum-кейсами кода
(`app/Enums/Procurement/`) — полнота проверяема grep'ом.

Наполняется в P10.4.
