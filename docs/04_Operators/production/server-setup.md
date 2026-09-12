---
audience: operator
type: howto
status: living
---

# Новый production-сервер: Ubuntu 24.04 → готовый checkout Agelto

Для текущего режима **без CI** используйте [ручной сценарий](manual-deployment.md), затем
возвращайтесь к проверкам основного [руководства](deployment.md). На shared host эта страница
не является разрешением повторить bootstrap: сначала аудит, затем только отсутствующие шаги.
Не выполняйте blanket upgrade, не перезаписывайте `authorized_keys`, `.ssh/config`,
`known_hosts`, Docker daemon settings или firewall соседнего проекта. Личный SSH-ключ deploy
настраивает владелец отдельно; его отсутствие не мешает работе от root через `sudo -u deploy`.
Ubuntu 26.04 не проходит version guard `host:apply`: обходить guard или переустанавливать уже
работающий Docker нельзя. Проверьте имеющиеся инструменты и используйте manual shared-host ветку.

Это первая глава production-книги. Пройдите её сверху вниз **один раз** на пустом Ubuntu 24.04
`amd64` или `arm64`. Результат главы: на сервер можно войти ключом как `deploy`, Docker и firewall
настроены, NGINX принимает HTTPS, а точный commit Agelto лежит в `/srv/agelto/repository` вместе с
non-secret `.env.production` и файловыми секретами.

