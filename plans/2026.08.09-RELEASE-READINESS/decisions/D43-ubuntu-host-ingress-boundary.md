---
id: D43
date: 2026-08-09
status: accepted
item: P12.1
items: [P12.1, P12.3, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Первый production host запечатывается на Ubuntu 24.04 LTS. Dedicated key-only `deploy` user
выполняет deployment; root SSH/password login запрещены, provisioning elevation отделено, членство
в `docker` признаётся root-equivalent privilege, а Docker TCP socket не публикуется. Host Nginx
завершает TLS через current Certbot carrier и проксирует на loopback-bound app port; Internet surface
ограничен SSH/HTTP/HTTPS и проверяется по effective Docker/iptables path, не только UFW status.
RAG:✅ 2026-08-09 official Ubuntu/Docker/Nginx/Certbot evidence in
`findings/P12-ubuntu-deployment-rag-2026-08-09.md`.

## Почему

Ubuntu 26.04 LTS уже поддерживается Docker, но новый major host baseline увеличивает first-deploy
uncertainty без project payoff. Direct public container port и prose-only UFW могут расходиться;
separate host ingress оставляет certificate/firewall lifecycle вне immutable application image и
делает loopback boundary проверяемой.

## Consequences

Переход на Ubuntu 26.04 или container-managed ingress требует отдельного full rehearsal и новой
decision record. Deploy identity остаётся высокопривилегированной из-за Docker socket, поэтому ключи,
login и actions журналируются/ревокуются как административные credentials.
