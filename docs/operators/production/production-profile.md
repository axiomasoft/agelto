---
audience: operator
type: howto
status: living
---

# Профиль production-хоста

Скопируйте `docker/host/production-profile.example` в `docker/host/production-profile`, заполните
все значения, затем назначьте владельца `root` и режим `0600`. Настоящий профиль игнорируется Git и
никогда не попадает в сборку образов или в вывод команд.

Это намеренно маленький закрытый файл `KEY=value`. В нём указаны файл публичного ключа deploy,
порты, минимальный запас ёмкости, путь к данным Docker и пути к отдельным секретным файлам,
доступным только root. Это не shell-код, и он разбирается без `source` и `eval`.

```bash
make -f docker/Makefile host:check PROFILE=docker/host/production-profile
bash docker/scripts/production-profile-check.sh check --profile "$(pwd)/docker/host/production-profile"
```

Обе проверки отклоняют симлинки, доступ для группы или всех, повторяющиеся и неизвестные ключи,
заглушки, публичные bind-адреса приложения, неполные SHA исходников и неабсолютные пути к
секретным файлам. Не делайте `source` профиля. Команды репозиториев, бэкапа, релиза и репетиций
получают путь к нему и скрывают все значения; в доказательствах остаются только хэши и
идентификаторы.
