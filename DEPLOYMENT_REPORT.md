# Отчёт практического развёртывания Agelto

Дата начала: 2026-09-12

Целевой сервер: `46.8.220.57:22`

Ветка: `main`

Итоговый статус: HTTPS работает, шесть сервисов Agelto healthy, readiness 7/7 passed,
Doctor без failures/warnings. Миграции завершены после подтверждения владельца, BaseSeeder
повторно выполнен в конце, вход SuperAdmin проверен в браузере. PostgreSQL backup реально
восстановлен в отдельную rehearsal DB. Ниже сохранены также промежуточные неуспехи и остановки;
они не описывают итоговое состояние. Documentation-only коммит создаётся после проверки этого
отчёта; его хеш сообщается владельцу отдельно, без push.

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
docker network inspect <каждая_существующая_сеть>
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
| Новый checkout до запуска контейнеров | Удалить только новый `/srv/agelto` после проверки, что это Agelto и в нём нет требуемых данных. |
| Новый Agelto deploy key | Удалить только новую запись Deploy key в GitHub и файлы Agelto key; чужие SSH-файлы не менять. |
| Compose startup | `docker compose --project-name agelto_prod --env-file .env.production -f docker-compose.prod.yml down` без `-v`; volumes сохраняются. |
| Новый NGINX site | Убрать только Agelto symlink, выполнить `nginx -t`, reload; затем восстановить/удалить только созданный Agelto-файл. |
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
