---
audience: operator
type: howto
status: living
---

# Идентичности репозиториев в production

Корень Git-проекта на проверенном VPS — `/srv/agelto/repository`; `/srv/agelto` — deployment
root, а `/var/www/html` — путь внутри PHP container. Это размещение веб-сервиса Agelto,
не признак standalone parser. `/var/www/agelto/repository` также допустим для **новой**
установки при согласовании всех host paths; выбор и local/server/container distinctions
описаны в [ручном runbook](manual-deployment.md#назначение-agelto-и-выбор-каталога).

На shared host **не запускайте `repositories:prepare` ниже**: helper перезаписывает `.ssh/config`
и `known_hosts` пользователя deploy и может сломать соседние identities. Используйте отдельный
Agelto key и repo-local `core.sshCommand` по [ручному сценарию](manual-deployment.md).
Bootstrap key можно оставить постоянным read-only key, не создавая ещё один без необходимости.
`vendor-sync.sh` читает pinned vendor по HTTPS: публичный repository не требует отдельного key;
private repository требует своего read-only key. Личный token и GitHub write access запрещены.

Выполняйте эти шаги под `root` на хосте Ubuntu, прошедшем проверку
[подготовки хоста](ubuntu-host.md). Игнорируемый production-профиль остаётся с режимом `0600`;
заполните в нём `AGELTO_REPOSITORY`, полный `AGELTO_CANDIDATE_SHA`, каталог checkout и текущее
тело публичного ключа GitHub Ed25519. Не получайте этот ключ хоста через `ssh-keyscan`: сначала
сравните значение в профиле с отпечатком, опубликованным GitHub.

```bash
ssh-keygen -lf <(printf 'github.com ssh-ed25519 %s\n' "$(grep '^GITHUB_ED25519_HOST_KEY=' docker/host/production-profile | cut -d= -f2)") -E sha256
sudo make -f docker/Makefile repositories:prepare PROFILE=docker/host/production-profile
```

Выведенный отпечаток должен быть `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`.
Зарегистрируйте три выведенных публичных ключа по отдельности в соответствующих репозиториях
GitHub как **deploy keys только на чтение**. Никогда не выбирайте права на запись, не
подставляйте личный ключ или токен и не включайте agent forwarding.

После регистрации запустите возобновляемую синхронизацию:

```bash
sudo make -f docker/Makefile repositories:sync PROFILE=docker/host/production-profile
```

Helper отказывается работать при неподтверждённой регистрации, неверном ключе хоста GitHub,
неверном или «украшенном» origin, любых изменённых или неотслеживаемых файлах, неполном SHA и
checkout на ветку. Он забирает точный кандидат Agelto в detached-состоянии на SHA из профиля и
оба коммита Azguard и Proxyma из строгого lock v2 в `local/vendors/{azguard,proxyma}`, после чего
повторно использует проверку vendor. Каждый vendor предоставляет два явных path-пакета Composer,
копируемых с `symlink:false`. Отдельные алиасы `github-agelto`, `github-azguard`,
`github-proxyma` используют отдельные ключи только на чтение. Проверка «только чтение» — это
намеренно неудачный `git push --dry-run`, поэтому репозитории не изменяются.

Сохраните отпечаток публичного ключа, канонические origin и все три полных SHA в обезличенной
записи доказательств развёртывания. Никогда не копируйте туда байты приватного ключа, содержимое
`known_hosts` или профиль.

GitHub документирует, что deploy key привязан к одному репозиторию, а для нескольких
репозиториев нужны отдельные пары ключей и алиасы. Там же опубликован используемый здесь отпечаток Ed25519. См. [Managing deploy
keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys)
и [GitHub SSH fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).
