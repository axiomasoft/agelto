# Отчёт практического развёртывания Agelto

Дата начала: 2026-09-12

Целевой сервер: `46.8.220.57:22`

Ветка: `main`

Итоговый статус: HTTPS работает, шесть сервисов Agelto healthy, readiness 7/7 passed,
Doctor без failures/warnings. Миграции завершены после подтверждения владельца, BaseSeeder
повторно выполнен в конце, вход SuperAdmin проверен в браузере. PostgreSQL backup реально
восстановлен в отдельную rehearsal DB. Ниже сохранены также промежуточные неуспехи и остановки;
они не описывают итоговое состояние. Первый documentation-only коммит `366aba5e` затем
опубликован по отдельному явному подтверждению владельца. Продолжение настройки после этого
коммита записывается в конце отчёта; новый коммит без отдельного указания не публикуется.
Актуальный handoff продолжения — последний раздел: Gemini key подключён, но API не проверен
из-за отсутствия LLM proxy. По последующему решению владельца backup feature установлен,
но выключен через `.env.backup` / `BACKUP_ENABLED=false`; старые encrypted copies сохранены.
Own SMTP подготовлен, mailer остаётся log: внешние IPv4 TCP 25 connections дают timeout.
Ранние списки pending относятся к моменту соответствующего этапа, не к текущему состоянию.

> Отчёт содержит выполненные команды и проверяемые факты, но не содержит паролей, приватных
> ключей, токенов и значений application secrets. SSH-пароль вводился интерактивно и нигде не
> сохранялся.

## Рабочая инструкция

Основная инструкция: `docs/04_Operators/production/deployment.md`.

Путь первого размещения: `docs/04_Operators/production/server-setup.md` →
`docs/04_Operators/production/deployment.md`. Также до подключения прочитаны связанные страницы
`docs/04_Operators/production/`, Docker-документация из `docs/03_Dev/docker/`,
`docs/03_Dev/process/deploy.md`, production Compose и шаблоны окружения/host/Nginx.

## Этап 1. Read-only аудит

Подключение выполнено интерактивно:

```bash
ssh -p 22 root@46.8.220.57
```

Пароль не передавался в аргументах команды и не записывался в shell history. После входа выполнено
`set +o history`.

### Выполненные команды

```bash
cat /etc/os-release
uname -a
hostnamectl
df -hT /
df -hT /var/lib/docker
free -h
swapon --show --bytes
uptime

command -v docker
docker version
docker compose version
docker info --format 'root={{.DockerRootDir}} driver={{.Driver}} logging={{.LoggingDriver}} cgroup={{.CgroupDriver}}'
systemctl is-enabled docker
systemctl is-active docker
docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
docker compose ls -a
docker network ls
docker volume ls
ss -lntup

command -v nginx
nginx -v
nginx -t
find /etc/nginx/sites-available /etc/nginx/sites-enabled -maxdepth 1 -mindepth 1 -printf '%M %u:%g %p -> %l\n'
grep -RHE '^[[:space:]]*(listen|server_name|proxy_pass|ssl_certificate|ssl_certificate_key)[[:space:]]' /etc/nginx/sites-available /etc/nginx/sites-enabled
command -v certbot
certbot certificates
find /etc/letsencrypt/live -mindepth 1 -maxdepth 1 -type d -printf '%f\n'

ufw status verbose
iptables -S DOCKER-USER
nft list ruleset

getent passwd deploy
id deploy
groups deploy
sudo -l -U deploy
namei -l /home/deploy
find /home/deploy -maxdepth 2 -mindepth 1 -type d -printf '%M %u:%g %p\n'
find /home/deploy/.ssh -maxdepth 1 -mindepth 1 -printf '%f|%y|%m|%u:%g\n'

git --version
curl -fsSIL --max-time 15 https://github.com/
find /srv /opt /var/www /home/deploy -maxdepth 3 -mindepth 1 -type d -printf '%M %u:%g %p\n'
systemctl --no-pager --type=service --state=running
systemctl list-timers --all --no-pager

docker inspect --format '{{.Name}}|project={{index .Config.Labels "com.docker.compose.project"}}|workdir={{index .Config.Labels "com.docker.compose.project.working_dir"}}|files={{index .Config.Labels "com.docker.compose.project.config_files"}}' insiders_app dterema_backend-app-1 dterema_nuxt_prod
# Первоначально networks инспектировались по отдельности; точная повторная команда ниже.
docker compose --project-name dterema_app -f /var/www/dterema/web/docker-compose.prod.yml config --services
docker compose --project-name dterema_backend -f /var/www/dterema/cms/docker-compose.prod.yml config --services
docker compose --project-name insiders -f /var/www/insiders/docker-compose.prod.yml config --services
```

### Фактическое исходное состояние

| Проверка | Факт | Результат для Agelto |
|---|---|---|
| ОС | Ubuntu 26.04 LTS, x86_64, kernel 7.0.0-29 | Расходится с жёстким prerequisite Ubuntu 24.04 в документации/`host:apply`; повторный host bootstrap не запускается. |
| Ресурсы | `/`: 99 GiB, свободно 65 GiB; RAM 9.6 GiB, доступно 7.6 GiB; swap отсутствует | Disk floor проходит; отсутствие swap нужно отразить как capacity-риск для тяжёлых jobs. |
| Docker | Engine 29.6.2, Compose 5.3.1, active/enabled; root `/var/lib/docker`; logging driver `json-file` | Docker готов, но logging driver расходится с документированным `local`. Не менять daemon глобально без отдельной оценки влияния на соседние проекты. |
| Существующие проекты | `dterema_app`, `dterema_backend`, `insiders`; 12 running и 2 успешно завершённых контейнера | Не останавливать и не пересоздавать. Использовать отдельный project name. |
| Сети | Заняты Compose-сети на `172.18.0.0/16`, `172.19.0.0/16`, `172.20.0.0/16` | Compose автоматически выделит отдельную project-scoped сеть; фиксированную subnet не задавать. |
| Volumes | `dterema_backend_*`, `insiders_*`; Agelto volumes отсутствуют | Имена `agelto_prod_*` не конфликтуют. |
| Порты | Публичны 22/80/443; loopback заняты 3000, 3306, 4174, 5433, 6381, 8000, 8080, 8081 | Для Agelto выбраны loopback 8082, 5434, 6382, 9002, 9003. |
| NGINX | 1.28.3, `nginx -t` успешен; enabled: `cms.dterema.localmost.ru`, `dterema.localmost.ru`, `insiders.localmost.ru` | Создавать только отдельный Agelto site; default не enabled и не должен удаляться. |
| TLS | Certbot 4.0.0; действующие сертификаты трёх соседних доменов | Не изменять. Agelto certificate выпускать только после получения FQDN и проверки DNS. |
| Firewall | UFW inactive; цепочка `DOCKER-USER` пустая | Внешне сейчас опубликованы только 22/80/443, data-порты loopback. Глобальную firewall policy не менять в рамках изолированного deploy без согласованного окна. |
| `deploy` | uid/gid 1000, home `/home/deploy`, группы `deploy users docker`, sudo отсутствует | Docker доступ уже есть; широкие sudo-права не нужны. |
| SSH `deploy` | `.ssh` принадлежит `deploy`; есть отдельные ключи соседних проектов; `authorized_keys` пуст | Личные ключи не создавать/не менять. Для Agelto использовать новый отдельный файл. |
| Git/GitHub | Git 2.53.0; HTTPS к GitHub вернул HTTP 200 | Сетевой доступ есть; private repository требует отдельный read-only deploy key. |
| Каталоги | `/srv/agelto` отсутствует; проекты расположены в `/var/www/dterema/**` и `/var/www/insiders` | Безопасный отдельный путь — `/srv/agelto/repository`. |

### Сверка инструкции

| Шаг инструкции | Факт на сервере | Результат | Что уточнить в документации |
|---|---|---|---|
| Ubuntu host bootstrap | Host уже настроен, Ubuntu 26.04 вместо 24.04 | Повторный `host:apply` небезопасен и не нужен | Добавить ветку existing shared host и запрет глобального apply при соседних проектах. |
| Docker/Compose | Уже установлены и используются тремя Compose-проектами | Готово | Описать проверку существующих project names, ports, networks, volumes и logging driver до deploy. |
| `deploy` | Уже в root-equivalent группе `docker`, без sudo | Готово | Для готового пользователя не требовать пересоздание или sudo; явно отметить Docker privilege. |
| Каталог checkout | `/srv/agelto` отсутствует | Требуется создать | Добавить ownership/mode и безопасный rollback только нового пустого каталога. |
| Repository access | Есть ключи других репозиториев, Agelto key отсутствует | Требуется отдельный key | Не перезаписывать `~/.ssh/config`/`known_hosts`; добавлять отдельный alias/identity. |
| Production ports | Документированные defaults частично заняты | Требуется override | Добавить матрицу выбора свободных loopback ports и синхронизацию NGINX upstream/profile/env. |
| NGINX/TLS | Три действующих vhost и certificate lineage | Готова общая платформа | Не удалять default/чужие sites; использовать новый файл и symlink, backup только своего target. |
| Firewall | UFW выключен, Docker ports loopback | Глобальная правка отложена | Не применять fresh-host UFW/DOCKER-USER recipe без анализа соседних published ports и окна. |

## Выбранная изоляция Agelto

Уточнение документации от 2026-09-13: Agelto — веб-сервис, парсинг — его текущая
реализованная функциональность. `/srv/agelto` выбран как отдельный deployment root рядом
с существующими веб-проектами в `/var/www`, а не из-за назначения Agelto. Незанятый
`/var/www/agelto` тоже допустим; изоляцию обеспечивает конфигурация ресурсов, не имя parent
directory. Фактический checkout остаётся `/srv/agelto/repository`; container app root —
`/var/www/html`. Перенос на сервере не выполнялся и не заявляется проверенным.

Проверки этой правки выполнены локально: `git diff --check`,
`pnpm documentation:build`, `pnpm documentation:verify` (312 файлов) и
`python3 docker/scripts/check-command-reference.py check --output DEPLOYMENT_COMMAND_MATRIX.md`
завершились успешно. SSH-команды в рамках этой правки не выполнялись; серверная копия
отчёта не обновлялась. Ниже сохранены факты и команды предыдущих этапов деплоя.

| Ресурс | Значение |
|---|---|
| Checkout | `/srv/agelto/repository` |
| Compose project | `agelto_prod` |
| App bind | `127.0.0.1:8082` |
| PostgreSQL bind | `127.0.0.1:5434` |
| Redis bind | `127.0.0.1:6382` |
| MinIO API / console | `127.0.0.1:9002` / `127.0.0.1:9003` |
| Network | `agelto_prod_agelto_network` (Compose-managed) |
| Volumes | `agelto_prod_pgsql_data`, `agelto_prod_redis_data`, `agelto_prod_minio_data`, `agelto_prod_app_storage` |

## Rollback-карта до начала изменений

| Изменение | Безопасный откат |
|---|---|
| Новый checkout до запуска контейнеров | Сохранить каталог; не удалять. После запуска использовать совместимый image rollback ниже. |
| Новый Agelto deploy key | При необходимости владелец отзывает только Agelto key в GitHub; private key на сервере сохраняется, чужие SSH-файлы не менять. |
| Compose startup | Scoped stop только Agelto app/Horizon/scheduler либо compatible image switch; без down, удаления containers/networks/volumes. |
| Новый NGINX site | Восстановить только сохранённый Agelto config и выполнить `nginx -t`, reload; при отмене первого site сохранить его и убрать только enabled symlink. |
| Agelto TLS lineage | Не выпускать до корректного DNS; при откате NGINX не удалять сертификат автоматически. |
| Миграция | До owner gate не запускать. После применения — только совместимый image rollback; destructive schema rollback запрещён без отдельного плана. |

## Этап 2. Каталог и bootstrap deploy key

Созданы только отсутствующие Agelto-ресурсы; существующие SSH identities пользователя `deploy` не
изменялись.

```bash
test ! -e /home/deploy/.ssh/agelto_bootstrap_ed25519 \
  && test ! -e /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
install -d -m 0755 -o deploy -g deploy /srv/agelto
sudo -u deploy env HOME=/home/deploy ssh-keygen -q -t ed25519 -N '' \
  -C 'agelto bootstrap repository key' \
  -f /home/deploy/.ssh/agelto_bootstrap_ed25519
stat -c '%U:%G %a %n' \
  /srv/agelto \
  /home/deploy/.ssh/agelto_bootstrap_ed25519 \
  /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
ssh-keygen -lf /home/deploy/.ssh/agelto_bootstrap_ed25519.pub -E sha256
cat /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
```

Проверено:

- `/srv/agelto` — `deploy:deploy`, mode `0755`;
- private key — `deploy:deploy`, mode `0600`, содержимое не выводилось;
- public key — `deploy:deploy`, mode `0644`;
- fingerprint — `SHA256:SxHphL9wm3SLhJ/OwAaSJyyTVCzNSFVpZ38ao5P5oCk`.

Стоп-гейт: public key передан владельцу для добавления в GitHub как read-only deploy key. Проверка
доступа и clone не выполнялись до явного подтверждения владельца.

После подтверждения выполнено:

```bash
sudo -u deploy env HOME=/home/deploy \
  GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes' \
  git ls-remote git@github.com:axiomasoft/agelto.git refs/heads/main

sudo -u deploy env HOME=/home/deploy \
  GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes' \
  git clone --branch main --single-branch \
  git@github.com:axiomasoft/agelto.git /srv/agelto/repository

sudo -u deploy git -C /srv/agelto/repository config core.sshCommand \
  'ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes'
sudo -u deploy git -C /srv/agelto/repository pull --ff-only
```

Фактический результат:

- GitHub read прошёл, `main` указывает на `9a2a8225f96ba7e6915186e9bdc5298a7d39ee38`;
- checkout создан на ветке `main`, рабочее дерево чистое;
- повторный `git pull --ff-only` от `deploy` вернул `Already up to date`;
- SSH identity закреплена только в `.git/config` Agelto, глобальный `~deploy/.ssh/config` не менялся.

## Этап 3. Production carriers и private vendor

После clone проинспектированы фактические `docker-compose.prod.yml`, `.env.example`,
`.env.docker.production.example`, `docker/host/production-profile.example`, NGINX template,
`docker/Makefile`, repository/preflight/vendor scripts и CI workflow. На сервере отсутствует `rg`,
поэтому один диагностический поиск завершился `rg: command not found`; установка для deploy не нужна.

Проверка зависимости и синхронизация:

```bash
sudo -u deploy env HOME=/home/deploy GIT_TERMINAL_PROMPT=0 \
  git ls-remote --exit-code https://github.com/axiomasoft/azguard-private.git HEAD
sudo -u deploy env HOME=/home/deploy bash docker/scripts/vendor-sync.sh
```

Результат: repository читается по HTTPS без credentials, HEAD совпадает с lock SHA
`7e7009cc7c26e173cfa6bfec42365a311fc2adb0`; checkout создан в игнорируемом
`local/vendors/azguard` и проверен на точный SHA. Второй deploy key не создавался.

Практическое расхождение: `repositories:prepare` безусловно создаёт два ключа и заменяет целиком
`/home/deploy/.ssh/config` и `/home/deploy/.ssh/known_hosts`. На shared host это могло бы сломать
SSH-доступ соседних проектов; helper не запускался. Инструкция должна предусматривать публичный
vendor и project-local SSH identity без перезаписи общих файлов.

