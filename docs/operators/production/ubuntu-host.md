---
audience: operator
type: howto
status: living
---

# Подготовка хоста Ubuntu 24.04

Короткий пошаговый runbook для нового хоста Ubuntu 24.04 (`amd64` или `arm64`). Helper выполняет
только повторяемую настройку. Переключение SSH и сетевая политика описаны здесь явно, чтобы их
можно было проверить до того, как они повлияют на доступ.

## 1. Создать и проверить профиль

Положите на сервер публичный ключ deploy и секретные файлы, доступные только root, заполните
игнорируемый git профиль и запустите проверку только на чтение:

```bash
sudo make -f docker/Makefile host:check PROFILE=docker/host/production-profile
```

## 2. Установить Docker и учётную запись deploy

Helper повторяет официальную установку Docker через apt-репозиторий Ubuntu. Он создаёт пользователя
`deploy`, выдаёт ему переданный публичный ключ, ставит Docker Engine и плагины Buildx и Compose,
а также раскладывает отслеживаемую конфигурацию демона. Группа `docker` по правам равна root,
поэтому её получает только учётная запись `deploy`.

```bash
sudo make -f docker/Makefile host:apply PROFILE=docker/host/production-profile CONFIRM=apply
docker version
docker compose version
```

## 3. Безопасно перевести SSH на вход только по ключу

Не закрывайте текущую root-сессию. Во втором терминале докажите новый вход только по ключу под
`deploy`. Только после успеха установите фрагмент конфигурации, проверьте его и перечитайте SSH
(не перезапускайте службу):

```bash
sudo install -m 0644 docker/host/sshd_config.d/90-agelto.conf /etc/ssh/sshd_config.d/90-agelto.conf
sudo sshd -t
sudo systemctl reload ssh
```

Прежде чем закрыть исходную сессию, убедитесь из третьей сессии, что вход под root и по паролю
теперь отклоняется.

## 4. Firewall и пакеты Docker

Порты, опубликованные Docker, могут обходить UFW. Не публикуйте наружу порты приложения и данных:
привязывайте их к `127.0.0.1`, оставляйте публичными только SSH, 80 и 443 и добавляйте фильтр
до правил Docker.

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo iptables -N DOCKER-USER 2>/dev/null || true
sudo iptables -I DOCKER-USER 1 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
sudo iptables -I DOCKER-USER 2 -i "$(ip route show default | awk '{print $5; exit}')" -j DROP
sudo iptables -A DOCKER-USER -j RETURN
```

Сохраните ровно эти правила `DOCKER-USER` утверждённым на хосте механизмом (systemd или
firewall), перезагрузите хост и убедитесь, что правила вернулись. Preflight отклоняет хост без
действующих правил; один только статус UFW доказательством не считается.

## 5. Проверка после перезагрузки

```bash
sudo make -f docker/Makefile host:preflight PROFILE=docker/host/production-profile
ss -lnt
docker info --format '{{.LoggingDriver}}'
systemctl status apt-daily-upgrade.timer docker --no-pager
```

Демон использует драйвер журналов Docker `local` с ротацией. Автоматические обновления
безопасности включены, но решение об автоматической перезагрузке остаётся за оператором:
проверяйте `/var/run/reboot-required`.

## Ограничения доказательств

Сценарий проверен на локальной машине с Ubuntu и Docker, потому что одноразового сервера нет.
Это подтверждает скрипты, запуск Compose и локальную production-топологию, но не доказывает
работу на свежей VM, внешний вход по SSH, публичный firewall и сохранение правил после
перезагрузки. Эти факты о хосте остаются задачей будущей репетиции на сервере, а не скрытым
требованием повторить локальную проверку.
