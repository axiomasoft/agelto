# Подготовка production-сервера (Debian/Ubuntu)

Инструкция по **хосту**: ОС, пользователь, Docker Engine, файрвол, Nginx, SSL.  
Запуск приложения, Compose, Makefile, volumes — в [docker-prod.md](./docker-prod.md).  
Процесс деплоя (git pull, сценарии) — в [deploy.md](./deploy.md).

## Содержание

1. [Обзор](#1-обзор)
2. [Базовая настройка ОС](#2-базовая-настройка-ос)
3. [Пользователь deploy и группа www-data](#3-пользователь-deploy-и-группа-www-data)
4. [Docker Engine](#4-docker-engine)
5. [Каталог проекта и права](#5-каталог-проекта-и-права)
6. [Файрвол](#6-файрвол)
7. [Nginx (reverse proxy)](#7-nginx-reverse-proxy)
8. [SSL (Let's Encrypt)](#8-ssl-lets-encrypt)
9. [Проверка готовности](#9-проверка-готовности)
10. [Безопасность и обслуживание](#10-безопасность-и-обслуживание)
11. [Доступ к PostgreSQL с рабочей станции](#11-доступ-к-postgresql-с-рабочей-станции)

---

## 1. Обзор

| Слой | Документ |
|------|----------|
| Сервер (эта инструкция) | Пакеты, `deploy`, Docker, UFW, Nginx, Certbot |
| Docker Compose production | [docker-prod.md](./docker-prod.md) — `make prod:*`, volumes, Docker Secrets |
| Деплой | [deploy.md](./deploy.md) — `git pull`, сценарии обновления |

На сервере обычно:

- отдельный UNIX-пользователь **`deploy`** (не root);
- **Docker Engine** + Compose plugin;
- **Nginx** на хосте → прокси на `127.0.0.1:${APP_PORT}` (порт из `.env.docker.production`);
- **Certbot** для HTTPS.

Дальше в примерах:

- `PROJECT_DIR=/var/www/app` — каталог с репозиторием (подставьте свой путь);
- `APP_PORT=8080` — порт на loopback, на который смотрит Nginx (значение из `.env.docker.production`).

---

## 2. Базовая настройка ОС

Выполнять от **root** (или через `sudo`).

```bash
apt update && apt upgrade -y
apt install -y curl wget git ufw ca-certificates gnupg lsb-release
timedatectl set-timezone Europe/Moscow   # при необходимости смените зону
```

Опционально (рекомендуется на публичном VPS):

```bash
apt install -y unattended-upgrades apt-listchanges
dpkg-reconfigure -plow unattended-upgrades
```

Проверка:

```bash
uname -a
free -h
df -h /
```

---

## 3. Пользователь deploy и группа www-data

### Зачем

- **deploy** — SSH, `git`, файлы проекта, `docker` без постоянного root.
- **www-data** — та же группа, что у PHP-FPM в контейнере (обычно GID **33**): позволяет
  безопасно разграничить права на `storage/` без ACL.

### Создание и SSH

```bash
adduser --disabled-password --gecos "" deploy

su - deploy
mkdir -p ~/.ssh && chmod 700 ~/.ssh
nano ~/.ssh/authorized_keys   # вставить публичный ключ
chmod 600 ~/.ssh/authorized_keys
exit
```

Проверка входа: `ssh deploy@<IP_сервера>`.

### Группы docker и www-data

После установки Docker (раздел 4):

```bash
getent group www-data || groupadd -g 33 www-data
usermod -aG docker,www-data deploy
```

Новая сессия SSH или `newgrp www-data`. Ожидаем: `groups deploy` → `deploy docker www-data`.

### Разовая настройка от root

```bash
export PROJECT_DIR=/var/www/app

getent group www-data || groupadd -g 33 www-data
usermod -aG docker,www-data deploy

mkdir -p "$PROJECT_DIR/storage/logs"
chown -R deploy:www-data "$PROJECT_DIR"
find "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" -type d -exec chmod 2775 {} \; 2>/dev/null || true

id deploy
```

---

## 4. Docker Engine

Официальный репозиторий Docker (Ubuntu; для Debian — [документация Docker](https://docs.docker.com/engine/install/debian/)).

```bash
apt remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  > /etc/apt/sources.list.d/docker.list

apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

systemctl enable --now docker
docker --version
docker compose version
```

Пользователь **deploy** должен быть в группе `docker` (раздел 3). Проверка под deploy:

```bash
docker ps
```

---

## 5. Каталог проекта и права

Под пользователем **deploy**:

```bash
export PROJECT_DIR=/var/www/app
cd "$PROJECT_DIR"
git clone <URL_РЕПОЗИТОРИЯ> .

cp .env.example .env
cp .env.docker.production.example .env.docker.production
```

Настройка `.env` и `.env.docker.production` — [docker-prod.md](./docker-prod.md).  
Дальнейший деплой — [deploy.md](./deploy.md).

---

## 6. Файрвол

Минимальный профиль для веб-сервера:

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'    # 80, 443
ufw enable
ufw status verbose
```

**Не открывайте** PostgreSQL (`5432`) и Redis (`6379`) в интернет — доступ только из
Docker-сети или через SSH-туннель (раздел 11).

---

## 7. Nginx (reverse proxy)

Nginx на **хосте** принимает 80/443 и проксирует на приложение в Docker.

```bash
apt install -y nginx
```

Пример конфигурации:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name your-domain.com;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Заголовки `X-Forwarded-For` и `X-Forwarded-Proto` обязательны — иначе Laravel видит
IP Docker-сети и ломаются сессии за HTTPS.

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 8. SSL (Let's Encrypt)

Домен должен указывать на сервер, Nginx отвечать на порту 80.

```bash
apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
sudo certbot renew --dry-run
systemctl status certbot.timer
```

После SSL в `.env` приложения задайте `APP_URL=https://your-domain.com` и выполните деплой
— см. [deploy.md](./deploy.md).

---

## 9. Проверка готовности

Чеклист **хоста**:

| Проверка | Команда / ожидание |
|----------|-------------------|
| SSH под deploy | `ssh deploy@<host>` |
| Группы | `groups` → есть `docker`, `www-data` |
| Docker | `docker ps` без sudo |
| Nginx | `curl -sI http://127.0.0.1` → ответ прокси |
| HTTPS | `curl -sI https://your-domain.com` → 200/302 |

Чеклист **приложения** (в каталоге проекта, под deploy):

```bash
cd "$PROJECT_DIR"
make prod:doctor
curl -fsS "http://127.0.0.1:${APP_PORT}/up" && echo OK
```

---

## 10. Безопасность и обслуживание

- **SSH:** только ключи; `PasswordAuthentication no` в `sshd_config`.
- **fail2ban** (опционально): `apt install fail2ban`, jail для `sshd` и `nginx-http-auth`.
- **Обновления:** `unattended-upgrades` для security-пакетов.
- **Секреты:** `.env` и `.env.docker.production` не в git; права `600` на чувствительные файлы.
  Для APP_KEY / DB_PASSWORD рекомендуется Docker Swarm Secrets (см. [docker-prod.md](./docker-prod.md#docker-secrets)).
- **PostgreSQL и Redis:** порты не открывать в UFW наружу.
- **Резервные копии:** дамп БД по расписанию (cron под deploy).

Пример cron-заготовки:

```bash
# crontab -e (пользователь deploy)
# 0 3 * * * cd /var/www/app && docker compose -f docker-compose.prod.yml exec -T pgsql pg_dump -U "$DB_USERNAME" "$DB_DATABASE" | gzip > /backups/agelto_$(date +%Y%m%d).sql.gz
```

Логи Nginx: `/var/log/nginx/`. Ротация через `logrotate` (пакет `nginx`).

---

## 11. Доступ к PostgreSQL с рабочей станции

**Рекомендуется SSH-туннель** (порт 5432 не открывать в интернет):

1. DBeaver → PostgreSQL → Host `127.0.0.1`, Port `5433` (локальный).
2. Вкладка SSH: хост сервера, пользователь `deploy`, ключ.
3. Remote host `127.0.0.1`, remote port `5432`.

Прямое подключение к `5432` на публичном IP — только с ограничением по IP в облачном firewall;
для типового деплоя не нужно.

---

## Дальнейшие шаги

1. Завершить подготовку хоста по разделам 1–9.
2. Открыть [docker-prod.md](./docker-prod.md) — настройка `.env`, `.env.docker.production`,
   Docker Secrets, `make prod:deploy:image`.
3. После первого успешного деплоя — [deploy.md](./deploy.md) для повседневного процесса обновления.