Проверка документированного CI image tag:

```bash
candidate_tag=ci-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38
docker manifest inspect "agelto/app:$candidate_tag"
docker manifest inspect "agelto/doctor:$candidate_tag"
```

Оба manifest отсутствуют. `.github/workflows/ci.yml` собирает image pair, но не публикует его в
registry. Без предоставленного immutable tag или согласованного build-and-transfer пути
`prod:image:promote` и запуск application services невозможны.

## Этап 4. DNS, NGINX и TLS

Владелец сообщил FQDN `agelto.ru`. Проверка:

```bash
getent ahostsv4 agelto.ru
getent ahostsv6 agelto.ru
```

A-запись вернула ровно `46.8.220.57`; отдельная AAAA-запись не обнаружена.

Создан отдельный HTTP-vhost `/etc/nginx/sites-available/agelto.ru.conf`, включён symlink
`/etc/nginx/sites-enabled/agelto.ru.conf`, upstream — `127.0.0.1:8082`. Default и соседние sites не
менялись. До TLS сохранена обратимая копия:

```bash
cp -a /etc/nginx/sites-available/agelto.ru.conf \
  /etc/nginx/sites-available/agelto.ru.conf.before-tls-20260912
```

TLS выпущен через уже существующий ACME account сервера:

```bash
certbot certonly --webroot -w /var/lib/letsencrypt \
  -d agelto.ru --non-interactive --agree-tos
```

Tracked template подготовлен с FQDN, certificate paths и реальным shared-host upstream:

```bash
sed \
  -e 's/__AGELTO_FQDN__/agelto.ru/g' \
  -e 's#__AGELTO_TLS_CERTIFICATE__#/etc/letsencrypt/live/agelto.ru/fullchain.pem#g' \
  -e 's#__AGELTO_TLS_PRIVATE_KEY__#/etc/letsencrypt/live/agelto.ru/privkey.pem#g' \
  -e 's#127.0.0.1:8080#127.0.0.1:8082#g' \
  docker/host/nginx/agelto.conf > /etc/nginx/sites-available/agelto.ru.conf.next
```

После визуальной проверки candidate был установлен, затем выполнены:

```bash
nginx -t
systemctl reload nginx
systemctl is-active nginx
curl --max-time 15 http://agelto.ru/
curl --max-time 15 https://agelto.ru/
openssl s_client -connect agelto.ru:443 -servername agelto.ru
certbot renew --dry-run
```

Фактический результат:

- `nginx -t` успешен, NGINX active;
- HTTP возвращает `301` на HTTPS;
- certificate CN `agelto.ru`, срок `2026-09-12` — `2026-12-11`, TLS verification result `0`;
- HTTPS возвращает ожидаемый `502`, потому что app на `127.0.0.1:8082` ещё не запущен;
- simulated renewal успешен для Agelto и всех трёх соседних сертификатов;
- соседние сайты после reload вернули прежние рабочие статусы `200`, `200`, `302`;
- NGINX 1.28.3 выдаёт предупреждение о deprecated `listen ... http2` из tracked Agelto template;
  синтаксическая проверка при этом успешна. Документации нужен ожидаемый результат и современная
  ветка синтаксиса.

Временный `.next` удалён после успешной установки; backup HTTP-конфигурации сохранён.

## Ручной build-on-host: production env и внутренние secrets

Владелец явно выбрал ручной deploy без CI: pull `main` и сборка на этом сервере. Это отдельная
ветка процесса; `prod:image:promote` не запускался, поскольку он пытается скачать отсутствующую
image pair из Docker Hub.

Выполнено из `/srv/agelto/repository`:

```bash
sudo -u deploy git pull --ff-only
sudo -u deploy git rev-parse HEAD
sudo -u deploy git check-ignore -v .env.production local/secrets/app_key
test ! -e .env.production
install -o deploy -g deploy -m 0600 .env.example .env.production
sudo -u deploy sed -i \
  -e 's|^APP_ENV=.*|APP_ENV=production|' \
  -e 's|^APP_DEBUG=.*|APP_DEBUG=false|' \
  -e 's|^APP_URL=.*|APP_URL=https://agelto.ru|' \
  -e 's|^LOG_LEVEL=.*|LOG_LEVEL=warning|' \
  -e 's|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|' \
  -e 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=agelto.ru|' \
  -e 's|^REDIS_PORT=.*|REDIS_PORT=6382|' \
  -e 's|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS="noreply@agelto.ru"|' \
  .env.production
```

Через `sudo -u deploy tee -a .env.production >/dev/null` добавлены несекретные production-параметры:

```dotenv
COMPOSE_PROJECT_NAME=agelto_prod
IMAGE_TAG=manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38
APP_BIND_ADDRESS=127.0.0.1
APP_PORT=8082
PGSQL_PORT=5434
MINIO_PORT=9002
MINIO_CONSOLE_PORT=9003
MINIO_ROOT_USER=agelto
MINIO_BUCKET=agelto
MINIO_ESTIMATES_BUCKET=agelto-estimates
HORIZON_STOP_GRACE_PERIOD=4200s
SETTINGS_CACHE_ENABLED=false
SETTINGS_CACHE_MEMO=false
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

`REDIS_PORT=6382` здесь задаёт только host publication: Compose передаёт приложению внутренний
`REDIS_PORT=6379`. `DB_PORT=5432` остаётся внутренним; `PGSQL_PORT=5434` — host publication.
`ADMIN_EMAIL=admin@agelto.ru` сохранён из project example, а не подтверждён как существующий mailbox.
`MAIL_MAILER=log`; внешние LLM/SMTP/Sentry/Telegram credentials не заполнялись и не выдумывались.

```bash
chown deploy:deploy .env.production
chmod 0600 .env.production
stat -c '%U:%G %a %n' .env.production
sudo -u deploy make secrets:init
find local/secrets -maxdepth 1 -type f ! -name '.gitignore' \
  -printf '%f %u:%g %m %s-bytes\n' | sort
sudo -u deploy git status --short --branch
sudo -u deploy make infra:preflight MODE=prod-runtime ENV_FILE=.env.production
```

Результат: `.env.production` — `deploy:deploy 0600`; штатно сгенерированы шесть ignored-файлов
`admin_initial_password`, `app_key`, `db_password`, `minio_root_password`, `redis_acl`,
`redis_password`, все `deploy:deploy 0600`. Содержимое не выводилось. Tracked tree чистое.
Preflight вернул `PASS HOST_TOOLS`, `VENDOR_LOCK`, `ENV_SCHEMA`, `SECRET_CONTRACT`,
`HOST_RESOURCES`, `COMPOSE_CONFIG` и `Preflight passed (prod-runtime)`.

### Обнаруженные build prerequisites и registry-развилка

```bash
sudo -u deploy make image:build \
  IMAGE_TAG=manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38
```

Попытка завершилась до Docker build: `composer: command not found` (script line 40). Скрипт
выполняет `composer install --dry-run --no-dev --no-scripts --no-interaction --no-progress
--prefer-dist`, а не только синтаксическую проверку Composer config. PHP/Composer на хосте отсутствуют.

Для изоляции от соседних проектов проверен Composer из того же upstream image, что в Dockerfile:

```bash
sudo -u deploy docker run --rm --entrypoint composer \
  serversideup/php:8.5-fpm-nginx --version
```

Получены PHP `8.5.10`, Composer `2.10.3`; digest upstream image:
`sha256:8f8c2f010ac5082ff3b42dbd1c2b2a77aa8a7ee0adb96d49920f32f45ae730e8`.
Создан отдельный executable `/srv/agelto/bin/composer` (`deploy:deploy 0755`), запускающий Composer
через `docker run --rm --entrypoint composer --user <deploy uid:gid>` с текущим checkout как volume
и workdir. PATH передавался только этой команде make; profile и системные PHP/Composer не менялись.

Повторная `image:build` с таким PATH завершилась на настоящей platform check: upstream base не
содержит `ext-intl` и `ext-gd`. Проверку не отключали (`--ignore-platform-req` не использовался).
Для корректного окружения предпринята сборка штатного target:

```bash
sudo -u deploy docker build --target runtime-base \
  --tag agelto/composer-preflight:9a2a8225 --file docker/php/Dockerfile .
```

Первая попытка остановилась на `FROM minio/mc:latest`: Docker Hub вернул `pull access denied`.
Проверки manifest показали: `quay.io/minio/mc:latest` и `quay.io/minio/minio:latest` доступны,
`minio/minio:latest` в Docker Hub также недоступен. Выполнено:

```bash
sudo -u deploy docker manifest inspect quay.io/minio/mc:latest >/dev/null
sudo -u deploy docker manifest inspect quay.io/minio/minio:latest >/dev/null
sudo -u deploy docker manifest inspect minio/minio:latest >/dev/null
sudo -u deploy docker pull quay.io/minio/mc:latest
sudo -u deploy docker tag quay.io/minio/mc:latest minio/mc:latest
sudo -u deploy docker build --target runtime-base \
  --tag agelto/composer-preflight:9a2a8225 --file docker/php/Dockerfile .
```

Quay MC digest: `sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727`.
Повторный Docker build принял локальный alias и использует этот digest. На момент записи сборка
продолжается; успешная image pair, запуск data services и миграция пока **не подтверждены**.

| Шаг инструкции | Факт на сервере | Результат | Что нужно уточнить в документации |
|---|---|---|---|
| Registry-only image promotion | CI не публикует image pair; владелец выбрал build-on-host | Ручная ветка согласована | Pull main → vendor lock → immutable local image pair; не использовать registry promotion для локальных tags. |
| Production env | Shared-host ports отличаются от defaults | Env 0600, preflight PASS | Разделить host Redis port и внутренний; дать полный env recipe без секретов. |
| Image pair pre-check | Хост без PHP/Composer; upstream PHP без intl/gd | Две попытки корректно остановлены | Composer dry-run требует полного production PHP platform; запускать из штатного runtime-base или перечислить host extensions. |
| MinIO images | Docker Hub minio/mc и minio/minio недоступны; Quay доступен | MC local alias принят BuildKit | Описать проверяемый официальный registry fallback с сохранением digest и без изменения соседних tags. |

### PECL/CDN: уточнение фактического отказа

Сборка runtime-base с MC alias дошла до PCOV и завершилась ошибкой:
`Package "pcov" Version "1.0.12" does not have REST xml available`. Повторная диагностика
показала, что версия существует в [официальном PECL](https://pecl.php.net/package/pcov), но PHP
`fsockopen` не завершает TLS handshake к одному из CDN-узлов. Это не Composer lock incompatibility.

Выполнены non-mutating сетевые проверки (response body/headers не сохранялись):

```bash
curl --max-time 20 -sS -o /dev/null -w 'HTTP=%{http_code}\n' \
  https://pecl.php.net/rest/r/pcov/1.0.12.xml
curl --max-time 20 -sS -o /dev/null -w 'HTTP=%{http_code}\n' \
  http://pecl.php.net/rest/r/pcov/1.0.12.xml
curl --max-time 20 -sS -o /dev/null -w 'HTTP=%{http_code}\n' \
  https://pecl.php.net/get/pcov-1.0.12.tgz
sudo -u deploy docker run --rm --entrypoint sh serversideup/php:8.5-fpm-nginx \
  -c 'cd /tmp && pecl download pcov-1.0.12'
sudo -u deploy docker run --rm --entrypoint sh serversideup/php:8.5-fpm-nginx \
  -c 'pecl -vvv -d default_socket_timeout=10 download pcov-1.0.12'
getent ahosts pecl.php.net
sudo -u deploy docker run --rm --entrypoint sh serversideup/php:8.5-fpm-nginx \
  -c 'getent ahosts pecl.php.net; curl --max-time 15 -sS -o /dev/null -w "REST HTTP=%{http_code} IP=%{remote_ip}\n" https://pecl.php.net/rest/r/pcov/1.0.12.xml'
```

REST XML вернул `200`; HTTPS `.tgz` в нескольких попытках дал timeout, официальный HTTPS URL без
`.tgz` и HTTP `.tgz` — `200`. Verbose PECL подтвердил `SSL: Handshake timed out` в PEAR/Proxy.php.
`getent` показал CDN IPv4 `138.199.24.219`, успешный curl — `138.199.46.68`.

Выполнена точечная проверка другого CDN IPv4 **только внутри одноразового контейнера**:

```bash
sudo -u deploy docker run --rm --add-host pecl.php.net:138.199.46.68 \
  --entrypoint sh serversideup/php:8.5-fpm-nginx \
  -c 'cd /tmp && pecl -vvv download pcov-1.0.12'
```

Результат: успешно скачан `pcov-1.0.12.tgz`, 25 341 bytes, exit 0 (с предупреждением PEAR об уже
отсутствующем temporary file). TLS verification не отключалась. Для build предпринято:

```bash
sudo -u deploy env BUILDKIT_PROGRESS=plain docker build \
  --add-host pecl.php.net:138.199.46.68 --target runtime-base \
  --tag agelto/composer-preflight:9a2a8225 --file docker/php/Dockerfile .
```

Это временная build-only DNS-развилка, а не рекомендация навсегда закреплять CDN IP. Перед повторным
использованием нужно заново доказать доступность выбранного узла. Global `/etc/hosts`, resolver,
firewall, TLS trust и существующие application containers не менялись. На момент записи эта попытка
ещё выполняется; image pair и миграции всё ещё не выполнены.

Runtime-base build завершился с exit 0: локальный image config
`sha256:22eb8174f59366996aacf0c6f7c249e44c58a1ebdfe799a21f3390fb85fb9838`, tag
`agelto/composer-preflight:9a2a8225`. PCOV `1.0.12` установлен и проверен на загрузку.

Два project-scoped wrappers в `/srv/agelto/bin` подготовлены через `printf '%s\n' ... > <file>`,
затем `chown deploy:deploy`, `chmod 0755`, `bash -n` (exit 0). Их окончательное содержимое:

```bash
# /srv/agelto/bin/composer
#!/usr/bin/env bash
set -euo pipefail
workdir="$(pwd -P)"
exec /usr/bin/docker run --rm --pull=never --entrypoint composer \
  --user "$(id -u):$(id -g)" --env HOME=/tmp \
  --env COMPOSER_CACHE_DIR=/tmp/composer-cache \
  --volume "$workdir:$workdir:ro" --workdir "$workdir" \
  agelto/composer-preflight:9a2a8225 "$@"
