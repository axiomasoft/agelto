---
id: D107
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**`supersedes D45` в части ФОРМЫ паузы между попытками: плоские 500 мс заменяются экспонентой с джиттером и уважением `Retry-After`.** Число попыток D45 (5) НЕ отменяется — отменяется только «пауза = константа». Основание — свод P1.3 (`findings/P1-rag-reliability-practices.md` §1.2, §1.4 п. 4): канон AWS требует джиттера (`Full Jitter`: спать `U(0, min(cap, base × 2^n))`), RFC 9110 §10.2.3 требует ждать не меньше `Retry-After` ответа 429, а боевой код сегодня не делает ни того ни другого — `Http::retry(times: crawler.http.retries, sleepMilliseconds: crawler.http.base_delay_ms = 500, when: 429 или 5xx)` в `CsvSourceMethod::request()` и `DocumentDownloader::fetch()`. При дефолте «5 попыток» это пять обращений к уже троттлящему хосту примерно за две секунды. Правку делает **P4.1** (оба файла уже в его `Files`, а `retry_delay_ms` — в его `Scope Included` п. 7): секция `stages` несёт не константу, а параметры backoff (`base`, `cap`, джиттер), и клиент читает `Retry-After` раньше локально вычисленной паузы. Сам P1.3 не правит ни числа, ни кода (`Scope Excluded`). **Consequences:** `Scope Included` P4.1 п. 7 всё ещё называет `retry_delay_ms = 500` плоской величиной — до синхронизации спеки с этой записью исполнитель P4.1 увидит два противоречащих указания; синхронизацию делает `plan-design`, не исполнитель. Второе следствие: правило Google SRE «ретраи живут ровно на одном слое» у нас уже нарушено (HTTP-слой + доменный `detail_next_retry_at`/`documents_retry_backoff_minutes`), и это тоже адресовано P4.1 — свод §1.6

## Почему

If-then эскалация ТЗ P1.3 сработала штатно: практики разошлись с дефолтом, и по правилу item'а факт кладётся в свод, а расхождение — новой D-записью со ссылкой на него, а не молчаливой правкой числа. Форма паузы, в отличие от их количества, расходится с каноном однозначно: у AWS «голый капнутый экспоненциальный backoff без джиттера» назван антипаттерном, а `Retry-After` в RFC — не рекомендация тона, а поведение агента. `RAG:✅ 2026-08-07 (perplexity-web, https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/ 2015-03-04; https://www.rfc-editor.org/rfc/rfc9110.html#section-10.2.3 2022-06)` + `RAG:— (repo-grounded: app/Services/Procurement/Eis/CsvSourceMethod.php:258-264; app/Services/Procurement/DocumentDownloader.php:116-122)`
