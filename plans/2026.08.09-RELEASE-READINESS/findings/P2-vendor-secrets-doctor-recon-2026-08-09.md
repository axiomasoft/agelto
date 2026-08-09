# P2 vendor / secrets / Doctor recon — 2026-08-09

## Scope and method

Проверены текущие carriers Agelto, локальный checkout AzGuard и первичные официальные источники
для Composer path repositories, Docker Compose secrets, Redis ACL, PostgreSQL/MinIO file secrets
и Laravel Doctor. Product files не изменялись. Локальный AzGuard читался только read-only; его
dirty generated-agent files не считаются release evidence.

## Repo-grounded baseline

1. `composer.json` ссылается на `../../packages/azguard/packages/{core,filament,context}` с
   `symlink=false`, хотя application требует только `axioma-studio/azguard-core` и
   `axioma-studio/azguard-filament`. Путь не существует после clean clone Agelto.
   RAG:— (repo-grounded: `composer.json`).
2. Intended private checkout существует локально как
   `https://github.com/axiomasoft/azguard-private.git`, branch `main`, observed HEAD `67ebcde`;
   package manifests находятся в `packages/{core,filament,context}`. Checkout dirty только в
   generated `.agents/**`/Swissknifeman carriers, поэтому observed HEAD — recon fact, не автоматически
   одобренный release pin. RAG:— (repo-grounded: read-only `git`/manifest inspection).
3. `local/vendors/` отсутствует; `.gitignore` исключает `.env*` и `/auth.json`, но не private
   vendor checkout или file-secret root. `.dockerignore` на текущем tree отсутствует и ожидается от
   P1. RAG:— (repo-grounded: filesystem and `.gitignore`).
4. `.env.example` содержит пустые `APP_KEY`, `DB_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, внешние API
   tokens и `ADMIN_EMAIL`; `.env.docker.example` содержит `MINIO_ROOT_PASSWORD=change-me`.
   Production example утверждает Swarm-only secret flow. RAG:— (repo-grounded: env examples).
5. `docker-compose.prod.yml` одновременно объявляет external Swarm secrets, передаёт PostgreSQL
   пароль обычным environment value и задаёт `AWS_SECRET_ACCESS_KEY_FILE`, тогда как
   `10-secrets.sh` читает `MINIO_ROOT_PASSWORD_FILE`, а не `AWS_SECRET_ACCESS_KEY_FILE`.
   Secret contract рассинхронизирован. RAG:— (repo-grounded: prod Compose and entrypoint).
6. `DatabaseSeeder` смешивает обязательные production data с local/testing demo users;
   `AdminUserSeeder`/`admin:reset-password` печатают сгенерированный plaintext password и не дают
   file-based non-interactive input. RAG:— (repo-grounded: seeders/command/tests).
7. Текущий `prod-doctor` проверяет Compose `/up`, наличие `vendor` и container status, но пакет
   `laravel/doctor` не установлен и app-specific readiness diagnostics отсутствуют.
   RAG:— (repo-grounded: `composer.json`, `docker/Makefile`).

## Official-source verification

### Composer path repositories

- Source: https://getcomposer.org/doc/05-repositories.md — checked 2026-08-09.
  Verdict: path repositories принимают relative local directories; `symlink=false` принудительно
  зеркалирует package, а reference по умолчанию использует Git HEAD, если directory — Git checkout.
  Consequence: release flow должен pin/проверять checkout commit до Composer install, а не строиться
  на floating `main`. Confidence: high (official Composer docs).

### Docker and service secrets

- Source: https://docs.docker.com/compose/how-tos/use-secrets/ — checked 2026-08-09.
  Verdict: Compose file secrets работают вне Swarm, монтируются в `/run/secrets/<name>` и выдаются
  каждому service явно; Docker рекомендует их вместо sensitive environment variables. Confidence: high.
- Source: https://docs.docker.com/reference/compose-file/services/#secrets — checked 2026-08-09.
  Verdict: file-source secret является read-only service mount; top-level declaration сама по себе
  не даёт service доступ. Для file source `uid/gid/mode` в Compose могут быть проигнорированы, поэтому
  host permissions и service grant проверяются отдельно. Confidence: high.
- Source: https://hub.docker.com/_/postgres — checked 2026-08-09.
  Verdict: official PostgreSQL image поддерживает `POSTGRES_PASSWORD_FILE` для Docker secret.
  Confidence: high (Docker Official Image docs).
- Source: https://redis.io/docs/latest/operate/oss_and_stack/management/security/acl/ — checked
  2026-08-09. Verdict: Redis принимает external `aclfile`; password может храниться как SHA-256 hash
  через `#<hash>`, не как plaintext process argument. Confidence: high (official Redis docs).
- Source: https://github.com/minio/minio/blob/master/docs/docker/README.md — checked 2026-08-09.
  Verdict: MinIO поддерживает absolute-path `MINIO_ROOT_USER_FILE` и
  `MINIO_ROOT_PASSWORD_FILE`. Confidence: medium (official repository documentation).

### Laravel Doctor

- Sources: https://packagist.org/packages/laravel/doctor and https://github.com/laravel/doctor —
  checked 2026-08-09. Verdict: first-party `laravel/doctor` v0.1.0 supports PHP ^8.3 and Illuminate
  12/13, registers `php artisan doctor`, production/local modes, custom diagnostics and
  `--format=agent|json`; fail/error affect exit code, warnings can fail via `--fail-on=warn`.
  Official install is `composer require laravel/doctor --dev`; `--fix` can mutate deterministic
  local problems and must not run in production validation. Confidence: high (Packagist metadata +
  official repository README).

## Design consequences

1. P2.1 keeps D6 nested checkout but adds a tracked vendor lock manifest with normalized origin,
   full commit SHA and allowed package subpaths; production build refuses floating/mismatched/dirty
   input and excludes nested `.git` metadata.
2. P2.2 replaces the Swarm-only assumption with file-backed Compose secrets in ignored
   `local/secrets/`, create-only generation and per-service grants. Production env files carry no
   secret values; external-provider tokens remain operator-supplied.
3. P2.3 is non-mutating and secret-safe: it checks carriers/permissions/ports/config through quiet
   or redacted output and never runs build/up/migrate/seed.
4. P2.5 separates required idempotent base data from demo data and accepts admin credentials only
   via hidden prompt or password-file path, never a plaintext CLI option.
5. P2.6 keeps Doctor in `require-dev` and uses a same-source one-off diagnostic image/profile. The
   immutable production runtime remains free of dev dependencies; production Doctor never uses
   `--fix` and fails on warnings.

## Проверка (verification status)

- RAG: WebSearch/open ×4 focused fact classes, official primary sources only, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0.4/P0.6 final Doctor/vendor/secret freeze and intended AzGuard
  release SHA are unavailable until those items execute; every P2 item carries terminal/reconcile gates.
- Обходки/[POSSIBLE-DEFECT]: none; current Swarm/env and password-output gaps are explicit P2 scope.
