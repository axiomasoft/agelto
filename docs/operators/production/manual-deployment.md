---
audience: operator
type: howto
status: living
---

# Ручной production deploy без CI

Это рабочая ветка [основной инструкции](deployment.md): инженер входит на сервер, подтягивает
`main`, собирает локальную пару images и переключает только Agelto. CI, registry token и
публикация images не требуются. Команды, проверки и безопасные развилки собраны в этой
инструкции и связанных runbooks; отдельные файлы деплоя в корне checkout не требуются.
Описание команды не доказывает её исполнения: после каждого шага сохраняйте фактический
результат в журнале конкретной операции вне Git, без секретов.

### Последний зафиксированный результат и история

Проверки сервера `46.8.220.57` от 2026-09-12: HTTPS и `/up` — 200, шесть основных сервисов
Agelto healthy, readiness 7/7, Doctor 27 diagnostics без failures/warnings. Применены 81
миграция и BaseSeeder; вход `admin@agelto.ru` проверен. Реальное переключение на предыдущую
пару images и обратно прошло без изменения identities PostgreSQL/Redis/MinIO; PostgreSQL
backup восстановлен в отдельной rehearsal DB. Это исторический результат, не текущий мониторинг.

Остаются осознанные ограничения: `BACKUP_ENABLED=false` ([backup](backups.md)),
`MAIL_MAILER=log`, внешняя SMTP доставка не проверена из-за TCP 25 timeout ([почта](server-mail.md));
Gemini key подключён, но управляемый proxy pool пуст и реальный LLM request не проверен.
Mermaid SVG rendering не подтверждён, полный browser E2E не объявлен успешным.
Правки документации от 2026-09-13 пересобраны только локально: production image не обновлялся.

Подробная история команд сохранена в Git до удаления временного отчёта из корня:
`git show 9c5449b2bbc54a3797cc118140c4589455310aef:DEPLOYMENT_REPORT.md`.
Используйте её как provenance, не как сценарий повторного запуска исторических команд.

## Назначение Agelto и выбор каталога

Agelto — веб-сервис. Парсинг — его текущая реализованная функциональность, а не отдельный
тип продукта или ограничение будущего веб-интерфейса. HTTP обслуживает приложение;
длительные crawling/parsing/AI задачи выполняются отдельно в Horizon jobs.

В проверенном deployment выбран `/srv/agelto`: это отдельный deployment root рядом с
существующими проектами в `/var/www/dterema` и `/var/www/insiders`. Выбор сделан для удобного
разделения каталогов, **не потому, что Agelto якобы не веб-сервис**. `/srv` сам по себе не
изолирует процессы: изоляцию обеспечивают отдельные Compose project names, сети, volumes,
порты и NGINX virtual host.

`/var/www/agelto` также допустим для новой установки после аудита конфликтов. Ни Docker,
ни NGINX не требуют, чтобы host checkout веб-сервиса находился именно в `/var/www`.
Все команды ниже согласованы с фактическим `/srv/agelto`; это выбранная конфигурация,
а не единственное поддерживаемое расположение. Уже работающий deployment этой документационной
правкой **не переносится**.

### Где находятся файлы: local, server и container

| Окружение | Путь | Назначение |
|---|---|---|
| Local workstation | `/home/vostrikov/projects/agelto/agelto` | Локальный checkout инженера; не каталог на VPS |
| Ubuntu VPS | `/srv/agelto` | Deployment root: repository, bin, releases, backups, operations, mail |
| Ubuntu VPS | `/srv/agelto/repository` | Корень Git-проекта: artisan, Makefile, production Compose и ignored env |
| PHP container | `/var/www/html` | Корень приложения внутри image; не host `/var/www` |
| PHP container | `/var/www/html/public` | Публичные assets и HTTP entrypoint, не весь Git checkout |

NGINX на host проксирует HTTP к `127.0.0.1:8082`, а не публикует host repository целиком.
Секреты, `.env.production` и `.env.backup` не должны быть доступны через document root.
Пути временных credential files, переданные как **local** file links, нельзя читать через
SSH на VPS: `/tmp` локального компьютера и `/tmp` сервера — разные файловые системы.
Отчёт хранит только non-secret сведения; временный local password file не является постоянным
серверным хранилищем паролей.

На VPS после SSH корень проекта проверяется без вывода secrets:

```bash
cd /srv/agelto/repository
pwd
git rev-parse --show-toplevel
git status --short
```

Ожидается `/srv/agelto/repository` в первых двух проверках; `git status` показывает фактические
изменения checkout. Git/сборку/Compose выполняйте от deploy; root нужен для host operations.
Не выводите содержимое env или secret files для проверки расположения.

### Если для новой установки выбран `/var/www/agelto`

Сохраните ту же структуру: deployment root `/var/www/agelto`, checkout
`/var/www/agelto/repository`. До выполнения runbook согласуйте **все** абсолютные host paths:
Git clone/profile, wrappers в bin, mounts ignored Compose override, release/public snapshots,
secret paths, backup scripts/flag/release lock и systemd WorkingDirectory/ExecStart/ReadWritePaths.
SMTP directory и его wrapper также должны указывать на новый deployment root.
Container paths `/var/www/html` не заменяются host paths.

Не применяйте только замену `git clone` target и не выполняйте глобальный search/replace
в `/etc` или чужих проектах. При новой установке проверьте итоговый Compose через
`config --quiet`, собственные units через `systemd-analyze verify` и выполните runtime проверки
этого runbook. Альтернативный host path пока не проверялся на целевом VPS.
Перенос уже работающего `/srv/agelto` — отдельное согласованное изменение с backup,
сохранением project/volume identities и rollback; простой `mv` не является инструкцией переноса.

## 1. Предусловия и аудит до изменений

Нужны разрешённый root-вход, FQDN, доступ к GitHub, Git, Bash, Make, Python 3, OpenSSL, Docker
Engine с Compose/BuildKit, NGINX и Certbot. Требуются свободное место для двух тяжёлых images,
build cache и backup, а также память для сборки без ущерба соседям. Шаги личного SSH-ключа и
SSH policy выполняет владелец отдельно. Не обходите version guard `host:apply`.

Первый вход — root; пароль только интерактивно. Отключите history в этой сессии, не включайте
`set -x`. Выполните read-only аудит:

