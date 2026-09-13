---
audience: operator
type: runbook
status: living
---

# Исходящая почта с собственного сервера Agelto

Приложение работает без доставки почты: `MAIL_MAILER=log` сохраняет письма в log, но не
отправляет их. Это не успешная SMTP доставка. По решению владельца внешние credentials
сейчас не обязательны; доступ к сайту/БД/очередям не должен зависеть от SMTP или backup.

## Изолированный SMTP без чужих настроек

Канонические files: `docker/host/mail/compose.yml`, `mail.env.example`, `smtp-smoke.php`.
Это отдельный project `agelto_mail`, service `smtp`, alias `agelto-smtp:587` только в уже
**собственной Agelto** network. Не подключайте SMTP к соседним Docker networks.
Нет host ports, authentication endpoint или публичного relay. Persistent named volumes
`agelto_mail_mail_queue` и `agelto_mail_dkim_keys` относятся только к этому project.
Trust list — loopback + exact audited Agelto subnet, не весь 172.16/12 и не 0.0.0.0/0.
Sender domain ограничен `agelto.ru`; receipt smoke отдельно проверяет отказ другому sender.

Version `boky/postfix:5.1.0` проверена по release-specific
[документации автора](https://github.com/bokysan/docker-postfix/tree/v5.1.0).
Не берите unreleased master docs как доказательство существования image tag. На этом host
проверен digest `sha256:aafc772384232497bed875e1eb66b4d3e54ba1ebc86e2e185a6dc1dbc48182ef`.
OpenDKIM keys генерируются один раз в собственном volume, private key остаётся на сервере.
Публикуется только TXT public part. Built-in `/scripts/healthcheck.sh` проверяет SMTP и DKIM,
не заменяйте его более слабым process-only probe.

## Preconditions и установка с нуля

Сначала закончите [manual deployment](manual-deployment.md), проверьте labels/subnet own
network, отсутствие project/container/volumes/host directory с выбранными именами.
Нужны свободный диск/память, read-only registry access, Docker/Compose; host postfix и
public 25/587 listeners устанавливать не нужно. Root создаёт только отдельный directory,
после этого operator работает от deploy.

```bash
docker network inspect --format '{{index .Labels "com.docker.compose.project"}} {{range .IPAM.Config}}{{.Subnet}}{{end}}' \
  agelto_prod_agelto_network
test ! -e /srv/agelto/mail
install -d -o deploy -g deploy -m 0700 /srv/agelto/mail
install -o deploy -g deploy -m 0644 /srv/agelto/repository/docker/host/mail/compose.yml /srv/agelto/mail/compose.yml
install -o deploy -g deploy -m 0600 /srv/agelto/repository/docker/host/mail/mail.env.example /srv/agelto/mail/.env.mail
install -o deploy -g deploy -m 0644 /srv/agelto/repository/docker/host/mail/smtp-smoke.php /srv/agelto/bin/smtp-smoke.php
```

В `.env.mail` редактором задайте проверенный image digest, exact network/trust subnet и
forward-confirmed reverse hostname. `COMPOSE_PROFILES=` — default disabled; для обычного
startup включите `COMPOSE_PROFILES=smtp`. Уже запущенный container сменой flag не останавливается.
Не source-ите dotenv, не помещайте secret credentials в Compose environment.

От deploy:

```bash
cd /srv/agelto/mail
docker pull boky/postfix:5.1.0
docker image inspect --format '{{json .RepoDigests}}' boky/postfix:5.1.0
docker compose --env-file .env.mail -f compose.yml --profile smtp config --quiet
docker compose --env-file .env.mail -f compose.yml --profile smtp up -d --no-build --pull never --wait smtp
docker compose --env-file .env.mail -f compose.yml --profile smtp ps
docker exec agelto_mail-smtp-1 postqueue -p
docker exec -i agelto_prod-app-1 php < /srv/agelto/bin/smtp-smoke.php
docker inspect --format '{{json .HostConfig.PortBindings}}' agelto_mail-smtp-1
docker exec agelto_mail-smtp-1 cat /etc/opendkim/keys/agelto.ru.txt
```

Health должен стать healthy, queue initially empty, PortBindings `{}`. Envelope smoke
проверяет EHLO/MAIL/RCPT/RSET/QUIT, разрешённый sender и отказ неразрешённому sender (5xx).
Он **не отправляет DATA**, не создаёт message и не доказывает внешнюю доставку/DKIM validation.
Не печатайте `.private` key и не включайте его в Git. Для повторной установки сначала
protected snapshots собственных env/compose, затем config validation/scoped switch.

## Реальный внешний delivery gate

До `MAIL_MAILER=smtp` проверьте исходящий IPv4 TCP 25 к реальным recipient MX и DNS.
Не открывайте public inbound 25/587 и не отключайте firewall ради outbound проверки.
На 2026-09-12 три IPv4 MX connections дали errno 110 (timeout); вероятна блокировка
hosting/network path, но её источник не установлен. Нужно попросить VPS provider проверить
и разрешить **исходящий TCP 25**. DNS сам по себе TCP timeout не исправит.

При текущем hostname `flexl.ru` PTR `46.8.220.57 → flexl.ru` и forward A обратно на тот же
IP уже согласованы. Поэтому менять shared PTR/hostname не требуется. Если выбираете новый
`smtp.agelto.ru`, сначала A на server IP и согласованный provider PTR; не меняйте существующие
MX и `mail.agelto.ru` для отправки. Они относятся к входящей почте и сейчас ведут на другой IP.

Outbound identity:

- Один SPF TXT на `agelto.ru`, разрешающий server IP; для текущего нового sender
  `v=spf1 ip4:46.8.220.57 ~all`. Если SPF уже есть, объедините sources в **одну** запись,
  не добавляйте вторую и не исключайте работающих senders.
- TXT `agelto._domainkey.agelto.ru`: public record из собственного `.txt`, concatenate quoted
  fragments без лишних пробелов в `p=`. Это per-installation key, не универсальное значение.
- TXT `_dmarc.agelto.ru`: сначала `v=DMARC1; p=none; adkim=r; aspf=r`; это monitoring policy,
  не enforcement. Ужесточение после проверки всех legitimate senders — отдельное решение.

SPF/DKIM, forward/reverse DNS и TLS относятся к требованиям
[Gmail sender guidelines](https://support.google.com/mail/answer/81126).
Наличие records/SMTP acceptance не гарантирует inbox delivery или репутацию IP.
Не отправляйте тестовые письма произвольным получателям: нужен адрес, выбранный владельцем.

После working port 25/DNS и согласованного delivery smoke заполните `.env.production`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=agelto-smtp
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_EHLO_DOMAIN=flexl.ru
MAIL_FROM_ADDRESS=noreply@agelto.ru
```

Authentication не нужна только для **private trusted Agelto relay**. Для внешнего relay
получайте реальные credentials и храните в protected external env, не в arguments/logs.
В ignored host override общий PHP environment должен передавать MAIL_HOST/PORT/SCHEME/
EHLO_DOMAIN так же, как MAIL_MAILER/FROM из [manual deployment](manual-deployment.md).
Его values перекрывают external `.env`; не оставляйте там hardcoded log или чужой host.
Примените обычный scoped worker drain/recreate и bounded readiness; затем проверьте реальное
письмо на owner recipient, queue status, SPF/DKIM/DMARC в received headers.
До этого application mailer остаётся log; последний зафиксированный статус и границы
проверки приведены в [ручной инструкции](manual-deployment.md#последний-зафиксированный-результат-и-история).

## Выключение и rollback

Верните `MAIL_MAILER=log` и выполните scoped application restart по manual guide. SMTP
container можно сохранить работающим без отправки либо остановить только собственный:

```bash
cd /srv/agelto/mail
docker compose --env-file .env.mail -f compose.yml --profile smtp stop smtp
```

Убедитесь в empty queue; queued messages не удаляются. Env/profile переключение само не
останавливает running service. Не выполняйте down/prune/volume deletion. DKIM key сохраняется;
при future backup enable обновлённый payload включает encrypted DKIM и SMTP config archives.