Дальше без пропусков: [первый запуск](deployment.md#4-первый-запуск-точная-последовательность),
затем [повторные релизы](deployment.md#5-повторный-релиз-уже-настроенного-окружения).

> **Почему не `/var/www/agelta`.** Исполняемый production-contract Agelto принимает checkout только
> ниже `/srv/agelto`. Поэтому в этой инструкции используется `/srv/agelto/repository`; путь
> `/var/www/agelta` сейчас будет отвергнут проверкой профиля. Это не косметическая деталь, а
> граница безопасности скрипта синхронизации репозитория.

## Содержание

1. [Значения, ключи и порядок работы](#0-выпишите-значения-до-подключения)
2. [Базовая ОС, Git и системные пакеты](#1-базовая-ос-git-и-bootstrap-пользователь)
3. [Первый GitHub deploy key и clone](#2-одноразовый-bootstrap-github-ключ-и-клонирование-agelto)
4. [Production profile и Docker Engine](#3-production-профиль-docker-и-безопасный-ssh)
5. [Отдельные ключи Agelto и AzGuard](#4-отдельные-ключи-agelto-и-azguard)
6. [Firewall и SSH](#5-firewall-и-окончательная-ssh-политика)
7. [NGINX](#6-nginx-как-reverse-proxy)
8. [Let's Encrypt](#7-ssl-lets-encrypt)
9. [`.env.production`](#8-production-env-файл)
10. [Файловые секреты и проверка Compose](#9-файловые-секреты-и-проверка-compose)
11. [Итоговая проверка](#10-проверка-готовности-хоста)

## 0. Выпишите значения до подключения

На рабочей станции выберите и сохраните в защищённом password manager следующие значения:

| Значение | Пример формата | Откуда берётся |
|---|---|---|
| `FQDN` | `agelto.example.com` | Домен, A/AAAA которого уже указывает на IP сервера. |
| `CANDIDATE_SHA` | 40 lowercase hex-символов | Commit готового к выпуску Agelto, не имя ветки. |
| `IMAGE_TAG` | `2026.09.09-<sha>` | Неизменяемый tag уже собранных app и doctor images. |
| `ADMIN_EMAIL` | рабочий email администратора | Будущий первый SuperAdmin. |
| registry password и URL backup-alert | отдельные секретные значения | Реестр образов и канал уведомлений; не попадают в `.env.production`. |

Сгенерируйте отдельный ключ **для входа инженера как `deploy`**. Это не GitHub deploy key:

```bash
# На рабочей станции, не на production-server.
ssh-keygen -t ed25519 -a 100 -f ~/.ssh/agelto-production-deploy -C 'agelto production deploy login'
ssh-copy-id -i ~/.ssh/agelto-production-deploy.pub root@SERVER_IP
```

Оставьте текущую root-сессию открытой до шага 5. Если на сервер нельзя входить root по ключу,
передайте файл `~/.ssh/agelto-production-deploy.pub` через консоль провайдера и положите его в
`/root/.ssh/agelto-deploy.pub` с правами `0600`.

## 1. Базовая ОС, Git и bootstrap-пользователь

Подключитесь как `root` и выполните ровно этот блок. Он не открывает порты и ещё не отключает
парольный вход.

```bash
apt-get update
apt-get upgrade -y
apt-get install -y ca-certificates curl git gnupg jq openssh-server ufw nginx certbot python3-certbot-nginx
timedatectl set-timezone Europe/Volgograd
timedatectl set-ntp true
systemctl enable --now ssh nginx

git --version
curl --version
nginx -v

install -d -m 0700 /root/.ssh
test -s /root/.ssh/agelto-deploy.pub
chmod 0600 /root/.ssh/agelto-deploy.pub

id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
install -m 0600 -o deploy -g deploy /root/.ssh/agelto-deploy.pub /home/deploy/.ssh/authorized_keys
stat -c '%U:%G %a %n' /home/deploy/.ssh /home/deploy/.ssh/authorized_keys
```

Перед следующим шагом проверьте операционную систему и новый вход. `host:apply` сознательно
принимает только Ubuntu 24.04 и `amd64`/`arm64`; на другой версии он остановится.

```bash
. /etc/os-release
printf '%s %s\n' "$ID" "$VERSION_ID"
dpkg --print-architecture
timedatectl show -p NTPSynchronized --value
```

Ожидаются `ubuntu 24.04`, `amd64` или `arm64` и `yes`. Во **втором терминале** на рабочей станции
проверьте именно новый ключ; не продолжайте, если SSH просит пароль:

```bash
ssh -i ~/.ssh/agelto-production-deploy -o IdentitiesOnly=yes \
  deploy@SERVER_IP 'id && hostnamectl --static'
```

Ожидается `uid=... (deploy)`. Root-сессию пока не закрывайте.

## 2. Одноразовый bootstrap GitHub-ключ и клонирование Agelto

У production нет личного GitHub token. Создайте отдельный read-only deploy key репозитория
`axiomasoft/agelto`; его приватная часть остаётся у пользователя `deploy`.

```bash
sudo -u deploy env HOME=/home/deploy ssh-keygen -q -t ed25519 -N '' \
  -C 'agelto bootstrap repository key' -f /home/deploy/.ssh/agelto_bootstrap_ed25519
sudo -u deploy cat /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
```

Добавьте напечатанный public key в GitHub: **Agelto repository → Settings → Deploy keys → Add deploy
key**, флаг write access оставьте выключенным. Затем зафиксируйте GitHub host key. Команда получает
только опубликованный ключ GitHub API и немедленно сверяет его известный fingerprint; `ssh-keyscan`
для этого не используйте.

```bash
GITHUB_KEY="$(curl -fsSL https://api.github.com/meta | jq -r '.ssh_keys[] | select(startswith("ssh-ed25519 "))' | head -n 1)"
printf 'github.com %s\n' "$GITHUB_KEY" > /home/deploy/.ssh/known_hosts
chown deploy:deploy /home/deploy/.ssh/known_hosts
chmod 0600 /home/deploy/.ssh/known_hosts
ssh-keygen -lf /home/deploy/.ssh/known_hosts -E sha256
```

Fingerprint должен быть `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`. Если он иной,
остановитесь и сравните ключ с [официальной страницей GitHub](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).

Теперь создайте checkout и переключите его на заранее выбранный commit:

```bash
install -d -m 0755 -o deploy -g deploy /srv/agelto
sudo -u deploy env HOME=/home/deploy GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes' \
  git clone git@github.com:axiomasoft/agelto.git /srv/agelto/repository

sudo -u deploy git -C /srv/agelto/repository fetch --no-tags origin "$CANDIDATE_SHA"
sudo -u deploy git -C /srv/agelto/repository checkout --detach "$CANDIDATE_SHA"
sudo -u deploy git -C /srv/agelto/repository rev-parse HEAD
sudo -u deploy git -C /srv/agelto/repository status --short
```

Последняя строка должна побайтно совпасть с `CANDIDATE_SHA`. В шаге 4 проект заменит bootstrap SSH
на две раздельные pinned identities: для Agelto и закрытого AzGuard dependency.

## 3. Production-профиль, Docker и безопасный SSH

От root создайте два отдельных файла. Docker Hub token читает именно `deploy`, потому что этот
пользователь выполняет `docker pull`; alert URL читает root-only backup-carrier. Их содержимое зависит
от registry/канала и поэтому не хранится ни в Git, ни в документации:

```bash
install -d -m 0700 -o deploy -g deploy /home/deploy/.config/agelto
install -m 0600 -o deploy -g deploy /dev/null /home/deploy/.config/agelto/registry-password
sudo -u deploy editor /home/deploy/.config/agelto/registry-password

install -d -m 0700 /root/.config/agelto
sudoedit /root/.config/agelto/backup-alert-url
chmod 0600 /root/.config/agelto/backup-alert-url
stat -c '%U:%G %a %n' /home/deploy/.config/agelto/registry-password /root/.config/agelto/backup-alert-url
```

Скопируйте profile и заполните **все** строки. `GITHUB_ED25519_HOST_KEY` — это base64-часть
`$GITHUB_KEY` из шага 2, без начального `ssh-ed25519`.

```bash
cd /srv/agelto/repository
cp docker/host/production-profile.example docker/host/production-profile
chmod 0600 docker/host/production-profile
sudoedit docker/host/production-profile
```

В результате в profile должны быть, в частности, эти конкретные значения:

```ini
DEPLOY_USER=deploy
DEPLOY_AUTHORIZED_KEY_FILE=/root/.ssh/agelto-deploy.pub
SSH_PORT=22
PUBLIC_HTTP_PORT=80
PUBLIC_HTTPS_PORT=443
APP_BIND_ADDRESS=127.0.0.1
APP_BIND_PORT=8080
DOCKER_DATA_ROOT=/var/lib/docker
REGISTRY_PASSWORD_FILE=/home/deploy/.config/agelto/registry-password
BACKUP_ALERT_URL_FILE=/root/.config/agelto/backup-alert-url
AGELTO_REPOSITORY=axiomasoft/agelto
AGELTO_CANDIDATE_SHA=<ваш CANDIDATE_SHA>
AGELTO_CHECKOUT_DIR=/srv/agelto/repository
GITHUB_ED25519_HOST_KEY=<base64-часть GITHUB_KEY>
```

`DISK_MIN_FREE_GB` задайте по реальной ёмкости сервера, но не меньше `20`. Затем запустите
проверку без изменений и только потом apply:

```bash
sudo make -f docker/Makefile host:check PROFILE=docker/host/production-profile
sudo make -f docker/Makefile host:apply PROFILE=docker/host/production-profile CONFIRM=apply
```

`host:apply` устанавливает Docker Engine, Buildx и Compose, добавляет только `deploy` в группу
`docker`, включает security updates и записывает безопасный Docker daemon config. Группа `docker`
равна root-привилегиям: не добавляйте в неё никого ещё.

Проверьте новую Docker-группу в **новой** сессии `deploy`; в старой она ещё не видна:

```bash
ssh -i ~/.ssh/agelto-production-deploy -o IdentitiesOnly=yes deploy@SERVER_IP \
  'id; docker ps; docker info --format "{{.LoggingDriver}}"'
```

Ожидается `local` как logging driver. Контейнеров на новом сервере пока нет — это нормально.

## 4. Отдельные ключи Agelto и AzGuard

Ключ из раздела 2 — bootstrap-помощник. Проектная команда создаёт две разные identities: первая
может читать только Agelto, вторая — только приватную зависимость AzGuard. Скрипт закрепляет host
key GitHub, выключает agent forwarding и проверяет, что у ключей нет push-доступа.

```bash
cd /srv/agelto/repository
make -f docker/Makefile repositories:prepare PROFILE=docker/host/production-profile

sudo -u deploy ssh-keygen -lf /home/deploy/.ssh/agelto_deploy_ed25519.pub
sudo -u deploy ssh-keygen -lf /home/deploy/.ssh/azguard_deploy_ed25519.pub
```

Добавьте **первый** напечатанный public key в GitHub repository Agelto: `Settings → Deploy keys →
Add deploy key`. Второй добавьте в repository из `docker/private-vendors.lock.json`. В обоих случаях
не включайте `Allow write access`. Затем выполните синхронизацию; она повторно получает точный
commit и приватную зависимость, проверяет чистоту checkout и read-only доступ:

```bash
cd /srv/agelto/repository
make -f docker/Makefile repositories:sync PROFILE=docker/host/production-profile
sudo -u deploy git -C /srv/agelto/repository rev-parse HEAD
sudo -u deploy git -C /srv/agelto/repository status --short
```

Последняя команда ничего не выводит, а SHA совпадает с `CANDIDATE_SHA`. Теперь удалите временный
bootstrap key из GitHub Deploy keys и его private/public файлы с сервера:

```bash
rm -f /home/deploy/.ssh/agelto_bootstrap_ed25519 \
  /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
```

## 5. Firewall и окончательная SSH-политика

После успешной проверки входа из раздела 1 включите key-only SSH и firewall, не закрывая исходный
root-terminal. В сеть открываются только SSH, HTTP и HTTPS; PostgreSQL, Redis, MinIO и порт app
никогда не добавляются в UFW.

```bash
cd /srv/agelto/repository
install -m 0644 docker/host/sshd_config.d/90-agelto.conf /etc/ssh/sshd_config.d/90-agelto.conf
sshd -t
systemctl reload ssh

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
ufw status verbose
```

В отдельном терминале снова войдите ключом как `deploy`. Лишь после этого можно закрыть root-сессию:

```bash
ssh -i ~/.ssh/agelto-production-deploy -o IdentitiesOnly=yes deploy@SERVER_IP 'id; docker ps'
```

## 6. NGINX как reverse proxy

Agelto app слушает только `127.0.0.1:8080`; наружу открывается только NGINX. Сначала создайте
HTTP-конфигурацию для ACME challenge, подставив реальный FQDN:

```bash
FQDN=agelto.example.com
install -d -m 0755 /var/lib/letsencrypt
cat >/etc/nginx/sites-available/agelto-http <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${FQDN};
    location /.well-known/acme-challenge/ { root /var/lib/letsencrypt; }
    location / { proxy_pass http://127.0.0.1:8080; proxy_set_header Host \$host; proxy_set_header X-Forwarded-Proto http; }
}
EOF
ln -sfn /etc/nginx/sites-available/agelto-http /etc/nginx/sites-enabled/agelto-http
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

Проверьте DNS до выпуска сертификата. Адрес в первом выводе должен быть IP этого сервера; `404` во
втором ответе допустим, но запрос не должен попадать на другой хост:

```bash
getent ahosts "$FQDN"
curl -I "http://${FQDN}/.well-known/acme-challenge/ping"
```

## 7. SSL (Let's Encrypt)

Теперь выпустите сертификат. Укажите реальный email дежурной команды вместо примера:

```bash
certbot certonly --webroot -w /var/lib/letsencrypt -d "$FQDN" \
  --email ops@company.example --agree-tos --no-eff-email
```

Установите проверенный шаблон Agelto с полученным сертификатом и private key:

```bash
sed \
  -e "s/__AGELTO_FQDN__/${FQDN}/g" \
  -e "s#__AGELTO_TLS_CERTIFICATE__#/etc/letsencrypt/live/${FQDN}/fullchain.pem#g" \
  -e "s#__AGELTO_TLS_PRIVATE_KEY__#/etc/letsencrypt/live/${FQDN}/privkey.pem#g" \
  docker/host/nginx/agelto.conf >/etc/nginx/sites-available/agelto
ln -sfn /etc/nginx/sites-available/agelto /etc/nginx/sites-enabled/agelto
rm -f /etc/nginx/sites-enabled/agelto-http
nginx -t && systemctl reload nginx
certbot renew --dry-run
```

Проверьте с рабочей станции: `curl -I http://$FQDN` даёт redirect, а
`curl -I https://$FQDN` получает ответ от application. До первого запуска app возможен `502` — это
нормально; после главы запуска он обязан исчезнуть. PostgreSQL, Redis, MinIO и `8080` не должны
быть доступны снаружи.

## 8. Production env-файл

Перейдите под `deploy`, создайте **один** non-secret Compose env-файл и явно задайте production
значения. Не переносите в него `APP_KEY`, DB/Redis/MinIO passwords или initial-admin password.

```bash
sudo -u deploy env HOME=/home/deploy bash -lc '
  cd /srv/agelto/repository
  cp .env.example .env.production
  cat .env.docker.production.example >> .env.production
  chmod 0600 .env.production
'
sudo -u deploy editor /srv/agelto/repository/.env.production
```

В редакторе измените как минимум:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://agelto.example.com
SESSION_DRIVER=redis
ADMIN_EMAIL=admin@your-company.example
IMAGE_TAG=2026.09.09-a1b2c3d4
COMPOSE_PROJECT_NAME=agelto_prod
```

Оставьте `APP_KEY=`, `DB_PASSWORD=`, `REDIS_PASSWORD=`, `AWS_SECRET_ACCESS_KEY=` и
`MINIO_ROOT_PASSWORD=` пустыми. Замените пример домена, email и `IMAGE_TAG` своими значениями;
не создавайте дубликаты строк. Секреты создаются только как файлы с `0600`:

## 9. Файловые секреты и проверка Compose

```bash
sudo -u deploy env HOME=/home/deploy bash -lc '
  cd /srv/agelto/repository
  make -f docker/Makefile secrets:init
  find local/secrets -maxdepth 1 -type f -printf "%f %m\\n" | sort
  make -f docker/Makefile infra:preflight MODE=prod-runtime ENV_FILE=.env.production
  make -f docker/Makefile prod:config \
    ENV_FILE=.env.production \
    IMAGE_TAG=2026.09.09-a1b2c3d4 \
    COMPOSE_PROJECT_NAME=agelto_prod
'
```

Ожидаются файлы `app_key`, `db_password`, `redis_password`, `redis_acl`, `minio_root_password` и
`admin_initial_password`; содержимое никогда не выводите. Повторный `secrets:init` сохраняет уже
существующие bytes. Зелёные preflight/config означают, что сервер подготовлен к первой поставке
готового immutable image; далее продолжайте с [первым запуском](deployment.md#4-первый-запуск-последовательность-и-контрольные-точки).

## 10. Проверка готовности хоста

Перезагрузите сервер: это проверяет автоматический запуск Docker, UFW и SSH не в текущей сессии.
После восстановления SSH войдите как `deploy` и выполните:

```bash
sudo make -f /srv/agelto/repository/docker/Makefile host:preflight \
  PROFILE=/srv/agelto/repository/docker/host/production-profile
docker ps
ss -lnt
curl -I "https://${FQDN}"
```

Готовый хост даёт `[PASS]` у `host:preflight`; `docker ps` не требует `sudo`; публично доступны
только 22, 80 и 443. До первого запуска приложения HTTPS может вернуть `502`, но сертификат уже
должен быть валиден. Следующий шаг — только [первый запуск](deployment.md#4-первый-запуск-последовательность-и-контрольные-точки); после него используйте [сценарии повторного релиза](deployment.md#5-повторный-релиз-уже-настроенного-окружения), а не проходите эту главу заново.