```bash
set +o history
cat /etc/os-release
df -h /
free -h
swapon --show
docker version
docker compose version
docker ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
docker compose ls -a
docker network ls
docker volume ls
ss -lntup
nginx -t
find /etc/nginx/sites-enabled /etc/nginx/sites-available -maxdepth 1 -mindepth 1 -ls
certbot certificates
ufw status verbose
iptables -S DOCKER-USER
getent passwd deploy
id deploy
sudo -l -U deploy
git --version
curl -fsSI --max-time 15 https://github.com/
find /srv /opt /var/www /home/deploy -maxdepth 3 -type d
```

У существующих containers проверьте labels `com.docker.compose.project.working_dir` и
`com.docker.compose.project.config_files`: это показывает способ запуска соседнего проекта.
Не печатайте `docker inspect` целиком: environment может содержать чужие secrets.

Выберите незанятые project name, каталог и loopback ports. Проверяйте их повторно перед start.
Не используйте `container_name`, external **соседние** shared networks/volumes или фиксированную subnet.
Отдельный [исходящий SMTP](server-mail.md) может подключаться только к уже собственной
проверенной Agelto network; его queue/DKIM volumes и Compose project остаются отдельными.
Зафиксируйте исходные HTTP statuses соседей. Не переустанавливайте готовые инструменты,
не меняйте глобальный Docker daemon, firewall, SSH policy, default site и чужие сертификаты.
Нет swap — capacity-риск, а не разрешение менять host глобально.

### Только пустой host: установка отсутствующих инструментов

