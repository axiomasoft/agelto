# План деплоя Agelto на продакшен VDS и переноса готовой базы

Этот документ фиксирует полную схему развёртывания Agelto на чистом VDS-сервере (`Ubuntu 24.04`), настройку SSH и GitHub Deploy Keys, перенос предварительно собранной и вычищенной локальной базы данных за 2025–2026 годы и запуск основного боевого контура отслеживания актуальных закупок.

---

## 1. Подготовка доступов и входные данные

### 1.1. Две пары SSH-ключей
1. **SSH-ключ для доступа агента на VDS (`root`):**
   - Либо агент генерирует локально ключевую пару Ed25519 и передаёт публичный ключ для добавления в `/root/.ssh/authorized_keys` на новой VDS;
   - Либо предоставляется временный доступ по IP/паролю `root`, после чего агент сам прописывает ключ и закрывает парольный вход.
2. **Два отдельных GitHub Deploy Keys (генерируются на самой VDS):**
   - Скрипт [`docker/scripts/deploy-repositories.sh`](file:///home/vostrikov/projects/agelto/agelto/docker/scripts/deploy-repositories.sh) создаёт системного пользователя `deploy` и генерирует на VDS два изолированных ключа Ed25519:
     1. `/home/deploy/.ssh/agelto_deploy_ed25519.pub` — для основного репозитория **`axiomasoft/agelto`**;
     2. `/home/deploy/.ssh/azguard_deploy_ed25519.pub` — для приватного репозитория пакетов **`axiomasoft/azguard-private`**.
   - Агент выводит оба `.pub`-ключа в чат, владелец добавляет их в GitHub (**Settings → Deploy keys**, Read-only), после чего сервер выкачивает код и приватные пакеты по SSH.

### 1.2. Что нужно сообщить перед стартом деплоя
- **IP-адрес VDS и SSH-порт** (по умолчанию `22`).
- **Основной домен (`APP_URL`)** для привязки в Nginx и выпуска сертификата Let's Encrypt.
- **Email администратора (`ADMIN_EMAIL`)** для Let's Encrypt и первой учётной записи в админ-панели.
- **Реквизиты боевого RU-прокси (`PLAYWRIGHT_MCP_PROXY_SERVER`)** в формате `http://user:pass@host:port`.
- **Ключ API LLM-провайдера** (при включении боевого разбора смет).

---

## 2. Пошаговое развёртывание окружения на чистой VDS

Все операции опираются на штатные цели [`docker/Makefile`](file:///home/vostrikov/projects/agelto/agelto/docker/Makefile) и [`docker-compose.prod.yml`](file:///home/vostrikov/projects/agelto/agelto/docker-compose.prod.yml):

### Шаг 1. Провижининг хоста (`Ubuntu 24.04`)
1. Заполнение `/srv/agelto/production-profile` (на основе [`docker/host/production-profile.example`](file:///home/vostrikov/projects/agelto/agelto/docker/host/production-profile.example), права `0600`).
2. Проверка и настройка хоста:
   ```bash
   make -f docker/Makefile host:check PROFILE=/srv/agelto/production-profile
   make -f docker/Makefile host:apply PROFILE=/srv/agelto/production-profile CONFIRM=apply
   make -f docker/Makefile host:preflight PROFILE=/srv/agelto/production-profile
   ```
   Устанавливаются Docker Engine, Docker Compose, Nginx, Certbot, UFW, лимиты `sysctl`/`ulimit`, создаётся пользователь `deploy` и структура каталогов `/srv/agelto`.

### Шаг 2. Генерация GitHub Deploy Keys и выкачка кода
1. Генерация ключей на VDS:
   ```bash
   sudo make -f docker/Makefile repositories:prepare PROFILE=/srv/agelto/production-profile
   ```
2. После добавления обоих `.pub`-ключей в GitHub — синхронизация кода и приватных пакетов `azguard`:
   ```bash
   sudo make -f docker/Makefile repositories:sync PROFILE=/srv/agelto/production-profile
   ```

### Шаг 3. Секреты, сборка прод-образов и запуск инфраструктуры
1. Инициализация файловых секретов в `local/secrets/` (`app_key`, `db_password`, `redis_password`, `minio_app_password`, `minio_root_password`):
   ```bash
   make -f docker/Makefile secrets:init
   ```
2. Подготовка `.env.production` и сборка иммутабельной пары образов (`agelto/app:<tag>` и `agelto/doctor:<tag>`):
   ```bash
   make -f docker/Makefile image:build IMAGE_TAG=<sha-tag>
   ```
3. Запуск инфраструктурных сервисов (`postgres`, `redis`, `redis-cache`, `redis-session`, `minio`) и инициализация бакетов S3/MinIO:
   ```bash
   make -f docker/Makefile prod:infra:up ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   make -f docker/Makefile prod:storage:init ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   ```

---

## 3. Перенос готовой локальной базы данных и файлов MinIO на продакшен

Чтобы продакшен стартовал не с пустой базы, а с уже собранным, выверенным и отшлифованным локально корпусом закупок за 2025–2026 годы:

### Шаг 1. Снятие дампа локально (после завершения локального прогона и тестов)
```bash
# 1. Сжатый дамп PostgreSQL в custom-формате (-Fc):
docker exec agelto_dev-postgres-1 pg_dump -U agelto -d agelto -Fc -Z 6 > /tmp/agelto_preprod_$(date +%Y%m%d).dump

# 2. Экспорт скачанных документов и смет из локального MinIO (если переносим подготовленные файлы):
docker run --rm --network agelto_dev_agelto_network \
  -v /tmp/minio_backup:/backup \
  minio/mc mirror play/agelto /backup/agelto
```

### Шаг 2. Загрузка и восстановление на продакшен VDS
```bash
# 1. Копирование дампа на VDS:
scp /tmp/agelto_preprod_*.dump root@<VDS_IP>:/tmp/agelto_preprod.dump

# 2. Восстановление в контейнер postgres на продакшене (при остановленных воркерах):
docker exec -i agelto_prod-postgres-1 pg_restore -U agelto -d agelto --clean --if-exists --no-owner --no-privileges < /tmp/agelto_preprod.dump

# 3. Донакат миграций (если есть) и проверка базовых сидов и прокси:
make -f docker/Makefile prod:migrate OWNER_GATE=approved ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
make -f docker/Makefile prod:seed-base ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
```

---

## 4. Запуск приложения и основного боевого профиля на продакшене

На продакшене после импорта исторической базы **архивные профили (`архив 2025`, `архив 2026`) выключаются** (`is_active = false`), а единственной постоянно работающей движущей силой становится профиль **отслеживания актуальных и обновлённых закупок (`ЕИС 44-ФЗ — свежий поток` + `Delta` по убыванию `UPDATE_DATE` + `status_refresh`)**:

1. Запуск приложения, воркеров Horizon и 4 демонов Process Core (`relay`, `reconcile`, `feed`, `project`) + `scheduler`:
   ```bash
   make -f docker/Makefile prod:app:up ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   make -f docker/Makefile prod:workers:up ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   ```
2. Проверка готовности (`prod:readiness` и `prod:doctor`):
   ```bash
   make -f docker/Makefile prod:readiness ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   make -f docker/Makefile prod:doctor ENV_FILE=.env.production COMPOSE_PROJECT_NAME=agelto_prod IMAGE_TAG=<sha-tag>
   ```
3. Включение основного профиля отслеживания свежих и обновлённых закупок (`id = 2`, `ЕИС 44-ФЗ — свежий поток`) и перевод архивных профилей в `is_active = false`.
