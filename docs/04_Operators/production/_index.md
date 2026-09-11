---
audience: operator
type: explanation
status: living
---

# Production

Начните с [инструкции для нового Ubuntu-сервера](server-setup.md): в ней один линейный путь от
пустой ОС до NGINX, pinned checkout, `.env.production` и файловых секретов. Затем продолжайте с
[полного руководства по production](deployment.md). Остальные
страницы — справочники для отдельных, нечастых операций, а не обязательный квест по ссылкам.

| Если нужно | Страница |
|---|---|
| Пройти весь путь на новом сервере | [Ubuntu → готовый checkout](server-setup.md) |
| Подготовить сервер или выпустить очередной релиз | [Полное руководство](deployment.md) |
| Посмотреть точный контракт команды, входы и resume | [Справочник команд](commands.md) |
| Подготовить host, checkout и production-профиль | [Host](ubuntu-host.md), [репозиторий](repositories.md), [профиль](production-profile.md) |
| Провести первый запуск или разобрать модель выпусков | [Первый запуск](first-deploy.md), [модель релизов](releases.md) |
| Настроить DNS или TLS глубже | [TLS](network-tls.md) |
| Настроить backup, восстановление или rollback | [Backups](backups.md), [Rollback and restore](rollback-restore.md) |
| Повернуть ключи или секреты | [Deploy keys](credential-rotation.md), [Secrets](secret-rotation.md) |
| Разобрать сбой | [Troubleshooting](troubleshooting.md) |

Source-derived [матрица P12](https://github.com/axiomasoft/agelto/blob/main/plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md)
остаётся точным inventory команд, но не является стартовой страницей для человека.