После аудита, **только если Docker/соседних приложений ещё нет**, root может установить
инструменты вручную без personal deploy SSH key и без `host:apply`. Docker официально
поддерживает Ubuntu 24.04 и 26.04; команды apt repository сверены с
[Docker Ubuntu installation](https://docs.docker.com/engine/install/ubuntu/).
На shared host этот блок пропускается. При конфликтующих пакетах остановитесь: автоматически
удалять/заменять существующий container runtime нельзя.

```bash
apt-get update
apt-get install -y ca-certificates curl git make python3 openssl nginx certbot python3-certbot-nginx
install -d -m 0755 /etc/apt/keyrings
test ! -e /etc/apt/keyrings/docker.asc
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod 0644 /etc/apt/keyrings/docker.asc
test ! -e /etc/apt/sources.list.d/docker.sources
agelto_docker_codename=$(. /etc/os-release; printf '%s' "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
agelto_docker_arch=$(dpkg --print-architecture)
printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' \
  "$agelto_docker_codename" "$agelto_docker_arch" > /etc/apt/sources.list.d/docker.sources
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker nginx
docker version
docker compose version
nginx -t
```

Запишите установленные versions. Installation на целевом сервере не повторялась: там всё уже
было готово. Firewall/SSH hardening требует отдельного разрешения и не является побочным
эффектом deploy. Проверяйте внешний ingress и закрытые loopback data ports независимо от UFW.

Проверенная матрица размещения на `46.8.220.57`:

| Ресурс | Agelto |
|---|---|
| Checkout / Compose project | `/srv/agelto/repository` / `agelto_prod` |
| App / PostgreSQL / Redis | `127.0.0.1:8082` / `127.0.0.1:5434` / `127.0.0.1:6382` |
| Silo (S3, совместим с MinIO) API / console | `127.0.0.1:9002` / `127.0.0.1:9003` |
| NGINX site / FQDN | `/etc/nginx/sites-available/agelto.ru.conf` / `agelto.ru` |

## 2. Deploy user и отдельный read-only GitHub key

Если deploy уже существует и входит в `docker`, ничего не меняйте. На новом host создайте
его home и добавьте только в `docker`; это root-equivalent право, а не ограниченный sudo.
Root выполняет host operations; deploy — Git/build/Compose. Широкий sudo не нужен.
Для отсутствующего пользователя/доступа Docker (root):

```bash
id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
id -nG deploy
# Только если группы docker нет в выводе:
usermod -aG docker deploy
install -d -o deploy -g deploy -m 0700 /home/deploy/.ssh
sudo -u deploy docker ps
```

Создавайте `/srv/agelto` только если он отсутствует и не является symlink/чужим приложением:

```bash
install -d -o deploy -g deploy -m 0755 /srv/agelto
```

От имени deploy создайте отдельный key, **не перезаписывая существующий**:

```bash
sudo -u deploy ssh-keygen -t ed25519 -N '' \
  -f /home/deploy/.ssh/agelto_bootstrap_ed25519 -C 'agelto read-only deploy key'
sudo -u deploy ssh-keygen -lf /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
sudo -u deploy cat /home/deploy/.ssh/agelto_bootstrap_ed25519.pub
```

Покажите владельцу только `.pub`, затем остановитесь до подтверждения:
`GitHub → axiomasoft/agelto → Settings → Deploy keys → Add deploy key → вставить публичный ключ
→ оставить read-only доступ`. Private key остаётся на сервере 0600, `.ssh` 0700.
Это GitHub key, **не личный ключ входа пользователя deploy**.

Проверьте GitHub host fingerprint по [официальной странице GitHub](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).
Используйте уже проверенный `known_hosts`; при его отсутствии создайте отдельный Agelto файл
с предварительно сверенным host key. Не доверяйте одному `ssh-keyscan` и не отключайте host checking.

Fresh home без `known_hosts`: получите только публичный host key в новый candidate и проверьте
его fingerprint по официальной странице, прежде чем устанавливать файл:

```bash
test ! -e /srv/agelto/github.known_hosts.candidate
sudo -u deploy ssh-keyscan -t ed25519 github.com > /srv/agelto/github.known_hosts.candidate
ssh-keygen -lf /srv/agelto/github.known_hosts.candidate
# Только после точного совпадения fingerprint с официальным GitHub fingerprint:
test ! -e /home/deploy/.ssh/known_hosts
test ! -L /home/deploy/.ssh/known_hosts
install -o deploy -g deploy -m 0600 /srv/agelto/github.known_hosts.candidate /home/deploy/.ssh/known_hosts
```

При уже существующем `known_hosts` не заменяйте его этим блоком. Используйте проверенную
existing GitHub entry либо отдельный Agelto known-hosts файл и добавьте
`-o UserKnownHostsFile=/home/deploy/.ssh/agelto_known_hosts` во **все** следующие SSH commands,
включая repository-local `core.sshCommand`. На проверенном сервере существующая entry подошла.

```bash
sudo -u deploy env GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes' \
  git ls-remote git@github.com:axiomasoft/agelto.git refs/heads/main
# Только после успешного доступа и проверки, что target checkout отсутствует:
sudo -u deploy env GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes' \
  git clone --branch main --single-branch git@github.com:axiomasoft/agelto.git /srv/agelto/repository
sudo -u deploy git -C /srv/agelto/repository config --local core.sshCommand \
  'ssh -i /home/deploy/.ssh/agelto_bootstrap_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes'
```

Не вызывайте `repositories:prepare` на shared host: helper перезаписывает глобальные deploy
SSH config/known_hosts. В checkout запустите от deploy `bash docker/scripts/vendor-sync.sh`.
Он сверяет exact SHA из `docker/private-vendors.lock.json` и отказывается перезаписывать dirty
vendor. При публичном HTTPS vendor второй key не нужен; при private access нужен отдельный
read-only key именно того repository, без личного token и без write access.

## 3. Production env и secrets

От deploy в checkout создайте отсутствующий `.env.production` из `.env.example`, с mode 0600.
Не копируйте поверх существующего env. Заполните non-secret значения редактором:

```bash
cd /srv/agelto/repository
test ! -e .env.production && install -m 0600 .env.example .env.production
```

| Ключи | Требуемое значение/формат |
|---|---|
| `COMPOSE_PROJECT_NAME` | Отдельное имя, здесь `agelto_prod` |
| `IMAGE_TAG` | Unique `manual-<full SHA>-<UTC timestamp>`, не latest; заполнить после сборки |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_NAME` | `production`, `false`, `https://agelto.ru`, `Agelto` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_HOST`, `DB_PORT` | `agelto`, `agelto`, `postgres`, `5432` |
| `APP_BIND_ADDRESS`, `APP_PORT`, `PGSQL_PORT`, `REDIS_PORT` | `127.0.0.1`, `8082`, `5434`, `6382` — host mappings |
| `MINIO_PORT`, `MINIO_CONSOLE_PORT`, `MINIO_ROOT_USER` | `9002`, `9003`, `agelto` |
| `MINIO_BUCKET`, `MINIO_ESTIMATES_BUCKET` | `agelto`, `agelto-estimates` |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `redis` |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE` | `agelto.ru`, `true`, `true`, `lax` |
| `ADMIN_EMAIL` | Адрес initial SuperAdmin, подтверждённый владельцем |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS` | `log` до SMTP; sender соответствующего домена |
| `LOG_LEVEL`, `HORIZON_STOP_GRACE_PERIOD` | `warning`, `4200s` |

Новые локальные credentials можно безопасно генерировать штатным `make secrets:init`, если
владелец разрешил генерацию; это не подстановка выдуманных credentials внешнего сервиса.
Повторный запуск сохраняет существующие bytes. Контракт: `app_key`, `db_password`,
`redis_password`, derived `redis_acl`, `minio_root_password`, `admin_initial_password` в
`local/secrets/` (0700), файлы deploy:deploy 0600. APP_KEY имеет формат `base64:<32 random bytes>`;
остальные generated passwords — случайные строки. Не выводите содержимое.

Для SMTP нужны host/port/scheme/username/password; для LLM — настоящий provider API key
(`GEMINI_API_KEY`); для alerts/observability — Telegram/Sentry credentials при включении.
Получайте их у владельца, не выдумывайте. Пустой LLM key и `MAIL_MAILER=log` не означают
готовность платного AI или доставку email. Не передавайте external secrets в CLI/Git/отчёт:
нужны отдельные protected files и явно настроенный поддерживаемый runtime secret mapping.

### Внешние secrets: Gemini и SMTP

Gemini key можно перенести из локального `.env` по явному разрешению владельца. Прочитайте
его через штатный PHP/Dotenv **в памяти**, сохраните quoted/escaped строку `GEMINI_API_KEY`
в ignored `local/secrets/external.env` на сервере (deploy:deploy 0600, parent 0700).
Передавайте bytes только через SSH stdin, не через CLI, shell history, stdout, Git или build args.
Не копируйте весь development env: DB identities, ports и SMTP defaults там не production.
Проверьте отсутствие target/symlink до первой записи; обновление требует protected snapshot.

Prism не поддерживает `GEMINI_API_KEY_FILE` сам по себе. Простое добавление такого environment
не подключит ключ. Проверенный host-only adapter `/srv/agelto/bin/external-env` (0755):

```sh
#!/bin/sh
set -eu
cd /var/www/html
test ! -L .env
test -s /run/secrets/external_env
install -m 0600 -o www-data -g www-data /run/secrets/external_env .env
export AGELTO_ENTRYPOINT_PRELOAD=1
if [ "$#" -eq 0 ]; then
    set -- /init
fi
exec /etc/entrypoint.d/10-secrets.sh "$@"
```

Сохраните код редактором в новый candidate file, `sh -n`, затем `install -m 0755` в bin.
Adapter запускается как root до штатного entrypoint, создаёт только protected runtime `.env`
www-data:0600, а исходный secret остаётся readonly mount. Он сохраняет штатный preload
APP/DB/Redis/S3 secrets и делегирует обычный CMD: `/init`, Horizon, scheduler или Doctor.
На каждом recreate копируется актуальный external env; значения не попадают в image labels
или Compose environment. Для rotation нужно recreate всех четырёх PHP services, не только app.

В production override **для app, horizon, scheduler и doctor** добавьте:

```yaml
secrets:
  external_env:
    file: ./local/secrets/external.env
services:
  app:
    command: ["/init"]
    entrypoint: &external_entrypoint ["/usr/local/bin/agelto-external-env"]
    secrets:
      - external_env
    volumes:
      - /srv/agelto/bin/external-env:/usr/local/bin/agelto-external-env:ro
  horizon:
    stop_signal: SIGTERM
    entrypoint: *external_entrypoint
    secrets:
      - external_env
    volumes:
      - /srv/agelto/bin/external-env:/usr/local/bin/agelto-external-env:ro
  scheduler:
    entrypoint: *external_entrypoint
    secrets:
      - external_env
    volumes:
      - /srv/agelto/bin/external-env:/usr/local/bin/agelto-external-env:ro
  doctor:
    entrypoint: *external_entrypoint
    secrets:
      - external_env
    volumes:
      - /srv/agelto/bin/external-env:/usr/local/bin/agelto-external-env:ro
```

Это **добавления** к host override ниже, не замена остальных mounts/environment/healthchecks.
`command: ["/init"]` обязателен для app: Compose при non-null entrypoint игнорирует image CMD.
Без этого CLI smoke проходит, но app выводит banner, завершается и уходит в restart loop.
Проверяйте rendered app command, а не только image CMD или `compose run`.
Объедините sections редактором, сохраните protected backup, `compose config --quiet`, затем
проверьте одноразовым `compose run --rm --no-deps -T app` только boolean наличия
`config('prism.providers.gemini.api_key')` после Laravel bootstrap. Не печатайте сам config.
Потом примените adapter через обычный scoped release switch.

**Gemini требует управляемый proxy pool.** `OutboundProxy` → `PoolOutboundProxy` →
`ProxyResolver` для scope `llm`; active proxy должен иметь разрешённую страну (не RU/unknown),
scope и рабочее состояние. Пустой pool даёт `NoProxyAvailableException` до HTTP request.
Ключ сам по себе не доказывает доступ к Google API. Пустой proxy pool не блокирует startup,
HTTPS или readiness приложения, но LLM jobs до заполнения pool fail closed.
Прокси вводят через защищённую админку
или отдельный согласованный перенос; не берите credentials соседнего проекта и не добавляйте
direct/env fallback. Проверяйте API через этот же canonical transport с sanitized status,
без полного exception/response/request dump. Реальный generation smoke фиксируется отдельно.

Для отправки с собственного VPS используйте [isolated SMTP runbook](server-mail.md):
`MAIL_HOST=agelto-smtp`, port 587, auth не нужна только внутри trusted Agelto network.
External SMTP требует реальные `MAIL_USERNAME`/`MAIL_PASSWORD` в protected external env.
Поменяйте `MAIL_MAILER` на `smtp` только после working outbound TCP 25/DNS и проверки
доставки на owner recipient. Host values в `environment` перекрывают `.env`: оставшийся
`MAIL_MAILER=log` продолжит писать письма в log. Отсутствие SMTP не блокирует приложение.

**Compose не передаёт весь env автоматически.** В базовом production файле есть explicit
`environment`, но нет `env_file`. Host `REDIS_PORT=6382` не должен заменить внутренний 6379.
Создайте ignored `docker-compose.override.yml` deploy:deploy 0600 для необходимых additions:

```yaml
services:
  app:
    volumes:
      - /srv/agelto/nginx-app.conf:/etc/nginx/conf.d/default.conf:ro
    environment: &host_env
      APP_NAME: ${APP_NAME:-Agelto}
      PHP_OPCACHE_ENABLE: "1"
      SESSION_DOMAIN: ${SESSION_DOMAIN:-null}
      SESSION_SECURE_COOKIE: ${SESSION_SECURE_COOKIE:-true}
      SESSION_HTTP_ONLY: ${SESSION_HTTP_ONLY:-true}
      SESSION_SAME_SITE: ${SESSION_SAME_SITE:-lax}
      MAIL_MAILER: ${MAIL_MAILER:-log}
      MAIL_HOST: ${MAIL_HOST:-agelto-smtp}
      MAIL_PORT: ${MAIL_PORT:-587}
      MAIL_SCHEME: ${MAIL_SCHEME:-null}
      MAIL_EHLO_DOMAIN: ${MAIL_EHLO_DOMAIN:-agelto.ru}
      MAIL_FROM_ADDRESS: ${MAIL_FROM_ADDRESS}
      MAIL_FROM_NAME: ${APP_NAME:-Agelto}
  horizon:
    stop_signal: SIGTERM
    environment: *host_env
  scheduler:
    environment: *host_env
  doctor:
    volumes:
      - /srv/agelto/releases/${IMAGE_TAG}/public:/var/www/html/public:ro
    environment: *host_env
  redis:
    healthcheck:
      test: ["CMD-SHELL", "test \"$$(redis-cli --user health --pass '' ping)\" = PONG"]
```

Redis health user — отдельный `nopass` user с правом только PING. `--user health` без
`--pass ''` не посылает AUTH: NOAUTH всё равно даёт CLI exit 0. Нужен строгий PONG comparison.

Для production HTTPS-only ingress создайте `/srv/agelto/nginx-app.conf` из
`docker/nginx/default.conf`, добавив после **каждого** `include fastcgi_params;`:
`fastcgi_param HTTPS on;` и `fastcgi_param SERVER_PORT 443;`. Это сообщает PHP о TLS offload
и устраняет HTTP assets/Livewire URLs. Применимо только когда host NGINX перенаправляет HTTP
на HTTPS, а app port закрыт на loopback; для mixed HTTP/HTTPS ingress нужна другая схема.
Проверьте этот конфиг с generated main config работающего app до recreate, затем `nginx -t`
в app после start. Bare image ещё не содержит generated `/etc/nginx/nginx.conf`: standalone
`docker run --entrypoint nginx ... -t` без него не является корректным syntax check.

Doctor image не содержит web-assets. До его проверки экспортируйте `public` из **того же
app tag** в новый `/srv/agelto/releases/<tag>/public`, deploy-readable; doctor получает только
read-only mount. Не берите public из другого release и не отключайте assets diagnostic:

```bash
mkdir -p -m 0755 "/srv/agelto/releases/$candidate_tag"
docker run --rm --pull=never --entrypoint tar "agelto/app:$candidate_tag" \
  -C /var/www/html -cf - public </dev/null | \
  tar --no-same-owner --same-permissions -xf - -C "/srv/agelto/releases/$candidate_tag"
```

Выполняйте экспорт после успешной сборки; directory должен быть новым. Сохраняйте предыдущие
public snapshots для rollback. Эти files обслуживает только Doctor, app использует assets image.

Root заранее создаёт parent `install -d -o deploy -g deploy -m 0755 /srv/agelto/releases`.
На existing Agelto host проверьте `stat`/отсутствие symlink и ownership этого parent: первый
root-export мог оставить его root-owned и повторный deploy получил бы `mkdir: Permission denied`.
Меняйте owner только проверенного Agelto parent, не recursive contents соседних приложений.
Для Doctor public files/directories должны быть www-data-readable/traversable; при private
operation umask 077 после export сохраняйте safe asset modes из tar (directories 0755/files 0644),
не делайте writable snapshot и не меняйте secret-file permissions.

Чтобы не смешивать проекты/файлы, создайте `/srv/agelto/bin/compose` deploy:deploy 0755:

```bash
#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/docker compose --project-directory /srv/agelto/repository \
  --project-name agelto_prod --env-file /srv/agelto/repository/.env.production \
  -f /srv/agelto/repository/docker-compose.prod.yml \
  -f /srv/agelto/repository/docker-compose.override.yml "$@"
```

Проверьте `bash -n /srv/agelto/bin/compose` и
`IMAGE_TAG=preflight-plan /srv/agelto/bin/compose config --quiet` (только render, не start).
Временный tag здесь не требует images; перед start env обязан содержать реально собранный tag.
Все следующие команды — от deploy в checkout. `make ... COMPOSE_PROD=/srv/agelto/bin/compose`
применяет override; просто переменная `COMPOSE_FILE` не заменяет явный `-f` в Make.

## 4. Собрать локальную immutable пару

```bash
git pull --ff-only origin main
test -z "$(git status --porcelain)"
bash docker/scripts/vendor-sync.sh
candidate_sha=$(git rev-parse HEAD)
candidate_tag="manual-$candidate_sha-$(date -u +%Y%m%dT%H%M%SZ)"
! docker image inspect "agelto/app:$candidate_tag" >/dev/null 2>&1
! docker image inspect "agelto/worker-prepare:$candidate_tag" >/dev/null 2>&1
! docker image inspect "agelto/doctor:$candidate_tag" >/dev/null 2>&1
```

`make image:build` до Docker build вызывает Composer platform dry-run на host. Если там нет
Composer **с полной требуемой PHP platform**, сначала соберите app-base по tracked Dockerfile:

```bash
docker build --target app-base --tag "agelto/composer-preflight:$candidate_sha" \
  --file docker/php/Dockerfile .
```

Создайте `/srv/agelto/bin/composer` 0755 (подставьте фактический preflight tag):

```bash
#!/usr/bin/env bash
set -euo pipefail
workdir="$(pwd -P)"
exec /usr/bin/docker run --rm --pull=never --entrypoint composer \
  --user "$(id -u):$(id -g)" --env HOME=/tmp --env COMPOSER_CACHE_DIR=/tmp/composer-cache \
  --volume "$workdir:$workdir:ro" --workdir "$workdir" \
  "agelto/composer-preflight:REPLACE_WITH_BUILT_SHA" "$@"
```

Generic Composer container без intl/gd недостаточен. Не используйте `--ignore-platform-reqs`.
Wrapper используется только через command-local PATH, не меняет global/profile PATH и читает
checkout read-only. Выполните:

```bash
PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin BUILDKIT_PROGRESS=plain \
  make image:build IMAGE_TAG="$candidate_tag"
docker image inspect --format '{{.Id}} {{index .Config.Labels "org.opencontainers.image.revision"}} {{index .Config.Labels "io.agelto.vendor-lock-sha"}}' \
  "agelto/app:$candidate_tag" "agelto/worker-prepare:$candidate_tag" "agelto/doctor:$candidate_tag"
```

Ожидается `Image set ready`, exit 0 и одинаковые revision/vendor labels для app, doctor и
worker-prepare. Сохраните IDs всех образов релиза. Контейнер Horizon использует worker-prepare.
Не пересобирайте прежний tag: он нужен для rollback. Build context исключает `.env*`,
`local/secrets`, `.git`, host vendor/node_modules согласно `.dockerignore`.
После успеха внесите candidate tag в `.env.production`, не затрагивая остальные ключи.

Развилки, проверенные на сервере:

- Образы `minio/minio` и `minio/mc` удалены с Docker Hub, а `quay.io/minio` не отдаёт их
  анонимно. Стек использует поддерживаемый S3-совместимый форк Silo: сервис `minio` и стадия
  `mc` в `docker/php/Dockerfile` закреплены на `pgsty/silo:RELEASE.2026-11-12T00-00-00Z` с
  digest. Отдельный pull или ретег не нужен; при ошибке pull проверьте доступ хоста к Docker Hub
  и сверьте digest с Compose. Переход с существующего volume MinIO описан в
  [миграции на Silo](../../dev/runbooks/environment-upgrade.md#minio-silo).
- PECL сообщает missing REST XML: проверьте официальный HTTPS REST/tarball и verbose PECL;
  это может быть CDN TLS timeout, а не отсутствующая версия. Только после сетевого доказательства
  используйте `docker build --add-host pecl.php.net:<проверенный-CDN-IPv4>`.
  Для Make build понадобится command-local Docker wrapper, делегирующий остальные команды
  `/usr/bin/docker`; его содержимое приведено ниже. Не закрепляйте исторический IP навсегда,
  не меняйте global resolver/hosts и не отключайте TLS verification.

Только для подтверждённой PECL/CDN развилки создайте `/srv/agelto/bin/docker`
deploy:deploy 0755, предварительно убедившись, что файл отсутствует и не является symlink:

```bash
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == build && -n "${AGELTO_PECL_BUILD_IP:-}" ]]; then
  [[ "$AGELTO_PECL_BUILD_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 2
  shift
  exec /usr/bin/docker build --add-host "pecl.php.net:$AGELTO_PECL_BUILD_IP" "$@"
fi
exec /usr/bin/docker "$@"
```

Проверьте `bash -n /srv/agelto/bin/docker`. После повторной проверки CDN IPv4 задайте его
только на время build (подставьте реальный проверенный адрес вместо placeholder):

```bash
PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin \
  AGELTO_PECL_BUILD_IP=REPLACE_WITH_VERIFIED_IPV4 BUILDKIT_PROGRESS=plain \
  make image:build IMAGE_TAG="$candidate_tag"
```

Ожидается `Image set ready`, затем проверьте image labels/IDs как выше. Без этой переменной
wrapper делегирует Docker без изменения; rollback build override — убрать command-local
переменную, не менять системный resolver или `/usr/bin/docker`.

## 5. Data services, точная БД и первый запуск

Этот `infra:preflight` выполняется **до первого start**: он требует свободных host ports.
При повторном deploy уже работающего Agelto его собственные ports закономерно заняты;
не останавливайте Agelto или соседей ради этого check. Вместо initial free-port gate сверяйте
`compose ps`, project labels, текущие mappings и `compose config --quiet`; команды повторного
релиза приведены отдельно ниже.

```bash
make infra:preflight MODE=prod-runtime ENV_FILE=.env.production
/srv/agelto/bin/compose config --quiet
COMPOSE_PROGRESS=plain make prod:infra:up COMPOSE_PROD=/srv/agelto/bin/compose
/srv/agelto/bin/compose ps
```

Ожидаются только postgres/redis/minio healthy. Inspect project labels, сеть и mounts должны
показать `agelto_prod`, `agelto_prod_agelto_network` и volumes `agelto_prod_*`, не соседний project.
Сеть выделяется Docker автоматически; её фактическая subnet не фиксируется в инструкции.

```bash
/srv/agelto/bin/compose exec -T postgres psql -U agelto -d agelto -Atc \
  'SELECT current_database(), current_user; SHOW port; SELECT count(*) FROM pg_tables WHERE schemaname = '\''public'\'';'
```

Перед migration покажите владельцу: БД/user `agelto`, host `postgres:5432`, container
`agelto_prod-postgres-1`, volume `agelto_prod_pgsql_data`, отдельная network/project и точная
команда ниже. Получите explicit approval. Для existing DB сначала verified backup, не только
успешный pg_dump. Для первой пустой БД зафиксируйте нулевой table count.

```bash
make prod:migrate COMPOSE_PROD=/srv/agelto/bin/compose OWNER_GATE=approved
```

**Известная развилка pg_partman 5.5.0.** Migration `2026_09_10_100093` проверяет наличие partition
по имени `_YYYY_MM`, но pg_partman по умолчанию создаёт `_pYYYYMMDD`. При overlap нельзя удалять
partition, пропускать migration или править migration ledger. Сохраните dump и проверьте bounds.
В первой пустой БД приведите существующие pg_partman partitions к ожидаемому формату и настройте
`partman.part_config.datetime_string='YYYY_MM'` **только для пяти Agelto parents**.
Transactional SQL и проверки database/bounds/target-name приведены ниже.
После нормализации повторите тот же `prod:migrate`: он продолжит pending migrations без
повторного создания предыдущих tables.
Для непустой/нестандартной DB не переносите SQL без проверки её bounds и backup.

Перед SQL сохраните archive от deploy без terminal stdin, в новом protected path:

```bash
umask 077
partition_backup_dir=$(mktemp -d /srv/agelto/partition-backup.XXXXXX)
docker exec agelto_prod-postgres-1 pg_dump -U agelto -d agelto -Fc </dev/null \
  > "$partition_backup_dir/agelto.dump"
test -s "$partition_backup_dir/agelto.dump"
docker exec -i agelto_prod-postgres-1 pg_restore --list \
  < "$partition_backup_dir/agelto.dump" > "$partition_backup_dir/archive.list"
```

Каждая команда должна завершиться с exit 0; остановитесь при ошибке и сохраните неполный
archive для диагностики. `pg_restore --list` доказывает читаемость, не полноценный restore:
для непустой DB требуется отдельный verified restore по [runbook](rollback-restore.md).
Fresh-install SQL применяйте только после проверки exact DB/parents/bounds и отсутствия rows
во всех пяти parents; без этих условий остановитесь, не переносите SQL на другую БД.

SQL для описанной fresh-install развилки (от deploy; контейнер/DB сначала inspect/psql):

```bash
/srv/agelto/bin/compose exec -T postgres psql -v ON_ERROR_STOP=1 -U agelto -d agelto <<'SQL'
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

SQL не удаляет partitions и не меняет их bounds. Неожиданный bound/существующий target name
обрывает transaction. Вернуть naming можно обратным `ALTER TABLE ... RENAME` для записанных
names и прежним datetime_string, но это вернёт несовместимость repair migration; обычный
image rollback naming не меняет. После повторной migration проверьте ledger и maintenance.

```bash
make prod:seed-base COMPOSE_PROD=/srv/agelto/bin/compose
/srv/agelto/bin/compose run --rm --no-deps app php artisan partitions:maintain
make prod:admin:create COMPOSE_PROD=/srv/agelto/bin/compose \
  PASSWORD_FILE=local/secrets/admin_initial_password
make prod:app:up COMPOSE_PROD=/srv/agelto/bin/compose
make prod:storage:init COMPOSE_PROD=/srv/agelto/bin/compose
make prod:workers:up COMPOSE_PROD=/srv/agelto/bin/compose
```

Partition maintenance имеет штатную retention policy; это не read-only check. Не запускайте
его до schema setup или на соседней DB. Admin command create-only, password-file mount
read-only. При уже существующем admin проверяйте его роль, не reset password автоматически.
App запускается до buckets; workers — только после buckets. Не запускайте ручной `schedule:run`
для искусственного heartbeat: дождитесь штатного минутного расписания.

## 6. DNS, NGINX и TLS

Root проверяет A/AAAA FQDN, public IP, внешний HTTP/HTTPS ingress и существующие certificate
lineages. Создаётся только новый `/etc/nginx/sites-available/<FQDN>.conf` и его enabled symlink.
Сохраните обратимую копию своего файла **до изменения**, не перезаписывайте чужой site.
Сначала HTTP-only vhost: ACME location с root `/var/lib/letsencrypt`, приложение на выбранном
loopback upstream. TLS template до выпуска certificate использовать нельзя: referenced files
ещё отсутствуют. Для выбранного `agelto.ru` создайте **новый** candidate file редактором,
например `/srv/agelto/agelto.http.candidate.conf`, с таким содержимым:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name agelto.ru;
    client_max_body_size 32m;
    location /.well-known/acme-challenge/ {
        root /var/lib/letsencrypt;
    }
    location / {
        proxy_pass http://127.0.0.1:8082;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

Root, только после проверки отсутствия этого FQDN в чужих sites и отсутствия target paths:

```bash
test ! -e /etc/nginx/sites-available/agelto.ru.conf
test ! -e /etc/nginx/sites-enabled/agelto.ru.conf
test ! -L /etc/nginx/sites-enabled/agelto.ru.conf
install -d -m 0755 /var/lib/letsencrypt
install -m 0644 /srv/agelto/agelto.http.candidate.conf /etc/nginx/sites-available/agelto.ru.conf
ln -s /etc/nginx/sites-available/agelto.ru.conf /etc/nginx/sites-enabled/agelto.ru.conf
nginx -t && systemctl reload nginx
curl --resolve agelto.ru:80:127.0.0.1 --max-time 20 -sS -o /dev/null \
  -w '%{http_code}\n' http://agelto.ru/up
```

При неуспешном `nginx -t` не reload: исправьте **свой** candidate/site и повторите check.
HTTP `/up` должен вернуть 200, не 502. Если DNS ещё pending, страница с HTTPS assets может
не быть полноценным HTTP UI (production app уже HTTPS-only); не выдавайте этот bootstrap
за завершённый публичный deploy. При доступном DNS/ingress выполните:

```bash
certbot certonly --webroot -w /var/lib/letsencrypt -d agelto.ru \
  --non-interactive --agree-tos
```

Команда использует existing Certbot account; для нового account отдельно задайте email владельца.
Публичные 80/443 и DNS обязательны. При неготовом DNS оставьте HTTP-only config и статус TLS
pending, а не fake success. После certificate issuance отрендерите tracked
`docker/host/nginx/agelto.conf`: FQDN, certificate/key paths и `127.0.0.1:8080` → выбранный 8082.
NGINX 1.28 сообщает deprecated `listen ... http2`; допустима современная форма
`listen 443 ssl;`, `listen [::]:443 ssl;`, `http2 on;` в Agelto TLS server block.

Для этого FQDN root рендерит новый candidate из tracked template и сохраняет свой HTTP site:

```bash
test -r /etc/letsencrypt/live/agelto.ru/fullchain.pem
test -r /etc/letsencrypt/live/agelto.ru/privkey.pem
test ! -e /srv/agelto/agelto.tls.candidate.conf
sed -e 's/__AGELTO_FQDN__/agelto.ru/g' \
  -e 's|__AGELTO_TLS_CERTIFICATE__|/etc/letsencrypt/live/agelto.ru/fullchain.pem|g' \
  -e 's|__AGELTO_TLS_PRIVATE_KEY__|/etc/letsencrypt/live/agelto.ru/privkey.pem|g' \
  -e 's|127.0.0.1:8080|127.0.0.1:8082|g' \
  /srv/agelto/repository/docker/host/nginx/agelto.conf > /srv/agelto/agelto.tls.candidate.conf
# Для NGINX >= 1.25.1 modernize listen/http2 в candidate редактором, как описано выше.
nginx_backup="/srv/agelto/agelto.site.before-tls.$(date -u +%Y%m%dT%H%M%SZ).conf"
test ! -e "$nginx_backup"
cp -p /etc/nginx/sites-available/agelto.ru.conf "$nginx_backup"
install -m 0644 /srv/agelto/agelto.tls.candidate.conf /etc/nginx/sites-available/agelto.ru.conf
```

```bash
nginx -t && systemctl reload nginx
curl --max-time 20 -sS -o /dev/null -w '%{http_code}\n' http://agelto.ru/
curl --fail --max-time 20 -sS https://agelto.ru/up >/dev/null
certbot renew --dry-run
```

Ожидаются HTTP redirect, HTTPS /up 200 и успешная renewal rehearsal. Rollback NGINX:
восстановить только собственный backup, `nginx -t`, затем reload. Новый certificate сохраняется;
его удаление и изменение чужих certificate lineages не требуется.

## 7. Runtime проверка и handoff

Первый readiness сразу после recreate может показать scheduler unavailable до штатного
минутного heartbeat. Для автоматизируемой startup-проверки сохраните следующий candidate
script редактором, `bash -n`, затем установите deploy:deploy 0755 как
`/srv/agelto/bin/wait-readiness`. Он не запускает искусственный `schedule:run`:

```bash
#!/usr/bin/env bash
set -euo pipefail
for readiness_attempt in $(seq 1 15); do
    if /srv/agelto/bin/compose exec -T app php artisan app:readiness --format=agent </dev/null; then
        exit 0
    fi
    if (( readiness_attempt < 15 )); then
        sleep 5
    fi
done
echo 'Agelto readiness did not become green in the bounded startup window' >&2
exit 1
```

```bash
/srv/agelto/bin/compose ps
/srv/agelto/bin/wait-readiness
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
/srv/agelto/bin/compose exec -T app php artisan horizon:status
/srv/agelto/bin/compose logs --tail=100 app horizon scheduler
git pull --ff-only origin main
```

Readiness должна показать семь passed checks: DB/cache/storage/release_state/assets/Horizon/
scheduler. Helper ограничивает startup wait 15 attempts с паузами 5 seconds (70 seconds + время
проверок); если он отказался, исследуйте logs, не объявляйте release успешным.
Состояние процесса schedule:work не заменяет heartbeat.
Проверьте effective config только по non-secret ключам; не используйте `config:show database`
или весь environment. Cookies должны иметь Secure/HttpOnly/SameSite, ссылки и form actions —
HTTPS. Проверьте homepage, admin login, asset URLs и protected documentation access.
Авторизованный browser smoke и внешние integrations фиксируются отдельно от HTTP 200.

Обычный `compose exec -T app` работает как root и читает original mounted file secrets.
При диагностике `docker exec --user www-data` process **не наследует** изменённые entrypoint
environment paths: передайте только non-secret `--env APP_KEY_FILE=/run/agelto-secrets/APP_KEY`,
аналогично `DB_PASSWORD_FILE`, `REDIS_PASSWORD_FILE`, `AWS_SECRET_ACCESS_KEY_FILE` с одноимёнными
runtime files. Без них CLI может получить no password supplied, хотя FPM правильно наследует
runtime paths и web работает. Не делайте original secrets world-readable ради диагностики.

## Повторный ручной релиз и rollback

Не меняйте ОС, DNS, ключи и secrets на каждом релизе. Работайте от deploy в checkout;
сохраните previous tag и оба image IDs, non-secret env/override copies и
[verified PostgreSQL backup](rollback-restore.md#ручная-локальная-postgresql-rehearsal).
Возьмите один scoped release lock: parallel deploy и backup недопустимы.

Для новой установки настройте [автоматические бэкапы с age и внешним Restic](backups.md).
По умолчанию `BACKUP_ENABLED=false` в отдельном `.env.backup`. Launcher и watchdog
начинают работу после включения флага, независимо от приложения. Для прежних локальных
копий используйте [восстановление архивов OpenSSL](manual-backups.md).

1. `git pull --ff-only origin main`, verify clean tree/vendor pin, full candidate SHA.
2. Соберите **новый** tag по разделу 4. До успеха env остаётся на previous tag.
3. При schema changes проверьте exact DB, backup и compatibility, получите migration approval.
4. Gracefully drain Agelto workers перед schema/runtime switch; не трогайте соседей.
5. Примените согласованные migrations из candidate tag, затем поменяйте env IMAGE_TAG.
6. Scoped recreate app/Horizon/scheduler с `--no-build --pull never`; повторите readiness,
   Doctor, HTTPS и smoke. Сохраните previous images и volumes.

Для current local-only tag **не** используйте `prod:image:promote`, `prod:deploy:image` или
любой `prod:deploy:<class>`: helpers выполняют registry pull и не учитывают этот override.
Команды повторного релиза (от deploy). Сначала сохраните предыдущий runtime и получите
новую пару по разделу 4; не source-ите env-файл как shell script:

Команды ниже предполагают, что предыдущий релиз уже использует `worker-prepare`. Перед
первым переходом сохраните предыдущие Compose-файлы и IDs образов из действующего релиза.

```bash
cd /srv/agelto/repository
umask 077
exec 9>/srv/agelto/.release.lock
flock -n 9
previous_tag=$(awk -F= '$1 == "IMAGE_TAG" {print $2; exit}' .env.production)
operation_stamp=$(date -u +%Y%m%dT%H%M%SZ)
operation_dir="/srv/agelto/operations/$operation_stamp"
test ! -e "$operation_dir"
mkdir -p "$operation_dir"
cp .env.production "$operation_dir/env.previous"
cp docker-compose.override.yml "$operation_dir/compose.previous"
docker image inspect --format '{{.Id}}' "agelto/app:$previous_tag" \
  "agelto/worker-prepare:$previous_tag" "agelto/doctor:$previous_tag" > "$operation_dir/pair.previous.ids"
printf '%s\n' "$previous_tag" > "$operation_dir/tag.previous"
git pull --ff-only origin main
test -z "$(git status --porcelain)"
bash docker/scripts/vendor-sync.sh
candidate_sha=$(git rev-parse HEAD)
candidate_tag="manual-$candidate_sha-$(date -u +%Y%m%dT%H%M%SZ)"
! docker image inspect "agelto/app:$candidate_tag" >/dev/null 2>&1
! docker image inspect "agelto/worker-prepare:$candidate_tag" >/dev/null 2>&1
! docker image inspect "agelto/doctor:$candidate_tag" >/dev/null 2>&1
PATH=/srv/agelto/bin:/usr/local/bin:/usr/bin:/bin BUILDKIT_PROGRESS=plain \
  make image:build IMAGE_TAG="$candidate_tag"
mkdir -p -m 0755 "/srv/agelto/releases/$candidate_tag"
docker run --rm --pull=never --entrypoint tar "agelto/app:$candidate_tag" \
  -C /var/www/html -cf - public </dev/null | \
  tar --no-same-owner --same-permissions -xf - -C "/srv/agelto/releases/$candidate_tag"
IMAGE_TAG="$candidate_tag" /srv/agelto/bin/compose config --quiet
```

Для build-only PECL override примените только заново проверенный IP по разделу 4.
Сверьте migrations diff от revision label active app до candidate SHA. Если есть schema
changes, сначала backup/compatibility/approval. Не продолжайте неизвестную совместимость.
Для schema-compatible candidate, после backup и подготовки пары:

```bash
/srv/agelto/bin/compose stop horizon scheduler
```

Если согласованы migrations, выполните их **из candidate**, а не предыдущего tag:

```bash
IMAGE_TAG="$candidate_tag" make prod:migrate \
  COMPOSE_PROD=/srv/agelto/bin/compose OWNER_GATE=approved
IMAGE_TAG="$candidate_tag" make prod:seed-base COMPOSE_PROD=/srv/agelto/bin/compose
```

После успеха (при code-only релизе предыдущий блок не требуется):

```bash
sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=$candidate_tag/" .env.production
/srv/agelto/bin/compose up -d --no-deps --no-build --pull never --wait app horizon scheduler
/srv/agelto/bin/wait-readiness
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
curl --fail --max-time 20 -sS https://agelto.ru/up >/dev/null
flock -u 9
```

`stop` затрагивает только Agelto workers и уважает Horizon grace 4200s. Оно не является
разрешением остановить соседний project. Не сокращайте grace ради быстрого deploy.
Проверьте фактический stop signal:

```bash
docker inspect --format '{{.Config.StopSignal}}' agelto_prod-horizon-1
```

Нужен `SIGTERM`. Старый PHP/FPM image наследует `SIGQUIT`, который Horizon не обрабатывает;
одного grace period недостаточно. Production Compose и host override должны явно задавать
`stop_signal: SIGTERM`. Для **уже начатого** зависшего scoped stop после проверки container label
допустим `docker kill --signal=TERM agelto_prod-horizon-1` — это корректный graceful TERM,
не SIGKILL. Дождитесь завершения и recreate с исправленным stop signal; не меняйте соседей.
App HTTP service может оставаться доступен во время worker drain только при schema-compatible
изменении; несовместимый schema change требует отдельного maintenance решения.

Rollback кода: только при известном previous image pair и подтверждённой совместимости
текущей schema. Проверьте сохранённые IDs, верните previous tag в env, затем те же scoped
worker stop/recreate/check команды. Secrets не ротируются и data volumes не удаляются.
Для отката к tag, выпущенному до появления `worker-prepare`, нужен сохранённый Compose
того релиза, в котором Horizon использует `agelto/app`. Совместимость schema и конфигурации
для первого перехода проверьте до переключения.
Точный rollback после подтверждения schema compatibility (подставьте **проверенный**
operation directory, содержащий previous snapshot; не выбирайте его по glob):

```bash
cd /srv/agelto/repository
operation_dir=/srv/agelto/operations/REPLACE_WITH_VERIFIED_UTC_STAMP
previous_tag=$(<"$operation_dir/tag.previous")
test "$previous_tag" != latest
cmp "$operation_dir/pair.previous.ids" <(docker image inspect --format '{{.Id}}' \
  "agelto/app:$previous_tag" "agelto/worker-prepare:$previous_tag" "agelto/doctor:$previous_tag")
test -d "/srv/agelto/releases/$previous_tag/public"
exec 9>/srv/agelto/.release.lock
flock -n 9
/srv/agelto/bin/compose stop horizon scheduler
install -m 0600 "$operation_dir/env.previous" .env.production
/srv/agelto/bin/compose config --quiet
/srv/agelto/bin/compose up -d --no-deps --no-build --pull never --wait app horizon scheduler
/srv/agelto/bin/wait-readiness
make prod:doctor COMPOSE_PROD=/srv/agelto/bin/compose
curl --fail --max-time 20 -sS https://agelto.ru/up >/dev/null
flock -u 9
```

Override/host NGINX backup не восстанавливается автоматически: security/config changes
проверяются отдельно. Не используйте `prod:infra:up` для image rollback.
Если schema несовместима, image rollback запрещён: нужен forward fix или отдельный approved
restore из verified manifest по [restore runbook](rollback-restore.md), не `migrate:rollback`.
Первый deploy не имеет previous successful release: rollback switch можно обкатать только
после появления второй проверенной пары. Не называйте dry-run успешным runtime rollback.