```

```bash
# /srv/agelto/bin/docker
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == build && -n "${AGELTO_PECL_BUILD_IP:-}" ]]; then
  [[ "$AGELTO_PECL_BUILD_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 2
  shift
  exec /usr/bin/docker build --add-host "pecl.php.net:$AGELTO_PECL_BUILD_IP" "$@"
fi
exec /usr/bin/docker "$@"
```

Они используются только с явным command-local PATH; `~deploy/.profile`, общесистемный PATH и
`/usr/bin/docker` не менялись. Composer читает checkout read-only и не устанавливает `vendor/` на
production host. Docker wrapper добавляет build-only host override только если передана отдельная
переменная; остальные Docker-команды делегирует без изменения.

```bash
sudo -u deploy env PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin \
  AGELTO_PECL_BUILD_IP=138.199.46.68 BUILDKIT_PROGRESS=plain \
  make image:build IMAGE_TAG=manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38
```

Composer platform dry-run теперь успешен: 150 installs, 0 updates/removals. Полная сборка повторно
использовала `runtime-base` как cached layers и перешла к production dependencies и frontend.
На момент этой записи окончательный результат image pair ещё ожидается.

Дополнительно установлено при сверке кода: все `prod:deploy:<class>` вызывают Compose `pull`, поэтому
для local-only image tags нельзя использовать и их. Нужен отдельный ручной recreate/rollback путь.
Production Compose не подключает `env_file` автоматически: только явно интерполированные ключи из
`.env.production` попадают в runtime environment. Записанные `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`,
MAIL и произвольные external keys требуют отдельной проверки effective runtime, а не считаются
настроенными только по наличию строки в env-файле.

## Результат ручной сборки и запуск изолированной инфраструктуры

Указанная выше команда `make image:build` завершилась с exit 0 (`Image pair ready`).
Production Composer установил 150 packages без dev dependencies; doctor image содержит dev
инструменты. Frontend, private documentation, Filament/Livewire assets собраны. Проверки пары
подтвердили отсутствие Pest/Doctor в app и наличие их в doctor. PSR-4 warnings test fixtures
в doctor не скрывались; build завершился успешно.

| Локальный image | Docker image ID |
|---|---|
| `agelto/app:manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38` | `sha256:e76cf8eb861753f3bb867df2cde09f948d68470702474967375d281513ad1f48` |
| `agelto/doctor:manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38` | `sha256:35cc9ff62bb08f4742564230095dda1709ab2f36cb429678962a8090e2d7024e` |

У обеих images revision label `9a2a8225f96ba7e6915186e9bdc5298a7d39ee38`, vendor lock label
`cf239cc3ba18ef369aadcde03dd1d127011710ff85279a53ad30c16a0da1c2a0`.
Images остаются только на сервере; registry publication/CI не выполнялись.

Перед запуском infrastructure ошибочно подготовлен alias `minio/minio:latest` из официального
Quay (`sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e`).
Этот image **не использован**: production Compose требует точный release tag. Первая команда
`prod:infra:up` собрала PostgreSQL, но остановилась на недоступном Docker Hub manifest MinIO
до создания контейнеров. Выполнена корректная развилка с тем же release:

```bash
sudo -u deploy docker pull quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z
sudo -u deploy docker tag quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z \
  minio/minio:RELEASE.2025-04-22T22-12-26Z
sudo -u deploy env BUILDKIT_PROGRESS=plain COMPOSE_PROGRESS=plain \
  make prod:infra:up ENV_FILE=.env.production \
  IMAGE_TAG=manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38 \
  COMPOSE_PROJECT_NAME=agelto_prod
```

Quay release digest: `sha256:a1ea29fa28355559ef137d71fc570e508a214ec84ff8083e39bc5428980b015e`.
Команда завершилась с exit 0. Созданы только:

| Service/container | Опубликованный адрес | Data volume |
|---|---|---|
| `agelto_prod-postgres-1` | `127.0.0.1:5434 → 5432` | `agelto_prod_pgsql_data` |
| `agelto_prod-redis-1` | `127.0.0.1:6382 → 6379` | `agelto_prod_redis_data` |
| `agelto_prod-minio-1` | `127.0.0.1:9002 → 9000`, `127.0.0.1:9003 → 9001` | `agelto_prod_minio_data` |

Сеть `agelto_prod_agelto_network`: `172.21.0.0/16`, gateway `172.21.0.1`.
Inspect подтвердил labels project/service и фактические mounts именно Agelto.
PostgreSQL image `agelto_postgres:17-partman` собран по tracked Dockerfile, partman package 5.5.0.
MinIO live endpoint `http://127.0.0.1:9002/minio/health/live` вернул `200`.

### Уточнение ложноположительного Redis health-check

Штатная команда `redis-cli --user health PING` возвращает `NOAUTH Authentication required`,
но exit 0, поэтому первый статус `healthy` не доказывал готовность Redis. Проверено:

```bash
sudo -u deploy docker compose --project-name agelto_prod --env-file .env.production \
  -f docker-compose.prod.yml exec -T redis redis-cli --user health --pass '' PING
{ printf 'AUTH default '; tr -d '\n' < local/secrets/redis_password; printf '\nPING\n'; } | \
  sudo -u deploy docker compose --project-name agelto_prod --env-file .env.production \
  -f docker-compose.prod.yml exec -T redis redis-cli
```

Результаты: `PONG` для отдельного nopass health user; `OK`, `PONG` для default user с secret,
переданным через stdin без вывода и без secret в argv. Предупреждение Redis CLI относится к
**пустому** health password, а не к настоящему Redis password.

Создан ранее отсутствовавший ignored `docker-compose.override.yml`, deploy:deploy 0600.
Он не меняет tracked source, соседние Compose projects или secret contract:

```yaml
services:
  app:
    environment: &host_env
      APP_NAME: ${APP_NAME:-Agelto}
      SESSION_DOMAIN: ${SESSION_DOMAIN:-null}
      SESSION_SECURE_COOKIE: ${SESSION_SECURE_COOKIE:-true}
      SESSION_HTTP_ONLY: ${SESSION_HTTP_ONLY:-true}
      SESSION_SAME_SITE: ${SESSION_SAME_SITE:-lax}
      MAIL_MAILER: ${MAIL_MAILER:-log}
      MAIL_FROM_ADDRESS: ${MAIL_FROM_ADDRESS}
      MAIL_FROM_NAME: ${APP_NAME:-Agelto}
  horizon:
    environment: *host_env
  scheduler:
    environment: *host_env
  doctor:
    environment: *host_env
  redis:
    healthcheck:
      test: ["CMD-SHELL", "test \"$$(redis-cli --user health --pass '' ping)\" = PONG"]
```

Здесь явно передаются дополнительные non-secret env keys, которые базовый Compose не передаёт.
Проверка `config --quiet` успешна. Effective Laravel config будет проверен после запуска app;
наличие override само по себе пока не считается runtime-проверкой cookies/mail.

Создан deploy:deploy 0755 `/srv/agelto/bin/compose` (`bash -n`: exit 0):

```bash
#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/docker compose --project-directory /srv/agelto/repository \
  --project-name agelto_prod --env-file /srv/agelto/repository/.env.production \
  -f /srv/agelto/repository/docker-compose.prod.yml \
  -f /srv/agelto/repository/docker-compose.override.yml "$@"
```

Оба файла записаны через guarded `test ! -e ... && printf '%s\n' ... > ...`, ownership/mode
применены только к новым файлам. Команды последующей эксплуатации обязаны учитывать override:

```bash
sudo -u deploy env COMPOSE_PROGRESS=plain /srv/agelto/bin/compose \
  up -d --no-build --no-deps --wait redis
sudo -u deploy /srv/agelto/bin/compose ps
docker inspect agelto_prod-redis-1 --format \
  '{{.Config.Healthcheck.Test}} {{.State.Health.Status}} {{range .State.Health.Log}}{{.ExitCode}} {{printf "%q" .Output}} {{end}}'
```

Пересоздан **только Agelto Redis**, сохранён его volume. Результат `healthy`, history exit 0
после строгого сравнения ответа с `PONG`; PostgreSQL/MinIO и соседние сервисы не пересоздавались.
Обратный путь для этого override: сохранить его отдельную копию, убрать только его Redis
healthcheck block и повторить scoped recreate Redis. Это вернёт прежнюю ложную проверку, поэтому
не является рекомендуемым рабочим состоянием. Ни один data volume не удаляется.

### Проверки перед обязательным migration gate

```bash
sudo -u deploy /srv/agelto/bin/compose exec -T postgres psql -U agelto -d agelto -Atc \
  'SELECT current_database(), current_user; SHOW port; SELECT count(*) FROM pg_tables WHERE schemaname = '\''public'\'';'
make -n prod:migrate COMPOSE_PROD=/srv/agelto/bin/compose OWNER_GATE=approved
nginx -t
for domain in agelto.ru insiders.localmost.ru dterema.localmost.ru cms.dterema.localmost.ru; do
  curl --max-time 15 -sS -o /dev/null -w "$domain HTTPS=%{http_code}\n" "https://$domain/"
done
df -h /
free -h
sudo -u deploy git pull --ff-only origin main
sudo -u deploy git status --short
```

- PostgreSQL: current database/user `agelto|agelto`, внутренний порт `5432`, **0 public tables**.
  Ранее read-only проверка extensions показала только `plpgsql 1.0`.
- Все три Agelto data services healthy с проверенной Redis probe.
- `make -n` только распечатал будущую команду: миграция **не запускалась**.
- `nginx -t` успешен; остались warnings о deprecated `listen ... http2`, не syntax failure.
- HTTPS Agelto `502` ожидаемо при отсутствующем app; сертификат действителен. Соседи: insiders
  `302`, dterema `200`, cms.dterema `200`, как перед настройкой Agelto.
- Disk free 57 GiB, RAM available 7.0 GiB, swap отсутствует.
- Git pull от deploy: `Already up to date`; tracked/untracked status checkout пуст
  (production env, secrets, local vendors и override ignored).
- В финальном `docker ps` соседние queue containers имели меньший uptime при unchanged app,
  DB и scheduler uptime около двух недель. Мы не отправляли им restart/stop/recreate команды;
  причина их самостоятельных перезапусков в рамках этого отчёта не установлена.

| Шаг инструкции | Факт на сервере | Результат | Что уточнить в документации |
|---|---|---|---|
| Immutable image pair | CI/registry нет, host build разрешён владельцем | Local app/doctor pair успешно собран | Полный manual build branch, full PHP platform для Composer precheck, unique local tags и запрет registry-pull deploy helpers. |
| Infra images | Docker Hub MinIO tags недоступны | Официальный Quay exact release успешно использован | Проверять точный tag; latest alias не заменяет pinned release. |
| Runtime env | Базовый Compose передаёт только explicit map | Создан isolated override; effective app config ещё не проверен | Не считать любой env key автоматически переданным; явно передавать cookies/mail/external options. |
| Redis health | NOAUTH даёт CLI exit 0 | Strict PONG check установлен, настоящий PING проверен | Health user требует пустой AUTH; exit code одной CLI-команды недостаточен. |
| Миграции | Новая отдельная БД agelto пуста | Ожидает explicit owner approval | Проверить DB/container/volume/project до owner gate, не запускать workers раньше schema/base setup. |

## Промежуточная остановка: подтверждение миграций (снята позднее)

Цель: БД `agelto`, user `agelto`, внутренний host `postgres:5432`, контейнер
`agelto_prod-postgres-1`, volume `agelto_prod_pgsql_data`, Compose project `agelto_prod`.
Публикация на host `127.0.0.1:5434` не используется приложением. Network и file-backed secret
относятся только к Agelto; migrations не подключаются к соседним БД.

**Ожидающая выполнения команда, не выполнена:**

```bash
cd /srv/agelto/repository
sudo -u deploy make prod:migrate COMPOSE_PROD=/srv/agelto/bin/compose OWNER_GATE=approved
```

После подтверждения: миграции, base seed/partitions по репозиторию, app/storage/admin,
Horizon/scheduler, runtime readiness/doctor, HTTP/HTTPS health, manual deploy/rollback команды,
окончательная правка связанных инструкций и отдельный documentation-only commit без push.
## Завершение после подтверждения владельца

Владелец явно подтвердил все необходимые действия, включая ранее показанные миграции.
Повторный SSH-вход выполнен той же командой; пароль снова введён скрыто, history выключена.
Цель повторно проверена как `agelto|agelto`, 0 public tables.

### Миграции и pg_partman

Первая реальная `prod:migrate ... OWNER_GATE=approved` применила предыдущие migrations, но
`2026_09_10_100093_repair_partition_coverage` завершилась ошибкой overlap:
`prc_events_2026_12` пересекается с существующей `prc_events_p20261201`.
Ledger не подделывался, partitions не удалялись, `migrate:fresh` не запускался.

Read-only SQL показал five parents в `partman.part_config` с `datetime_string=YYYYMMDD`,
декабрьский диапазон [2026-12-01, 2027-01-01) под именем `_p20261201`.
Все пять partitioned tables имели 0 rows:
`prc_events`, `prc_revisions`, `llm_calls`, `prc_fetches`, `prc_operation_logs`.

Первая попытка dump через Compose exec в PTY остановилась в CLI на terminal stdin
(`ps` показал stopped CLI process, dump был 0 bytes); дальнейшая отправка SQL в ту занятую
сессию не исполнилась. Это не считалось backup/SQL success. После проверки exact process
завершены только наши CLI PID 335613/335594 (TERM + CONT для stopped child), не Docker services.
Пустой файл сохранён как `before-partition-normalization-20260912.dump.incomplete`.
В новой SSH-сессии выполнен корректный dump без terminal stdin:

```bash
docker exec agelto_prod-postgres-1 pg_dump -U agelto -d agelto -Fc </dev/null \
  > /srv/agelto/before-partition-normalization-20260912.dump
chown deploy:deploy /srv/agelto/before-partition-normalization-20260912.dump
docker exec -i agelto_prod-postgres-1 pg_restore --list \
  < /srv/agelto/before-partition-normalization-20260912.dump
```

Перед созданием проверено отсутствие target; umask 077. List exit 0 доказывает читаемость archive,
не полноценный restore. Последующее restore rehearsal описано отдельно ниже.

Выполнена transaction только в exact Agelto container/DB:

```bash
docker exec -i agelto_prod-postgres-1 psql -v ON_ERROR_STOP=1 -U agelto -d agelto <<'SQL'
BEGIN;
UPDATE partman.part_config SET datetime_string = 'YYYY_MM' WHERE parent_table IN ('public.prc_events','public.prc_revisions','public.llm_calls','public.prc_fetches','public.prc_operation_logs');
DO $normalize$
DECLARE item record; partition_name text;
BEGIN
  IF current_database() <> 'agelto' THEN RAISE EXCEPTION 'Wrong database'; END IF;
  FOR item IN SELECT child.relname AS name, parent.relname AS parent_name, pg_get_expr(child.relpartbound, child.oid) AS bounds FROM pg_inherits i JOIN pg_class child ON child.oid = i.inhrelid JOIN pg_class parent ON parent.oid = i.inhparent JOIN pg_namespace ns ON ns.oid = parent.relnamespace WHERE ns.nspname = 'public' AND parent.relname IN ('prc_events','prc_revisions','llm_calls','prc_fetches','prc_operation_logs') AND child.relname ~ '_p[0-9]{8}$'
  LOOP
    IF item.bounds <> format('FOR VALUES FROM (%L) TO (%L)', to_char(to_date(right(item.name,8),'YYYYMMDD'),'YYYY-MM-DD') || ' 00:00:00+00', to_char(to_date(right(item.name,8),'YYYYMMDD') + interval '1 month','YYYY-MM-DD') || ' 00:00:00+00') THEN RAISE EXCEPTION 'Unexpected partition bounds for %: %', item.name, item.bounds; END IF;
    partition_name := item.parent_name || '_' || to_char(to_date(right(item.name,8),'YYYYMMDD'),'YYYY_MM');
    IF to_regclass('public.' || partition_name) IS NOT NULL THEN RAISE EXCEPTION 'Target already exists: %', partition_name; END IF;
    EXECUTE format('ALTER TABLE public.%I RENAME TO %I', item.name, partition_name);
    RAISE NOTICE 'Renamed % to %', item.name, partition_name;
  END LOOP;
END $normalize$;
COMMIT;
SQL
```

Результат: UPDATE 5, пять NOTICE о rename `<parent>_p20261201` → `<parent>_2026_12`,
COMMIT, exit 0. Bounds/data не менялись. Это operational naming workaround, не source fix
миграции: на новом host та же version-развилка требует указанных проверок.
Обратное переименование/config rollback вернёт несовместимость repair migration; для обычного
image rollback normalization сохраняется.

```bash
sudo -u deploy env COMPOSE_PROGRESS=plain make prod:migrate \
  COMPOSE_PROD=/srv/agelto/bin/compose OWNER_GATE=approved </dev/null
sudo -u deploy make prod:seed-base COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
sudo -u deploy /srv/agelto/bin/compose run --rm --no-deps app \
  php artisan partitions:maintain </dev/null
```

Повторная migration exit 0: 100093, 100094 и 100095 DONE; всего ledger содержит 81 migration.
BaseSeeder exit 0: Platform, AiRouting, Prompt, EisPlatform, StatusMapping, Okpd2Node,
CrawlerProfile seeders. `partitions:maintain` exit 0 через pg_partman.
Maintenance имеет штатную retention policy; это не read-only command и не обещание сохранять
вечно все пустые historical partitions. Production data перед этим отсутствовали.

### App, initial admin, S3, Horizon, scheduler

Production override дополнен `PHP_OPCACHE_ENABLE: "1"`; предыдущая копия сохранена
`/srv/agelto/docker-compose.override.yml.before-opcache-20260912`.

```bash
sudo -u deploy make prod:admin:create COMPOSE_PROD=/srv/agelto/bin/compose \
  PASSWORD_FILE=local/secrets/admin_initial_password </dev/null
sudo -u deploy make prod:app:up COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
sudo -u deploy make prod:storage:init COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
sudo -u deploy make prod:workers:up COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
```

Все exit 0. Initial SuperAdmin `admin@agelto.ru` создан create-only командой из file-secret.
Created fourth volume `agelto_prod_app_storage`; app/Horizon/scheduler используют его.
Buckets `agelto`, `agelto-estimates` подготовлены. Первые readiness/Doctor проверки были красными:
scheduler ещё не записал минутный heartbeat. Последующая штатная минута дала heartbeat;
ручной `schedule:run` не применялся. Scheduler logs показали callback,
pipeline:dispatch-backlog и pipeline:recover-continuations DONE.

После heartbeat app readiness стала полностью green, но Doctor оставался красным только по
assets: его image не содержит web public assets. Диагностика не отключалась.
Из app **того же tag** экспортирован public в новый release directory:

```bash
release_tag=$(awk -F= '$1 == "IMAGE_TAG" {print $2; exit}' .env.production)
install -d -m 0750 -o deploy -g deploy "/srv/agelto/releases/$release_tag"
sudo -u deploy docker run --rm --pull=never --entrypoint tar "agelto/app:$release_tag" \
  -C /var/www/html -cf - public </dev/null | \
  sudo -u deploy tar --no-same-owner -xf - -C "/srv/agelto/releases/$release_tag"
```

В ignored override добавлен **только doctor** read-only mount
`/srv/agelto/releases/${IMAGE_TAG}/public:/var/www/html/public:ro`.
Previous override copy: `/srv/agelto/docker-compose.override.yml.before-doctor-assets-20260912`.
Doctor повторно прошёл: 27 diagnostics, failed 0, warnings 0, passed 24, skipped 2, notice 1
(общий совет пакета о schedule:run, не ошибка production scheduler).
Rollback assets mapping: только собственный saved override; tests не исключались.

### Исправление HTTPS asset/Livewire URLs

Первый HTML probe страницы login выявил абсолютные `http://agelto.ru/...` assets и Livewire URLs.
Laravel bootstrap не задаёт trusted proxies. Host HTTPS termination само по себе не делало
FastCGI request secure. Создан production-only app NGINX config:

```bash
sed '/include fastcgi_params;/a\
        fastcgi_param HTTPS on;\
        fastcgi_param SERVER_PORT 443;' docker/nginx/default.conf > /srv/agelto/nginx-app.conf
chown deploy:deploy /srv/agelto/nginx-app.conf
chmod 0644 /srv/agelto/nginx-app.conf
```

Guard проверил отсутствие target. После каждого include добавлены HTTPS/SERVER_PORT,
в app override добавлен readonly bind `/srv/agelto/nginx-app.conf:/etc/nginx/conf.d/default.conf:ro`.
Это применимо именно к HTTPS-only публичному ingress с private loopback app port.
Horizon/scheduler config не менялся; исходники приложения не монтируются с host.

Bare-image nginx -t с новым config сначала дал exit 1: ещё нет generated nginx.conf.
Корректный pre-apply test использовал generated config текущего app:

```bash
docker cp agelto_prod-app-1:/etc/nginx /srv/agelto/nginx-check-20260912
cp /srv/agelto/nginx-app.conf /srv/agelto/nginx-check-20260912/conf.d/default.conf
sudo -u deploy docker run --rm --pull=never --entrypoint nginx \
  --volume /srv/agelto/nginx-check-20260912:/etc/nginx:ro "agelto/app:$release_tag" -t </dev/null
sudo -u deploy /srv/agelto/bin/compose up -d --no-build --no-deps --pull never --wait app </dev/null
docker exec agelto_prod-app-1 nginx -t </dev/null
```

Pre/post tests exit 0, пересоздан только app, healthy. Повторный HTML probe: assets и Livewire
URLs стали HTTPS. Backup override: `/srv/agelto/docker-compose.override.yml.before-https-20260912`.
Rollback этой настройки восстанавливает только собственный override и scoped app recreate;
он вернул бы mixed-content defect, поэтому без отдельной причины не рекомендуется.

Host Agelto vhost отдельно modernized для NGINX 1.28:
`listen 443 ssl;`, `listen [::]:443 ssl;`, `http2 on;`.
Previous copy `/etc/nginx/sites-available/agelto.ru.conf.before-http2-20260912`.
`nginx -t` exit 0 без прежних deprecated warnings, затем reload. Соседние files не изменены.

### Effective runtime и браузерный smoke

Через `docker exec -i agelto_prod-app-1 php` загружены autoload/bootstrap/kernel и выведены
**только allowlisted non-secret config keys** плюс DB select/PING:
DB `agelto`, user `agelto`, host `postgres`, port `5432`; Redis ping true;
session redis/domain agelto.ru/secure true/http_only true/same_site lax;
mail log/from noreply@agelto.ru; opcache.enable 1; retry_after prepare 2700, crawl_long 3900.
Secret values, environment целиком и config database целиком не выводились.

Headless Chromium read-only smoke использовал пароль из защищённого local file, без
screenshots/traces/storageState/secret logging и без CRUD над production entities.
Первая попытка имела ошибку ожидания в smoke script (transient redirect принят слишком рано);
исправлен сам smoke wait: дождаться Livewire POST и конечного /admin.
POST status 200, redirect https://agelto.ru/admin. Итоговые результаты:

| Проверка | Факт |
|---|---|
| Вход admin | PASSED, /admin |
| Session cookie | Secure true, HttpOnly true, SameSite Lax, domain .agelto.ru; value не выводилось |
| /admin | Authenticated HTTP 200 |
| /documentation/ | Authenticated HTTP 200 |
| /documentation/04_Operators/production/server-setup | Authenticated HTTP 200 |
| /documentation/04_Operators/production/deployment | Authenticated HTTP 200 |
| Page/asset failures | [] |
| Anonymous documentation | Redirect на /admin/login |

Дополнительная SVG Mermaid assertion не прошла: VitePress config не содержит renderer,
DOM mermaid/svg count 0, исходный flowchart отображается code block.
Инструкция больше не утверждает, что SVG runtime smoke прошёл. Full E2E contract, который
требует SVG, не назван green и не ослаблялся; renderer остаётся отдельной frontend/doc feature.

По отдельному запросу владельца admin password **только** скопирован в защищённый local
handoff file посредством scp, mode 0600, parent 0700, вне Git/worktree. Содержимое не выводилось
в терминал и не входит в этот отчёт. Private SSH keys не копировались.
Server source file: `/srv/agelto/repository/local/secrets/admin_initial_password`.

### Backup restore rehearsal и финальные seeds

Legacy `backup-and-verify.sh prod` нельзя использовать без адаптации: он выбирает другие env
names, не фиксирует project и удаляет scratch DB. Вместо него выполнен exact-container путь:

```bash
install -d -m 0700 -o deploy -g deploy /srv/agelto/backups
backup_stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="/srv/agelto/backups/agelto_${backup_stamp}.dump"
rehearsal_db="agelto_${backup_stamp,,}_rehearsal"
docker exec agelto_prod-postgres-1 pg_dump -U agelto -d agelto -Fc </dev/null > "$backup_file"
chown deploy:deploy "$backup_file"
docker exec agelto_prod-postgres-1 createdb -U agelto -O agelto "$rehearsal_db" </dev/null
docker exec -i agelto_prod-postgres-1 pg_restore -U agelto -d "$rehearsal_db" \
  --no-owner --no-acl --exit-on-error < "$backup_file"
docker exec agelto_prod-postgres-1 psql -U agelto -d "$rehearsal_db" -Atc \
  'SELECT current_database(); SELECT count(*) FROM migrations; SELECT count(*) FROM users; SELECT count(*) FROM pg_tables WHERE schemaname = '\''public'\'';' </dev/null
sha256sum "$backup_file"
```

Targets проверены отсутствующими, umask 077; restore не пишет в original DB.
Результат exit 0: `agelto_20260912t171546z_rehearsal`, 81 migrations, 1 user, 153 public tables.
Archive `/srv/agelto/backups/agelto_20260912T171546Z.dump`,
SHA256 `7151808d0872915dc71f00d4cca4c97907e5addd70f7aff49a2b890d66272e4f`.
Rehearsal DB/dump retained, не удалены. Это проверенный **локальный PostgreSQL restore**,
не encrypted/off-host full object/Redis/secret backup и не disaster-recovery proof.

Проверена команда recreate в dry-run:

```bash
sudo -u deploy /srv/agelto/bin/compose --dry-run up -d --no-build --no-deps \
  --pull never --force-recreate app horizon scheduler </dev/null
sudo -u deploy make prod:seed-base COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
```

Dry-run exit 0; реальные IDs/Created сохранились:
app 0f1aef74cfac (17:11:21 UTC), Horizon adb6148b20dd, scheduler 6536707701d1
(оба 17:06:13 UTC). Это не реальный version rollback. Первый успешный release пока единственный;
previous version отсутствует. Полный future rollback описан с IDs/schema/backup gates.
BaseSeeder **повторно в конце** exit 0; demo/local-only seeders не применялись.

## Итоговые проверки и handoff

Последний read-only/runtime pass после повторного BaseSeeder:

```bash
sudo -u deploy make prod:readiness COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
sudo -u deploy make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose </dev/null
nginx -t
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
docker inspect --format '{{.Name}} started={{.State.StartedAt}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} image={{.Config.Image}}' insiders_queue dterema_backend-queue-1
journalctl -k --since '2026-09-12 14:00:00 UTC' --no-pager | grep -Ei 'out of memory|oom-kill|killed process' | tail -n 20
free -h
df -h /
for site in agelto.ru dterema.localmost.ru cms.dterema.localmost.ru insiders.localmost.ru; do
  curl --max-time 20 -sS -o /dev/null -w "$site status=%{http_code} tls=%{ssl_verify_result}\n" "https://$site/"
done
sudo -u deploy git pull --ff-only origin main </dev/null
sudo -u deploy git status --short
sudo -u deploy bash docker/scripts/preflight.sh --root /srv/agelto/repository --mode prod-runtime --env-file /srv/agelto/repository/.env.production --agent-json </dev/null
```

Readiness: все семь checks passed. Doctor: passed, 27 diagnostics, failed 0, warnings 0,
notice 1 (общий совет package о cron), passed 24, skipped 2. Шесть сервисов Agelto healthy.
NGINX syntax successful. Agelto HTTPS 200/TLS verify 0; соседи dterema.localmost.ru 200,
cms.dterema.localmost.ru 200, insiders.localmost.ru 302/TLS verify 0, как в baseline.
Disk available 57 GiB, RAM available 5.5 GiB, swap 0. Git pull Already up to date, status пуст.

В первой повторной попытке neighbor curl были ошибочно указаны короткие домены без
`localmost`: `cms.dterema.ru` дал DNS failure/status 000, а два других short domains отвечали
200, но это **не** проверка соседей. Выше приведены правильные FQDN и успешный повтор.
На сервере `rg` отсутствует; kernel search с rg сначала failed command-not-found,
затем повторён через grep. В указанном kernel journal window matching OOM lines не выведены.
У neighboring queue containers RestartCount 480/482, OOMKilled false; app/DB/scheduler
соседей продолжают работать. Мы не отправляли их stop/restart/recreate. Причина их собственных
перезапусков не установлена; это не приписывается успеху или ошибке deploy без доказательства.

Повторный initial preflight **ожидаемо failed exit 14**: HOST_TOOLS/VENDOR_LOCK/ENV_SCHEMA/
SECRET_CONTRACT passed, PORT_OCCUPIED APP_PORT. Его free-port contract предназначен до первого
start, а теперь этот port занимает собственный healthy Agelto app. Документация добавляет
отдельную ветку проверки уже работающего проекта без остановки ради освобождения port.

### Сопоставление инструкции с фактом: исправления завершённого контура

| Шаг инструкции | Факт на сервере | Результат | Что исправлено/добавлено |
|---|---|---|---|
| Host prerequisites | Ubuntu 26.04, Docker/NGINX/deploy уже готовы | Повторный bootstrap пропущен | Fresh-host install отдельно от shared-host audit; не обходить guard host:apply и не менять daemon/SSH/firewall. |
| GitHub/vendor access | Dedicated read-only Agelto key, public vendor exact pin | ls-remote/clone/pull/vendor check успешны | Key owner gate, protected key, host fingerprint, repo-local SSH; не запускать global SSH rewrite helper. |
| Manual local build | CI/registry нет | App/Doctor pair build exit 0 | Unique immutable local tags, full Composer platform wrapper, Quay/PECL safe branches, запрет registry-pull helpers. |
| ENV/runtime | Compose explicit map не передавал cookie/mail/name options | Allowlisted effective config проверен | Non-secret env matrix, file secrets, explicit override, internal vs host port distinction, OPcache on. |
| Redis probe | NOAUTH мог быть false-positive exit 0 | AUTH health + strict PONG и default credential PING успешны | Корректный healthcheck, отдельная nopass PING-only ACL identity. |
| Fresh migrations | pg_partman naming overlap на 100093 | Guarded transaction + pending migrations DONE | Exact DB/container/volume approval и полный SQL normalization, без удаления/ledger hack. |
| Queue/scheduler | Workers после schema/buckets | Healthy, Horizon running, настоящий heartbeat | Порядок старта, grace 4200s, readiness wait, без fake schedule:run. |
| Doctor assets | Doctor image не имеет public | Same-tag public snapshot readonly; Doctor passed | Release-specific snapshot export/mount; не отключать diagnostic. |
| DNS/HTTP/TLS | DNS готов, loopback 8080 занят соседом | Separate 8082 upstream, cert issued, renewal rehearsal passed | HTTP bootstrap до cert, full candidate/render/backup commands, NGINX modern HTTP2. |
| TLS offload | Laravel первоначально генерировал HTTP assets | HTTPS assets/Livewire, browser login прошёл | Production-only FastCGI secure config и pre/post syntax checks. |
| Backup/rollback | Первый successful version, legacy helper unsafe | Реальный local restore; recreate dry-run | Exact retained rehearsal, IDs/schema gates, full manual deploy/rollback; не выдавать dry-run за version rollback. |
| Documentation verification | Default command inventory ссылается на stale archive | Current generated matrix strict check passed | Living matrix отдельно от terminal plan archive; default check failure не скрыт. |
| Mermaid SVG | В текущем VitePress нет renderer | Text/code docs доступны, SVG assertion failed | Убрано неподтверждённое обещание SVG; full E2E не объявлен green. |

### Проверки документации

Выполнены локально:

```bash
git diff --check
pnpm documentation:build
pnpm documentation:verify
python3 docker/scripts/check-command-reference.py check
python3 docker/scripts/check-command-reference.py render --output docs/04_Operators/production/command-matrix.md
python3 docker/scripts/check-command-reference.py check --output docs/04_Operators/production/command-matrix.md
```

Build exit 0, distribution verified 309 files. Explicit current matrix check exit 0.
Default matrix check failed `command matrix is stale`: он сравнивает historical terminal P12
snapshot с текущими Make/scripts. Archive не переписан, guard не ослаблен; команды living docs
теперь используют точный current matrix path. Полная test suite/full E2E не запускались и
не объявлены green. Исходники, tests и generated skill bundles не изменены.

### Что остаётся владельцу / границы результата

- GitHub read-only key уже добавлен, миграции подтверждены/выполнены, DNS и TLS готовы.
- Admin `admin@agelto.ru`, URL `https://agelto.ru/admin/login`, password передан protected
  handoff file отдельно от Git/отчёта; после входа владелец может заменить его.
- Личный SSH key входа deploy не создан: это владелец делает отдельно.
- Настоящие SMTP/LLM/alert credentials отсутствуют. Mail сейчас log; paid AI/email delivery
  и реальный end-to-end crawling не заявлены проверенными.
- Нужна off-host encrypted backup strategy для полноценного disaster recovery.
- Реальный version rollback можно проверить после второго successful distinct release.
- Mermaid rendering — отдельный frontend docs gap; текстовая private документация доступна.
- Documentation commit только локальный, push не выполняется. Поэтому server checkout/image
  пока содержит прежний documentation build; обновлённая инструкция и root report находятся
  в workspace commit, а не тайно опубликованы. Для публикации нужен отдельный запрос владельца.

Обычный manual deploy/rollback: `docs/04_Operators/production/manual-deployment.md`,
точные status/log commands: `docs/04_Operators/production/commands.md`,
backup rehearsal: `docs/04_Operators/production/rollback-restore.md`.

Generated inventory в конце перенесён из docs в root `DEPLOYMENT_COMMAND_MATRIX.md`:
его строгий machine-generated формат без frontmatter не должен нарушать frontmatter-канон
living docs. Содержимое не правилось вручную; current matrix check повторён с root path.
`commands.md` указывает этот root artifact, archive по-прежнему untouched.
После переноса final documentation build/verify exit 0: distribution содержит 306 files;
предыдущее наблюдение 309 относилось к сборке, включавшей matrix как documentation page.
Все 29 bash fences manual runbook и один bash fence restore runbook проверены через
`bash -n` (Node extractor + spawnSync), exit 0. Это syntax check, не ещё одно выполнение
production migrations или rollback. После финальной проверки обе root SSH sessions закрыты;
код exit первой SSH session 14 сохранял последний ожидаемый preflight failure, не deploy failure.

Оформление отдельного документационного коммита выполняется локально после checks:

```bash
git add DEPLOYMENT_REPORT.md DEPLOYMENT_COMMAND_MATRIX.md docs/03_Dev/process/deploy.md docs/04_Operators/production/_index.md docs/04_Operators/production/commands.md docs/04_Operators/production/deployment.md docs/04_Operators/production/manual-deployment.md docs/04_Operators/production/network-tls.md docs/04_Operators/production/releases.md docs/04_Operators/production/repositories.md docs/04_Operators/production/rollback-restore.md docs/04_Operators/production/server-setup.md
git diff --cached --check
git diff --cached --stat
git commit -m 'docs(deploy): document verified manual production setup'
git show --stat --oneline HEAD
git status --short
```

Коммит не может содержать собственный хеш; точный resulting hash сообщается в финальном handoff.
Ни `git push`, ни публикация документации в server checkout не выполняются.

### Использованные навыки и влияние

`task:task-dev` и `owner-autonomy`: выполнение без повторных вопросов после owner approval,
durable evidence в этом отчёте. `devops:docker`, `devops:docker-php`, `devops:docker-postgres`,
`devops:docker-dev-prod`, `devops:docker-services`: отдельные Compose identities, immutable
image pair, runtime env/healthchecks и изолированный порядок старта. `php:database` и
`php:laravel`: exact DB/partition проверки и безопасная миграционная развилка.
`devops:node-pnpm-preflight`: проверка Node/pnpm до documentation build/commit.
`quality:playwright-e2e`: реальный ограниченный authenticated browser smoke без production CRUD,
traces/storageState и без выдачи непрошедшей SVG assertion за green.

## Продолжение: закрытие оставшихся задач

Владелец разрешил выполнить остаток настройки и использовать заполненный локальный Gemini key
также в production. На момент начала (17:48 UTC) шесть Agelto containers healthy, NGINX syntax
green, checkout ещё `9a2a8225`, свободно 57 GiB / доступно 5.4 GiB RAM. CI не включается.

### Выполненные команды и промежуточные результаты

```bash
ssh -p 22 root@46.8.220.57
set +o history
date -u
df -h /
free -h
sudo -u deploy git -C /srv/agelto/repository status --short
sudo -u deploy git -C /srv/agelto/repository rev-parse HEAD
sudo -u deploy /srv/agelto/bin/compose ps
nginx -t
ls -l /srv/agelto/bin
systemctl list-unit-files --no-pager 'agelto*'
find /srv/agelto -maxdepth 2 -type d
```

Локально проверены Node/pnpm (24.12.0 / 9.15.0) и наличие ключа через Dotenv в памяти;
значения secrets не выводились. SMTP `log`, host локальный и username/password null:
реальных SMTP credentials локальный проект не предоставляет.

Создан `/srv/agelto/operations/20260912T174927Z`: protected snapshots `env.previous`,
`compose.previous`, `tag.previous`, `pair.previous.ids`, `tag.candidate`. От deploy выполнено:

```bash
cd /srv/agelto/repository
umask 077
operation_dir=/srv/agelto/operations/20260912T174927Z
previous_tag=$(awk -F= '$1 == "IMAGE_TAG" {print $2; exit}' .env.production)
mkdir -p "$operation_dir"
cp .env.production "$operation_dir/env.previous"
cp docker-compose.override.yml "$operation_dir/compose.previous"
printf '%s\n' "$previous_tag" > "$operation_dir/tag.previous"
docker image inspect --format '{{.Id}}' "agelto/app:$previous_tag" \
  "agelto/doctor:$previous_tag" > "$operation_dir/pair.previous.ids"
git pull --ff-only origin main
test -z "$(git status --porcelain)"
bash docker/scripts/vendor-sync.sh
git diff 9a2a8225f96ba7e6915186e9bdc5298a7d39ee38 HEAD -- database/migrations
candidate_sha=$(git rev-parse HEAD)
candidate_tag=manual-$candidate_sha-$(date -u +%Y%m%dT%H%M%SZ)
PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin BUILDKIT_PROGRESS=plain \
  make image:build IMAGE_TAG="$candidate_tag"
```

Pull — fast-forward до `366aba5eb9650f4bfa03d39cd735113ac7c0c213`; vendor exact pin
`7e7009cc7c26e173cfa6bfec42365a311fc2adb0`; migrations diff пуст. Candidate tag:
`manual-366aba5eb9650f4bfa03d39cd735113ac7c0c213-20260912T174930Z`.
Первая попытка `git diff "$previous_tag" HEAD` использовала Docker tag как Git ref: fallback
с настоящим source SHA дал корректный пустой diff. В инструкции всегда используется revision
label, не image tag. Сборка пока выполняется: не объявлять candidate готовым до exit 0.

Gemini secret прочитан PHP/Dotenv и передан напрямую pipe → SSH stdin → protected
`local/secrets/external.env`; без local secret copy, CLI value, вывода или логирования bytes.
Первый format guard `[A-Za-z0-9_-]+` оказался слишком узким для фактического значения;
SSH отменён до password/login и записей. Второй guard проверяет отсутствие control characters,
Dotenv string сохраняется quoted/escaped. Transfer exit 0, файл deploy:deploy 0600.

Host additions подготовлены через `apply_patch` в `/tmp/agelto-ops.QlWzSj6M`, синтаксис Bash/sh/PHP
проверен, переданы `scp -P 22 -r` в operation directory. Private SSH keys не копировались.
Первый install override от deploy отказался `Permission denied`: scp directory root-owned 0700.
После scoped `chown -R deploy:deploy` только staging directory доступ исправлен. Попытка
`git check-ignore` для staging вне repository дала expected outside-repository error; Git-ignored
repository paths проверяются отдельно, staging не является Git artifact.

```bash
docker exec agelto_prod-postgres-1 psql -U agelto -d agelto -Atc \
  'SELECT count(*) AS proxy_count FROM prc_proxies;'
install -o deploy -g deploy -m 0755 \
  "$operation_dir/agelto-ops.QlWzSj6M/external-env" /srv/agelto/bin/external-env
chown -R deploy:deploy "$operation_dir/agelto-ops.QlWzSj6M"
```

Production proxy pool пуст (0 records). `PoolOutboundProxy` fail-closed: Gemini key alone
не делает AI runnable, прямой fallback запрещён контрактом. Владелец отдельно запрошен о
возможности перенести локальные LLM proxies, SMTP и off-host destination; остальные работы
продолжаются. Штатный `backup-production.sh apply` ещё не реализован и не используется.

### Gemini adapter и реальный local backup

Override и adapter установлены; `compose config --quiet` exit 0. Одноразовый app command:

```bash
sudo -u deploy /srv/agelto/bin/compose run --rm --no-deps -T \
  --volume /srv/agelto/operations/20260912T174927Z/agelto-ops.QlWzSj6M/gemini-check.php:/gemini-check.php:ro \
  app php /gemini-check.php
```

Результат: `Gemini key loaded: yes`, затем canonical transport
`App\Exceptions\Procurement\Proxy\NoProxyAvailableException`, exit 1, exception message redacted.
HTTP к Google не выполнялся. Также read-only проверена локальная Agelto DB: `agelto|agelto`,
proxy count 0, active llm proxy count 0. Переносить из неё нечего. Validity/quota ключа API
не проверены, поскольку canonical outgoing route пока отсутствует.

Установлены только новые host-only scripts и четыре `agelto-backup-local*` units. Полный
фактически используемый code и воспроизводимая установка — `manual-backups.md`.

```bash
install -o deploy -g deploy -m 0755 "$operation_dir/agelto-ops.QlWzSj6M/backup-local" /srv/agelto/bin/backup-local
install -o deploy -g deploy -m 0755 "$operation_dir/agelto-ops.QlWzSj6M/backup-local-watchdog" /srv/agelto/bin/backup-local-watchdog
install -o deploy -g deploy -m 0644 "$operation_dir/agelto-ops.QlWzSj6M/backup-objects.php" /srv/agelto/bin/backup-objects.php
sudo -u deploy bash -c 'umask 077; openssl rand -base64 48 > /srv/agelto/backup-encryption.key'
chmod 0600 /srv/agelto/.release.lock
install -d -o deploy -g deploy -m 0700 /srv/agelto/backups/local
systemd-analyze verify /etc/systemd/system/agelto-backup-local.service \
  /etc/systemd/system/agelto-backup-local.timer \
  /etc/systemd/system/agelto-backup-local-watchdog.service \
  /etc/systemd/system/agelto-backup-local-watchdog.timer
systemctl daemon-reload
systemctl start agelto-backup-local.service
systemctl show agelto-backup-local.service -p Result -p ExecMainStatus
cat /srv/agelto/backups/local/latest.status
cat /srv/agelto/backups/local/20260912T175514Z/restore.receipt
sudo -u deploy /srv/agelto/bin/backup-local-watchdog
systemctl start agelto-backup-local-watchdog.service
systemctl show agelto-backup-local-watchdog.service -p Result -p ExecMainStatus
systemctl enable --now agelto-backup-local.timer agelto-backup-local-watchdog.timer
systemctl show agelto-backup-local.timer agelto-backup-local-watchdog.timer \
  -p ActiveState -p UnitFileState -p NextElapseUSecRealtime
sudo -u deploy bash -c 'cd /srv/agelto/backups/local/20260912T175514Z && sha256sum --check manifest.sha256'
```

Secret key generation выполнена **после** guard отсутствия target. Release lock уже существовал
deploy:0644 после первого adapter check; изменён только mode на 0600. Config backup key и
external env deploy:0600. Host `php -l` не выполнился: `php: command not found`; syntax уже
проверен локальным PHP, host PHP не устанавливался. `systemd-analyze verify` exit 0 с двумя
baseline warnings существующего XFS CPUAccounting; Agelto units warnings не дали.
`systemctl status` exit 3 после успешного oneshot — нормальное inactive/dead, не ошибка backup.
Контрольные `Result=success`, `ExecMainStatus=0` у backup и watchdog.

Первый real backup: `/srv/agelto/backups/local/20260912T175514Z`;
retained target `agelto_20260912t175514z_rehearsal`, 81 migrations, 1 user;
schema names/ledger `cmp` equal. Object export 0 (обе buckets сейчас пусты), S3 access успешен,
не является object-data restore proof. Config/secrets encrypted archive проверен decrypt/tar-list;
все шесть manifest artifacts checksum OK. Manifest SHA256:
`65fcdb204a54693479982d4dd4d440be01beb743f146c90cd66e166565052633`.
Latest status честно `verified_local=1`, `offhost=0`. Timer enabled/active:
backup next 2026-09-13 02:02:31 UTC, watchdog next 2026-09-12 18:03:52 UTC.
Реальный scheduled run/reboot пока не наблюдался; host не перезагружался.

Проверен stale branch с synthetic readonly status, без изменения настоящего latest.status:

```bash
docker run --rm --pull=never --network none --entrypoint bash \
  --volume /srv/agelto/bin/backup-local-watchdog:/backup-local-watchdog:ro \
  --volume /srv/agelto/operations/20260912T174927Z/stale.status:/srv/agelto/backups/local/latest.status:ro \
  agelto/app:manual-9a2a8225f96ba7e6915186e9bdc5298a7d39ee38 /backup-local-watchdog
sudo -u deploy /srv/agelto/bin/backup-local-watchdog
```

Synthetic epoch 0 дал ожидаемый `Agelto local backup is stale`, exit 1;
настоящий fresh status exit 0. Внешняя alert delivery не заявлена: нужны endpoint/credentials.

### Повторная сборка: PECL branch

Без build-only override повторился PECL REST timeout / missing pcov 1.0.12 XML, Make exit 2;
working release всё это время оставался healthy. Повторно проверено:

```bash
getent ahostsv4 pecl.php.net
curl --max-time 20 --resolve pecl.php.net:443:138.199.46.68 -sS -o /dev/null \
  -w 'PECL XML HTTP=%{http_code} TLS=%{ssl_verify_result} IP=%{remote_ip}\n' \
  https://pecl.php.net/rest/r/pcov/1.0.12.xml
curl --max-time 20 --resolve pecl.php.net:443:138.199.46.68 -sS -o /dev/null \
  -w 'PECL tarball HTTP=%{http_code} TLS=%{ssl_verify_result} IP=%{remote_ip}\n' \
  https://pecl.php.net/get/pcov-1.0.12.tgz
PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin AGELTO_PECL_BUILD_IP=138.199.46.68 \
  BUILDKIT_PROGRESS=plain make image:build IMAGE_TAG="$candidate_tag"
```

DNS сейчас 138.199.46.65; проверенный alternate 138.199.46.68 дал XML и tarball HTTP 200/TLS 0.
Override только command-local Docker build, host resolver/hosts и TLS verification не менялись.
Документированный workaround снова воспроизведён. Composer Doctor build сообщил PSR-4 warnings
для старых test fixture classes; tests/source не менялись и проверки не ослаблялись.

### Разовая зашифрованная off-host копия

Чтобы первая verified copy не осталась только на VPS, подготовлен full encrypted bundle
(а не только config archive) для protected owner workstation directory:

```bash
cd /srv/agelto/backups/local
umask 077
test ! -e 20260912T175514Z.tar.enc
tar -czf - 20260912T175514Z | openssl enc -aes-256-cbc -pbkdf2 -salt \
  -pass file:/srv/agelto/backup-encryption.key -out 20260912T175514Z.tar.enc
sha256sum 20260912T175514Z.tar.enc
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/srv/agelto/backup-encryption.key \
  -in 20260912T175514Z.tar.enc | tar -tzf - >/dev/null
```

Full bundle hash `732d4f3cf55b4b95d1928026f64a9e441899f683bb6170711b2005596744d777`.
Server decrypt/list exit 0. Download/verification results ниже записываются после завершения.
Автоматический off-host timer не объявляется готовым на основании этой разовой копии.

Download выполнен `scp -P 22` в `/home/vostrikov/agelto-production-backups` (owner 0700),
full bundle и отдельный recovery key — owner 0600. Local full-bundle SHA совпал с server SHA,
local decrypt/tar-list exit 0. Recovery key не выводился и не является private SSH key.
Настоящий `latest.status` не подменён на offhost=1: автоматический pipeline пока local-only.

### Дефекты, найденные именно при повторном runtime deploy

1. `/srv/agelto/releases` был root:root 0755: deploy `mkdir` отказался до переключения.
   Scoped `chown deploy:deploy /srv/agelto/releases` исправил только parent ownership.
2. Tar export под `umask 077` оставил candidate public 0700. Только новый public snapshot
   получил directories 0755/files 0644; host candidate parent 0755. Secrets не менялись.
   Manual guide теперь использует `tar --no-same-owner --same-permissions` и parent prerequisite.
3. Horizon унаследовал PHP/FPM `StopSignal=SIGQUIT`: `compose stop` ожидал, Horizon продолжал
   работать, хотя scheduler уже stopped. Во время drain HTTPS `/up` оставался 200.
   Vendor `ListensForSignals` подтверждает SIGTERM handler. Exact project label проверен,
   затем `docker kill --signal=TERM agelto_prod-horizon-1` в 18:03:53 UTC позволил graceful exit.
   Это не SIGKILL, grace 4200s не сокращён. Host override и tracked production Compose
   теперь явно задают Horizon `stop_signal: SIGTERM`.
4. Первый app switch отказался unhealthy/restarting: non-null Compose entrypoint удалил
   image CMD, `app.command=None`, Docker banner показывал пустой CMD. Root CLI secret smoke
   не поймал отсутствие FPM/Nginx. Добавлен app `command: ["/init"]`, adapter default при
   отсутствии arguments; explicit Horizon/scheduler/Doctor commands сохраняются.

Команды диагностики выполнялись scoped: `docker inspect` только State/StopSignal, `ps` PID/PPID/
comm/args только собственного Horizon, `horizon:status`; full application environment не выводился.
Диагностический SQL `SELECT count(*) FROM prc_runs` оказался неверным table name и получил
relation does not exist; никакой migration/data modification не выполнял и не считается проверкой.
Bootstrap/log sanitizer через `docker exec` отказался, когда app находился restarting. Следующая
попытка использовала ephemeral PHP-only app и redacted secret values до вывода logs, показав
пустой CMD. No private tokens/credentials вошли в report/log output.

Проверенный correction command и subsequent recreate:

```bash
test "$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' \
  agelto_prod-horizon-1)" = agelto_prod
docker kill --signal=TERM agelto_prod-horizon-1
install -o deploy -g deploy -m 0755 \
  "$operation_dir/agelto-ops.QlWzSj6M/external-env" /srv/agelto/bin/external-env
install -o deploy -g deploy -m 0600 \
  "$operation_dir/agelto-ops.QlWzSj6M/docker-compose.override.yml" \
  /srv/agelto/repository/docker-compose.override.yml
```

Deploy всё ещё schema-compatible docs-only candidate; после исправления повторяется
scoped `up -d --no-deps --no-build --pull never --wait app horizon scheduler`, readiness/Doctor,
HTTPS, сравнение immutable data-container IDs/Created. Итог записывается ниже по факту.

Для `quality:diagnosing-bugs` loop — rendered StopSignal/app command и реальный scoped stop/start.
Расширенный hypothesis fanout/production instrumentation пропущен явно: причины подтверждены
конкретными metadata и vendor handler, а устранение outage требует узкого reversible fix.
Read-only regression `bash docker/tests/horizon-stop-signal-contract.sh` проходит на исправленном
Compose; render исходного `366aba5e` с тем же assert падает именно на inherited StopSignal.
Full PHP/browser suite против production не запускалась. Отдельный code-only локальный коммит
`35bddd26` содержит два файла: production Compose и read-only regression script; push не выполнен.
Документационные изменения будут отдельным коммитом без кода, как требовал владелец.

Watchdog scheduled run действительно наблюдён: ExecMainStartTimestamp
2026-09-12 18:03:14 UTC, Result success / ExecMainStatus 0. Это hourly timer receipt,
не доказательство daily backup или поведения после reboot; соседний host не перезагружался.

### Candidate release verification

После entrypoint/CMD fix шесть containers healthy. Первый непосредственный readiness всё ещё
показал scheduler unavailable (нормальный startup без нового minute heartbeat) и остановил
командную цепочку до Doctor. После bounded retry штатного heartbeat readiness все 7 passed;
Doctor 27 diagnostics, failed 0, warnings 0, passed 24/skipped 2/notices 1.
Helper `/srv/agelto/bin/wait-readiness` установлен deploy:0755 и проверен Bash syntax + real
readiness exit 0. Руководство использует его на deploy/rollback, а не обещает immediate green.

Candidate image pair IDs (разные от previous pair):

- App `sha256:fd04b88a0e8b5db1e311cf3da08c65e83727fe59ccd6c38c4556e30d1b66d1a5`.
- Doctor `sha256:b596cdf5fa93a2ca27d47959ba437a2d71544f524362f2ac1443d2f9c9ba082a`.
- Revision обоих `366aba5eb9650f4bfa03d39cd735113ac7c0c213`.
- Runtime app/Horizon StopSignal SIGTERM; HTTPS /up 200 / TLS verify 0.

`docker exec --user www-data` первоначально получил DB no password supplied: новый exec process
не наследует изменённые entrypoint _FILE paths и не может читать original deploy:0600 mounts.
Правильный diagnostic exec с non-secret runtime _FILE paths дал www-data bootstrap OK / Gemini
key loaded yes. Original secret permissions **не** ослаблялись, FPM runtime наследует эти paths.

Browser smoke сначала получил local `ERR_NETWORK_CHANGED`, затем response-body race после
успешного Livewire redirect. Исправлен только temporary smoke harness: удалено чтение JSON-body
после automatic navigation (не assertion), остаются реальные login POST 200, final /admin,
auth/cookie/access/asset checks; console errors теперь тоже fail без raw message. Product
tests/guards не изменены. Итоговый run exit 0: admin login, Secure/HttpOnly/Lax cookies,
anonymous docs block, protected /admin и четыре docs routes 200 включая manual-deployment,
asset/page/console failures empty. Mermaid SVG по-прежнему не реализован; это не full E2E suite.

Local documentation build первоначально отказался из-за inline Go double-braces в Markdown:
VitePress/Vue принимал их за JS interpolation. Command перенесён в Bash code fence;
повторный `pnpm documentation:build && pnpm documentation:verify` exit 0 (309 files).
Новые docs не меняют archived plans/generated skills; root command matrix explicit check exit 0.

### Реальный rollback и возврат на финальный release

Обе операции выполнялись от deploy с scoped release lock. Previous и candidate имеют разные
app/Doctor image IDs, но одинаковую application schema: это проверка runtime переключения,
не разрешение откатывать будущую несовместимую migration. Previous app ID
`sha256:e76cf8eb861753f3bb867df2cde09f948d68470702474967375d281513ad1f48`, previous Doctor ID
`sha256:35cc9ff62bb08f4742564230095dda1709ab2f36cb429678962a8090e2d7024e`.

Фактически выполненный backward switch:

```bash
cd /srv/agelto/repository
operation_dir=/srv/agelto/operations/20260912T174927Z
exec 9>/srv/agelto/.release.lock
flock -n 9
previous_tag=$(cat "$operation_dir/tag.previous")
cmp "$operation_dir/pair.previous.ids" <(docker image inspect --format '{{.Id}}' \
  "agelto/app:$previous_tag" "agelto/doctor:$previous_tag")
test -d "/srv/agelto/releases/$previous_tag/public"
/srv/agelto/bin/compose stop horizon scheduler
install -m 0600 "$operation_dir/env.previous" .env.production
/srv/agelto/bin/compose config --quiet
/srv/agelto/bin/compose up -d --no-deps --no-build --pull never --wait app horizon scheduler
/srv/agelto/bin/wait-readiness
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
curl --fail --max-time 20 -sS https://agelto.ru/up >/dev/null
docker inspect --format '{{.Name}} {{.Id}} {{.Created}}' \
  agelto_prod-postgres-1 agelto_prod-redis-1 agelto_prod-minio-1 > "$operation_dir/infra.rollback"
cmp "$operation_dir/infra.before" "$operation_dir/infra.rollback"
docker inspect --format '{{.Config.Image}} {{.Image}}' agelto_prod-app-1
```

Result exit 0 / `ROLLBACK_VERIFIED`: previous app действительно active с previous ID,
readiness 7/7, Doctor failed=0/warnings=0, HTTPS /up 200. Horizon корректно остановился TERM;
PG/Redis/MinIO IDs и Created совпали до/после. Host override и secrets **не** откатывались.

После завершения backward process lock освобождён; новый process взял тот же lock и выполнил:

```bash
cd /srv/agelto/repository
operation_dir=/srv/agelto/operations/20260912T174927Z
exec 9>/srv/agelto/.release.lock
flock -n 9
/srv/agelto/bin/compose stop horizon scheduler
install -m 0600 "$operation_dir/env.candidate" .env.production
/srv/agelto/bin/compose config --quiet
/srv/agelto/bin/compose up -d --no-deps --no-build --pull never --wait app horizon scheduler
/srv/agelto/bin/wait-readiness
make prod:seed-base COMPOSE_PROD=/srv/agelto/bin/compose
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
curl --fail --max-time 20 -sS https://agelto.ru/up >/dev/null
docker inspect --format '{{.Name}} {{.Id}} {{.Created}}' \
  agelto_prod-postgres-1 agelto_prod-redis-1 agelto_prod-minio-1 > "$operation_dir/infra.final"
cmp "$operation_dir/infra.before" "$operation_dir/infra.final"
docker inspect --format '{{.Config.Image}} {{.Image}}' agelto_prod-app-1
```

Result exit 0 / `FORWARD_SWITCH_AND_FINAL_SEEDS_VERIFIED`. Active tag
`manual-366aba5eb9650f4bfa03d39cd735113ac7c0c213-20260912T174930Z` с candidate app ID выше.
Startup readiness дождался реального minute heartbeat; BaseSeeder все семь nested seeders DONE.
Doctor снова 27 diagnostics / failed 0 / warnings 0. Data-container identity cmp exit 0.

### Резервная копия именно финальной конфигурации

Повторный backup нужен, поскольку первая копия была до исправления adapter CMD. Выполнены:

```bash
systemctl start agelto-backup-local.service
systemctl start agelto-backup-local-watchdog.service
SYSTEMD_PAGER=cat systemctl show agelto-backup-local.service \
  agelto-backup-local-watchdog.service -p Id -p Result -p ExecMainStatus
sudo -u deploy cat /srv/agelto/backups/local/latest.status
```

Обе units Result=success / ExecMainStatus=0. Две ошибочные попытки прочитать
`latest-local.status` (в local и затем backups parent) получили no such file; правильный
script-defined path — `local/latest.status`, docs содержат именно его. `sudo -u deploy find`
из root cwd также предупреждал о невозможности вернуться в `/root`; никакие права /root
не менялись, следующие deploy операции имеют явный cwd.

Final backup `/srv/agelto/backups/local/20260912T181348Z`; retained restored DB
`agelto_20260912t181348z_rehearsal`, 81 migrations / 1 user, schema comparison matched.
Manifest SHA `1b5f1d2f39fd8005e80204929fcd7f3892692a6f9403f53e3413ac7773fc7d3c`.
Шесть artifact checksums OK. Автоматический receipt честно сохраняет `offhost=0`.

```bash
umask 077
backup_dir=/srv/agelto/backups/local/20260912T181348Z
cd "$backup_dir"
sha256sum --check manifest.sha256
cat restore.receipt
test ! -e "${backup_dir}.tar.enc"
tar -C /srv/agelto/backups/local -czf - 20260912T181348Z | \
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:/srv/agelto/backup-encryption.key \
  -out "${backup_dir}.tar.enc"
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/srv/agelto/backup-encryption.key \
  -in "${backup_dir}.tar.enc" | tar -tzf - >/dev/null
sha256sum "${backup_dir}.tar.enc"
```

Финальная encrypted копия скачана без вывода secrets:

```bash
scp -P 22 root@46.8.220.57:/srv/agelto/backups/local/20260912T181348Z.tar.enc \
  /home/vostrikov/agelto-production-backups/20260912T181348Z.tar.enc
chmod 0600 /home/vostrikov/agelto-production-backups/20260912T181348Z.tar.enc
sha256sum /home/vostrikov/agelto-production-backups/20260912T181348Z.tar.enc
openssl enc -d -aes-256-cbc -pbkdf2 \
  -pass file:/home/vostrikov/agelto-production-backups/backup-encryption.key \
  -in /home/vostrikov/agelto-production-backups/20260912T181348Z.tar.enc | tar -tzf - >/dev/null
```

Local/server full bundle SHA совпали:
`87567c59ea83f64ac589b2949a73b372dec24c5a847c695454b2c777dbf793b1`.
Обе decrypt/list проверки exit 0. Recovery key не перезаписывался, его custody описана
в manual backup guide; он не хранится в Git. Разовая внешняя копия не заменяет автоматизацию.

### Финальная повторная проверка и оставшиеся реальные зависимости

Фактические проверки после forward switch:

```bash
/srv/agelto/bin/compose ps
/srv/agelto/bin/compose exec -T app php artisan horizon:status
/srv/agelto/bin/compose exec -T app php artisan app:readiness --format=agent
/srv/agelto/bin/compose exec -T postgres psql -U agelto -d agelto -Atc \
  'SELECT current_database(),current_user; SELECT count(*) FROM migrations; SELECT count(*) FROM users; SELECT count(*) FROM prc_proxies;'
nginx -t
systemctl is-active certbot.timer agelto-backup-local.timer agelto-backup-local-watchdog.timer
sudo -u deploy git -C /srv/agelto/repository status --short
sudo -u deploy git -C /srv/agelto/repository ls-remote origin refs/heads/main
```

Six services healthy; app/Horizon/scheduler restarts=0. Readiness все 7 passed, Horizon running;
current_database/current_user `agelto|agelto`, migrations 81, users 1, managed proxies 0.
Последние 50/41/50 log lines app/Horizon/scheduler проверены без raw output (awk считает
ERROR/CRITICAL/EMERGENCY/Fatal/Exception): 0 markers. Это ограниченная log проверка, не полный
security/functional audit. Nginx syntax exit 0, три timers active. Checkout clean, readonly
GitHub access успешен, remote main SHA `366aba5eb9650f4bfa03d39cd735113ac7c0c213`.

HTTPS запросы повторены curl с certificate verification: agelto.ru / и /up 200/TLS=0;
dterema.localmost.ru 200, cms.dterema.localmost.ru 200, insiders.localmost.ru 302/TLS=0,
как до этого этапа. Соседние проекты не останавливались/не пересоздавались нашими командами;
их ранее замеченные queue RestartCounts не выдаются за отсутствие исторических событий.

Финальный browser smoke сначала timeout на login response; повторный run exit 0 подтвердил
login POST 200, /admin, Secure/HttpOnly/Lax session, пять protected routes 200 и anonymous
documentation redirect to login. Asset/page/console failures empty. Full mutating production
E2E suite не запускалась, Mermaid rendering остаётся отдельным известным frontend gap.
Пароль admin не менялся; его ранее переданный protected local file не включён в Git/backup report.

| Шаг инструкции | Факт на сервере | Результат | Что уточнено в документации |
|---|---|---|---|
| Ручной build | PECL endpoint отказался; alternate HTTPS verified IP сработал | Новая immutable app/Doctor pair собрана | Scoped build-only override и реальные проверки |
| Secret/config loading | Новый exec и FPM имеют разные _FILE paths; Compose entrypoint сбрасывает CMD | Gemini source mounted 0600, www-data/FPM load проверен | External-env adapter, explicit /init, no secret dump |
| Worker drain | Inherited SIGQUIT не обрабатывался Horizon | TERM исправлен, оба switches graceful | Explicit SIGTERM, grace 4200s, regression |
| Startup health | Scheduler требует настоящего minute heartbeat | Bounded retry 7/7 | wait-readiness вместо immediate green |
| Backup | Tracked apply — fixture, daily runtime раньше отсутствовал | Новые local timer/watchdog + два настоящих PG restore | Отдельный воспроизводимый manual-backups runbook |
| Off-host | Две encrypted copies реально на рабочей машине | Проверены hashes/decrypt; automation offhost=0 | Разовая copy не выдаётся за автоматический DR |
| Rollback | Previous/candidate IDs различны, schema одинакова | Backward и forward exit 0, data IDs unchanged | Compatibility before drain, --no-deps scoped switch |
| LLM/SMTP | Key loaded, proxies 0; local SMTP placeholders | Gemini fail-closed до Google HTTP, email log only | Нужны реальные credentials, не обещать API success |
| Documentation | Inline Go braces ломали Vue build | Command fence исправлен, build/verify пройдены | Корректный Markdown и актуальная source matrix |

Для полного внешнего production функционала остаются **только внешние данные/решения**:

- LLM proxy endpoint/credentials с известной разрешённой страной (не RU/unknown), active и
  scope llm в каноническом managed pool. Production и local pools пустые; обход ProxyResolver
  запрещён. Gemini key перенесён, но его validity/quota/API result до этого не проверены.
- Реальный SMTP host/port/scheme/user/password и подтверждённый sender, если нужна отправка
  почты: local `.env` содержит log и placeholders, не рабочую SMTP учётную запись.
- Off-host destination/access для автоматической копии и alert destination/access для доставки
  уведомлений. Сейчас local daily automation + local journal watchdog и verified one-time
  encrypted download. Нужен также согласованный retention/capacity порядок: rehearsal DBs и
  backups сохранены, автоматическое удаление запрещено, disk floor останавливает job при нехватке.
- Личный SSH key для deploy владелец добавляет сам, по исходному запрету он не создавался.

GitHub read-only key, migrations, BaseSeeder, DNS, TLS и runtime rollback больше не pending.
Новых shared host changes, отключения firewall/SSH, удаления данных/volumes/certificates нет.
Code-only fix `35bddd26a55d1db7b4d78e8cc112c1152fb7347d` и следующий docs-only commit
без отдельного указания не пушатся. Server остаётся на опубликованном clean main; безопасный
host override уже содержит исправление SIGTERM. Новые инструкции находятся в local checkout,
а не выдаются за опубликованную server documentation build.

Дополнительная read-only network проверка в конце:

```bash
docker network inspect --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}' \
  bridge dterema_app_default dterema_backend_dterema_cms_network insiders_insiders_network host none
```

Exit 0; shared network subnets остались 172.17/18/19/20, Agelto отдельно 172.21.
Final documentation syntax check выявил старый псевдо-placeholder network inspect в audit
fence (не shell command); перенесён в comment и заменён точной фактической командой здесь.
Начальная rollback-карта также уточнена на сохранение checkout/key/site и scoped stop,
без удаления. Ни одна команда удаления из прежней карты не выполнялась.

Local final verification: `git diff --check`, explicit root command-matrix check,
read-only Horizon stop-signal regression и documentation build/verify (309 files) exit 0.
Shell fences проверяются через `bash -n`/`sh -n`, не выполняются против production.
Documentation-only commit stage включает только Markdown; separate code fix уже закоммичен.
Нативные archived plans/generated skill bundles не изменялись.

Применены owner-autonomy/task:task-dev для owner gates и исполнения; Docker router и
docker-php/postgres/dev-prod/services для проверки существующего isolated manual stack;
node-pnpm-preflight перед Node/commit tooling; diagnosing-bugs для доказанного signal/CMD
runtime fix; playwright-e2e для ограниченного безопасного authenticated smoke вместо
mutating full production suite. Инспектированные, но неприменённые skills не считаются использованными.

Точные команды последней ограниченной log/browser/local проверки:

```bash
for container in agelto_prod-app-1 agelto_prod-horizon-1 agelto_prod-scheduler-1; do
    docker inspect --format '{{.Name}} status={{.State.Status}} health={{.State.Health.Status}} restarts={{.RestartCount}}' "$container"
    docker logs --tail 50 "$container" 2>&1 | \
      awk 'BEGIN {n=0; err=0} {n++; if ($0 ~ /ERROR|CRITICAL|EMERGENCY|Fatal|Exception/) err++} END {printf "Recent log lines=%d error-markers=%d\n",n,err}'
done
# Только на local workstation, credentials читаются harness в память, без stdout secrets:
node /tmp/agelto-admin-credentials.qHn6Cw/smoke.cjs
git diff --check
python3 docker/scripts/check-command-reference.py check --output DEPLOYMENT_COMMAND_MATRIX.md
bash docker/tests/horizon-stop-signal-contract.sh
pnpm documentation:build && pnpm documentation:verify
```

В полном final syntax pass проверено 102 Bash/sh fences (включая эти command blocks).
Latest operational report копируется отдельным файлом `/srv/agelto/DEPLOYMENT_REPORT.latest.md`
в deployment root (deploy:deploy 0644), вне Git checkout: tracked root report сервера остаётся
на опубликованном main, `git pull --ff-only` не блокируется незакоммиченным overwrite.

```bash
scp -P 22 DEPLOYMENT_REPORT.md root@46.8.220.57:/srv/agelto/DEPLOYMENT_REPORT.latest.md
# root SSH session:
chown deploy:deploy /srv/agelto/DEPLOYMENT_REPORT.latest.md
chmod 0644 /srv/agelto/DEPLOYMENT_REPORT.latest.md
sha256sum /srv/agelto/DEPLOYMENT_REPORT.latest.md
# local:
sha256sum DEPLOYMENT_REPORT.md
```

Перед final handoff hashes operational report и local file сверяются; Git commit не публикуется.

## Продолжение: opt-in backup и собственный исходящий SMTP

Решение владельца: отсутствие внешних настроек не блокирует приложение; backup включается
только env flag, SMTP отправляет с самого VPS, local proxies переносятся только при наличии.
Это заменяет предыдущую необходимость немедленно получить SMTP/off-host credentials.
Документационный commit прошлого этапа `bf43875e56b9dcab6015fa73ff7bf8b3aba4703b` не пушился.

### Read-only аудит перед изменением

Повторно прочитаны AGENTS, manual deploy/backup и actual mail/Compose configuration, проверены
clean local/server worktrees и git-ignore targets. Root подключён exact SSH command, password
интерактивный, `set +o history`; shared firewall/SSH/Nginx/neighbor settings не изменялись.

```bash
ss -lntp | awk 'NR==1 || $4 ~ /:(25|465|587|2525)$/'
for binary in postfix postconf exim sendmail dig nc openssl; do command -v "$binary" || true; done
dpkg-query -W -f='${Package} ${Status}\n' postfix exim4 opensmtpd msmtp 2>/dev/null || true
systemctl list-units --all --no-pager 'postfix*' 'exim*' '*smtp*'
docker ps --format '{{.Names}} {{.Image}}' | awk '/postfix|smtp|mail/'
hostname -f
getent hosts 46.8.220.57
/srv/agelto/bin/compose ps
systemctl show agelto-backup-local.service -p ActiveState -p Result -p ExecMainStatus
# Local exact Agelto DB, read-only:
docker exec agelto_dev-postgres-1 psql -U agelto -d agelto -Atc \
  'SELECT current_database(), current_user; SELECT count(*) FROM prc_proxies;'
```

Local identity `agelto|agelto`, proxies 0: переноса нет, пользователь заполнит own pool вручную.
На VPS нет SMTP binaries/packages/services/containers/listeners. `dig`/`nc` также отсутствовали;
attempted dig commands завершились command not found. Host packages ради диагностики не ставились.
Следующая DNS проверка выполнена PHP в собственном app container:

```bash
docker exec agelto_prod-app-1 php -r 'foreach (["agelto.ru","mail.agelto.ru","_dmarc.agelto.ru","agelto._domainkey.agelto.ru","57.220.8.46.in-addr.arpa"] as $name) {echo $name," ",json_encode(dns_get_record($name,DNS_A|DNS_MX|DNS_TXT|DNS_PTR)),PHP_EOL;}'
docker exec agelto_prod-app-1 php -r 'echo json_encode(dns_get_record("flexl.ru",DNS_A)),PHP_EOL;'
```

Факт DNS: Agelto A 46.8.220.57; MX priorities 10/20 оба mail.agelto.ru;
mail.agelto.ru A 104.128.128.241. Root TXT/SPF, _dmarc и выбранный DKIM selector отсутствовали.
PTR IP → flexl.ru, forward flexl.ru → same IP: согласованы. Существующие MX/mail A/PTR сохранены.

### Backup feature установлен, но выключен

Новый canonical source — `docker/host/backup/` (payload/exporter перенесены из собственного
проверенного runbook, не из чужого проекта). Safe launcher читает отдельный ignored env,
но **не source-ит** его. Допустим ровно один literal BACKUP_ENABLED=true/false, comments/blanks;
invalid/duplicate/symlink/missing profile fail closed. False — exit 0 **без payload**.
Watchdog использует тот же gate: отключённые backups не создают stale alarms.

Staging создан `mktemp -d /tmp/agelto-optional.XXXXXX`, candidate files через apply_patch,
передача `scp -P 22 -r` в `/srv/agelto/operations/agelto-optional.qAqG26` (без application secrets).
Старые own scripts сохранены `backup-local.before`/`backup-local-watchdog.before` в этом directory.
Установленные файлы:

```bash
stage=/srv/agelto/operations/agelto-optional.qAqG26
cp -p /srv/agelto/bin/backup-local "$stage/backup-local.before"
cp -p /srv/agelto/bin/backup-local-watchdog "$stage/backup-local-watchdog.before"
install -o deploy -g deploy -m 0755 /srv/agelto/bin/backup-local /srv/agelto/bin/backup-local-enabled
install -o deploy -g deploy -m 0755 /srv/agelto/bin/backup-local-watchdog /srv/agelto/bin/backup-local-watchdog-enabled
install -o deploy -g deploy -m 0755 "$stage/backup-env-gate" /srv/agelto/bin/backup-env-gate
install -o deploy -g deploy -m 0600 "$stage/backup.env" /srv/agelto/repository/.env.backup
install -o deploy -g deploy -m 0755 "$stage/backup-local" /srv/agelto/bin/backup-local
install -o deploy -g deploy -m 0755 "$stage/backup-local-watchdog" /srv/agelto/bin/backup-local-watchdog
sudo -u deploy /srv/agelto/bin/backup-local
sudo -u deploy /srv/agelto/bin/backup-local-watchdog
systemctl start agelto-backup-local.service agelto-backup-local-watchdog.service
systemctl show agelto-backup-local.service agelto-backup-local-watchdog.service -p Id -p Result -p ExecMainStatus
cat /srv/agelto/backups/local/latest.status
```

Фактически оба public launchers и обе systemd units — disabled no-op / success 0.
Последний receipt остался `20260912T181348Z` с тем же SHA: новая копия/restore DB не создавались.
Timers сохраняются enabled как механизм будущего запуска, но не выполняют backup при false.
Раннее намерение «отключить расписание» реализовано gate-ом: смены только env достаточно,
включать units через sudo заново не нужно. App/Horizon/scheduler от flag не зависят.

После добавления SMTP payload обновлён: guarded own project/volume/digest identity, encrypted
DKIM и SMTP-config archives, archive также включает backup flag/scripts. Final script SHA
`c88150e2c558db4b2b2654ed29d602375e3383fee4ebf0980e83aa1144b901b2` совпал local/server.
Новый enabled payload прошёл syntax; full backup под true **не** запускался согласно новому
решению владельца. Предыдущие настоящие PostgreSQL restore receipts остаются историческими.
Включение: редактором `.env.backup` → BACKUP_ENABLED=true, затем первый ручной launcher,
fresh receipt/checksums/capacity review по `manual-backups.md`. Выключение — false, без удаления.

### Direct SMTP: подготовка и реальные границы проверки

Три host TCP25 probes дали timeout; повтор из app container использовал explicit IPv4,
исключая ошибочный вывод по отсутствию IPv6:

```bash
for mx in gmail-smtp-in.l.google.com mx.yandex.ru mxs.mail.ru; do
  timeout 10 bash -c 'exec 7<>/dev/tcp/"$1"/25; IFS= read -r banner <&7; printf "%s\n" "$banner"; printf "QUIT\r\n" >&7' bash "$mx" || true
done
docker exec agelto_prod-app-1 php -r 'foreach (["gmail-smtp-in.l.google.com","mx.yandex.ru","mxs.mail.ru"] as $host) {$r=dns_get_record($host,DNS_A);$ip=$r[0]["ip"]??null;if(!$ip){echo "NO_A\n";continue;}$s=@stream_socket_client("tcp://".$ip.":25",$errno,$err,5);echo $host," ",$ip," CONNECT=",$s?"OK":"FAIL"," errno=",$errno,PHP_EOL;if($s){stream_set_timeout($s,5);fgets($s);fwrite($s,"QUIT\r\n");fclose($s);}}'
```

Explicit IPv4 results: Gmail 209.85.233.26, Yandex 77.88.21.249, Mail.ru 94.100.180.31 —
все CONNECT=FAIL errno=110. Вероятна hosting/network block, точный источник не установлен.
Нужно разрешение outbound TCP25 у provider, не открытие публичного inbound listener и не
отключение firewall. DNS сам по себе этот timeout не исправит. SMTP container probe nc также failed.

Выбран third-party image автора bokysan (не official Postfix image); release-specific primary
docs/release API проверены. Master упоминал unreleased 6.0.0: попытка ghcr 6.0.0 not found;
ghcr 5.1.0 также not found. Docker Hub `boky/postfix:5.1.0` pull exit 0, digest pinned:

```bash
sudo -u deploy docker pull boky/postfix:5.1.0
docker image inspect --format '{{json .RepoDigests}} {{.Config.Healthcheck.Test}}' boky/postfix:5.1.0
docker run --rm --pull=never --network none --entrypoint sed boky/postfix:5.1.0 -n '1,180p' /scripts/healthcheck.sh
```

Digest `sha256:aafc772384232497bed875e1eb66b4d3e54ba1ebc86e2e185a6dc1dbc48182ef`;
built-in health probes SMTP banner and DKIM socket, сохранён без weakening. No host MTA installed.

```bash
install -d -o deploy -g deploy -m 0700 /srv/agelto/mail
install -o deploy -g deploy -m 0600 "$stage/mail.env" /srv/agelto/mail/.env.mail
install -o deploy -g deploy -m 0644 "$stage/compose.yml" /srv/agelto/mail/compose.yml
install -o deploy -g deploy -m 0755 "$stage/mail-compose" /srv/agelto/bin/mail-compose
sudo -u deploy /srv/agelto/bin/mail-compose --profile smtp config --quiet
sudo -u deploy /srv/agelto/bin/mail-compose --profile smtp up -d --no-build --pull never --wait smtp
docker exec agelto_mail-smtp-1 postconf myhostname mynetworks inet_protocols smtpd_relay_restrictions smtpd_sender_restrictions smtpd_milters
docker exec agelto_mail-smtp-1 postconf smtpd_recipient_restrictions
docker exec agelto_mail-smtp-1 postqueue -p
docker inspect --format '{{json .HostConfig.PortBindings}}' agelto_mail-smtp-1
docker exec agelto_mail-smtp-1 cat /etc/opendkim/keys/agelto.ru.txt
docker exec agelto_mail-smtp-1 stat -c '%a %U:%G %n' /etc/opendkim/keys/agelto.ru.private
```

Own SMTP `agelto_mail-smtp-1` healthy; queue empty; no published host ports (`{}`). Existing
own Agelto network only, trust loopback + 172.21/16, sender agelto.ru. New volumes
`agelto_mail_mail_queue` and `agelto_mail_dkim_keys`; private DKIM file opendkim:opendkim 0400,
никогда не читался/копировался в plaintext output/Git. Отдельный public TXT ниже — не secret.
Попытка PHP lint в SMTP image отказалась (PHP отсутствует); diagnostics выполнены actual PHP
app container, ненужный PHP в mail image/host не устанавливался.

После передачи candidate smoke/payload SCP и install выполнены:

```bash
install -o deploy -g deploy -m 0755 "$stage/backup-local-enabled" /srv/agelto/bin/backup-local-enabled
install -o deploy -g deploy -m 0644 "$stage/smtp-smoke.php" /srv/agelto/bin/smtp-smoke.php
docker exec -i agelto_prod-app-1 php < /srv/agelto/bin/smtp-smoke.php
```

Первый inline envelope probe и последующий canonical smoke оба exit 0. SMTP EHLO/MAIL/RCPT/
RSET 250, QUIT 221; unauthorized sender example.invalid rejected at RCPT 554.
**NO DATA SENT**, ни одного письма не создавалось; delivery и received DKIM/SPF/DMARC не проверены.
Согласованного external recipient нет, а port25 пока недоступен: не имитировать success.

### Application env и безопасный scoped switch

Server override до изменения SHA совпал с local previous candidate; новые SMTP fields добавлены
в общий PHP anchor, но credentials не помещены в environment. Own env/override snapshots
`env.before-mail`/`compose.before-mail`, data identity `infra.before-mail` в protected stage.
Первая preflight от deploy отказалась читать root:0700 staging candidate — до stop/services
изменений. Preflight повторена root, permissions root directory не ослаблялись.

```bash
cd /srv/agelto/repository
cp -p .env.production "$stage/env.before-mail"
cp -p docker-compose.override.yml "$stage/compose.before-mail"
docker compose --project-directory /srv/agelto/repository --env-file .env.production \
  -f docker-compose.prod.yml -f "$stage/docker-compose.override.yml" config --quiet
exec 9>/srv/agelto/.release.lock
flock -n 9
sudo -u deploy /srv/agelto/bin/compose stop horizon scheduler
install -o deploy -g deploy -m 0600 "$stage/docker-compose.override.yml" docker-compose.override.yml
sed -i -e '/^MAIL_HOST=/d' -e '/^MAIL_PORT=/d' -e '/^MAIL_SCHEME=/d' \
  -e '/^MAIL_EHLO_DOMAIN=/d' \
  -e '$aMAIL_HOST=agelto-smtp\nMAIL_PORT=587\nMAIL_SCHEME=null\nMAIL_EHLO_DOMAIN=flexl.ru' .env.production
chown deploy:deploy .env.production
chmod 0600 .env.production
sed -i 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=smtp/' /srv/agelto/mail/.env.mail
sudo -u deploy /srv/agelto/bin/compose config --quiet
sudo -u deploy /srv/agelto/bin/compose up -d --no-deps --no-build --pull never --wait app horizon scheduler
sudo -u deploy /srv/agelto/bin/wait-readiness
```

Exit 0 / `MAIL_CONFIGURATION_SCOPED_SWITCH_VERIFIED`; app/Horizon/scheduler healthy,
readiness all7 passed. PG/Redis/MinIO `.Id .Created` before/after cmp unchanged. App image tag
и schema не менялись, migrations/seeds этой операцией не запускались. Runtime allowlist config
после bootstrap: mailer=log, host=agelto-smtp, port=587, ehlo=flexl.ru,
from=noreply@agelto.ru, gemini_loaded=true; actual key value не выводился.
Mailer log intentional до working port25/DNS/owner recipient delivery proof.

Rollback: сохранить false backup flag; для own mail env/override вернуть только protected
before-mail copies root install 0600, scoped worker drain/up/readiness. New SMTP можно сохранить
или scoped `mail-compose --profile smtp stop smtp`; queue/keys/volumes не удалять.
No shared Nginx reload был нужен: `nginx -t` exit 0, HTTPS homepage/health 200 TLS=0;
neighbor domains повторно 200/200/302 TLS=0, commands stop/recreate к ним не применялись.
Doctor 27 diagnostics / failed0 / warnings0, own timers active, server checkout clean.

### Точные DNS-записи для владельца

Это public per-installation данные. Текущие MX и mail.agelto.ru **не менять**.
PTR и forward flexl.ru уже верны, менять shared reverse DNS не требуется.
Сначала provider должен проверить/разрешить исходящий TCP25.

| Тип | DNS name | Value / действие |
|---|---|---|
| TXT SPF | agelto.ru / @ | `v=spf1 ip4:46.8.220.57 ~all`; если SPF появился, объединить sources в единственную запись |
| TXT DMARC | _dmarc.agelto.ru | `v=DMARC1; p=none; adkim=r; aspf=r` — monitoring, не enforcement |
| TXT DKIM | agelto._domainkey.agelto.ru | Публичное значение из следующего block; concatenate без пробелов в p= |

```text
v=DKIM1; h=sha256; k=rsa; s=email; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzgWYPw9d7T5W98MPaaq+cNRl5W4pC5s+GxE8YBUdaM6IVeuWZKcigfZFFB6sFoVP0bDXBzkjQr8sNcGpDOzGv7uxz1cA5fkOQtf0hztHJ0cgXY8349tideZ9sqqKo52ZUfT/qqTITXdkXtwYSSbAY5CxOrKYeSuWcRrBQNIo7oR23fJ7Fa2+LTaDN/JfjrxnezcoQKi5j5ZSMLo4JZLjWCWhWvN9gRtL3VMrIwU/d7hWvDWunsR2xiqrlCvChLPvXxBtVzaxqH75cvQCwZPMW1lHvD/KgcJ4vlD5mvRHBQ0TDlMBx5C/rjZ7IhV2hgHtVOAyteTwDDLp66sy98yKbQIDAQAB
```

После records + port25 нужно проверить письмо на адрес владельца и authentication headers,
только затем MAIL_MAILER=smtp и обычный scoped application restart. Не обещается inbox success
на основании DNS, internal SMTP envelope или healthy status. Условия сверены с release-specific
author docs и Gmail sender guidelines, ссылки находятся в `server-mail.md`.

### Сверка runbook и handoff этого этапа

| Шаг инструкции | Факт на сервере | Результат | Что доработано |
|---|---|---|---|
| Backup activation | Старые timers выполняли job сразу | Env false → no payload / no stale watchdog | Shared strict gate, canonical source scripts, opt-in runbook |
| SMTP configuration | Нет server SMTP; чужой MX ведёт на другой IP | Own private SMTP/DKIM healthy, sender ACL passed | New server-mail runbook, no host ports, pinned release/digest |
| External delivery | Три explicit IPv4 TCP25 timeouts | Mailer intentional log, delivery unverified | Provider gate отдельно от DNS, no fake sent receipt |
| DNS | SPF/DKIM/DMARC absent; PTR confirmed | Точные public TXT values подготовлены, existing MX preserved | Current DNS state и безопасные owner commands |
| Local proxies | Own DB pool 0 | Нечего переносить, owner заполнит вручную | Отсутствие pool не блокирует startup, AI fail closed |
| Runtime switch | Own mail params не передавались explicit Compose env | Healthy6/readiness7, data identities unchanged | Shared mail fields, protected snapshots/scoped rollback |

Code-only commit `f0ebdb261ec56953b9286feeb05416c94e77f7b2` содержит 12 host source/test files,
без документации/secrets. Push не выполнялся; docs отдельным successor commit. Server latest
report копируется вне clean Git checkout как и ранее. Full mutating production suite не запускалась.
Focused checks: backup gate disabled/enabled, invalid/missing/duplicate/symlink/injection and
payload-exit contracts; SMTP read-only Compose isolation/digest/sender/volumes contract;
PHP/Bash syntax; unchanged Horizon contract; root command matrix check; docs build/verify.
Новый enabled backup с SMTP archives намеренно не запускался при false.

Использованные skills: owner-autonomy/task:task-dev сохранили owner opt-in и scope;
Docker router/required PHP/Postgres/services проверили изоляцию без shared-host bootstrap;
verify-claims различил unreleased master от actual 5.1.0 и SMTP health от Gmail delivery;
node-pnpm-preflight проверил tooling; php:named-arguments применён к new smoke closure calls.
Diagnosing-bugs/Playwright из прошлого этапа в этом этапе не активировались.

Final local checks этого этапа exit 0: Bash/sh syntax (на момент проверки 111 fences),
PHP syntax обоих host tools, все focused contracts и root matrix check; documentation
build/verify 312 files. Public DKIM RSA key 2048 bits; hash его base64 `p=`
`8888630975e22e872b89e0c58c94240bfac55f909b110d9c5cf837bd936ec172` совпал с server public TXT.
Никакого private key материала эта проверка не читала.

Latest report этого этапа передан отдельным operational snapshot (не tracked checkout overwrite):

```bash
scp -P 22 DEPLOYMENT_REPORT.md root@46.8.220.57:/srv/agelto/DEPLOYMENT_REPORT.latest.md
chown deploy:deploy /srv/agelto/DEPLOYMENT_REPORT.latest.md
chmod 0644 /srv/agelto/DEPLOYMENT_REPORT.latest.md
sha256sum /srv/agelto/DEPLOYMENT_REPORT.latest.md
# local counterpart:
sha256sum DEPLOYMENT_REPORT.md
```

Server/local hashes сверяются после передачи, затем закрывается root session; новые commits
не пушатся. Текущее решение владельца не требует немедленного off-host destination/SMTP
credentials: backup feature default off, direct SMTP подготовлен, delivery deferred до
working port25/DNS/owner recipient. Proxy pool будет заполнен владельцем через admin.

## 2026-09-13: нижний регистр и переименование разделов документации

По указанию владельца локальное дерево изменено:
`00_Ideas` → `ideas`, `01_Business` → `business`, `02_Workflow` → `workflow`,
`03_Dev` → `dev`, `04_Operators` → `operators`. `meta` уже имел нужное имя.
Обновлены Markdown-ссылки, VitePress rewrites/menu/sidebar, маршрутизация AGENTS/CLAUDE,
исходные guidelines, ссылки локальных skills и проверки, использующие эти пути.
Архивные планы/исследования и исторические команды этого отчёта не переписывались.

Выполненные локальные команды и результат:

```bash
pnpm --version
node --version
pnpm documentation:build
pnpm documentation:verify
make test-preflight
make agent:artisan ARGS='test --ci --no-tia --compact tests/Unit/Docs tests/Unit/Documentation tests/Feature/Documentation'
docker compose --project-name agelto_dev exec -T app vendor/bin/pint --test tests/Unit/Docs/DocsIntegrityTest.php tests/Feature/Documentation/PrivateDocumentationResponseTest.php
python3 docker/scripts/check-command-reference.py check --output DEPLOYMENT_COMMAND_MATRIX.md
git diff --name-only -- docker/tests | xargs -r -n 1 bash -n
git diff --check
```

Результаты: pnpm 9.15.0 / Node 24.12.0, build/verify — 312 файлов; test preflight подтвердил
`agelto_test`; полный выбранный corpus документации — 31 тест / 121 assertion passed,
без TIA; Pint — 2 файла passed; command matrix и Bash syntax — exit 0. Дополнительная
проверка артефактов подтвердила 7 ключевых новых routes в manifest и отсутствие старых
разделов в путях артефактов и HTML-навигации.

Первый focused HTTP-прогон после механической замены завершился 7 ошибками: fixture manifest
оставлял `business/index.html` перед `article.html`, нарушая обязательный byte-order contract.
Исправлен только порядок fixture; безопасность manifest не ослаблялась. Повторный focused
прогон — 14/14 passed, затем расширенный corpus — 31/31 passed.

Новая инструкция доступна в исходниках по `docs/operators/production/manual-deployment.md`,
в собранном портале — `/documentation/operators/production/manual-deployment`.
Артефакты пересобраны локально в `var/private-documentation`; production image и серверная
копия отчёта в этом изменении не обновлялись, SSH-команды не выполнялись. Публикация портала
на production потребует нового immutable application image по ручной инструкции; обычный
code-only deploy не заменяет документацию внутри image. Внешний Brain-vault не изменялся:
его структуру нужно согласовать при следующем Brain-sync, не дублируя старые разделы.
