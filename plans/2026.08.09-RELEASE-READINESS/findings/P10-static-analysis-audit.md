# P10.1 — terminal-tree static-analysis baseline

Дата измерения: 2026-08-13. Baseline product tree:
`469acf640fc95944a3accc378445190dbca2a9ba`. Все repo-grounded counts, hashes и tool outputs
ниже относятся к этому product tree; item-owned plan carriers не меняют product bytes.

## Чистота и воспроизводимость

- До journal start `git status --short --untracked-files=all` был пуст.
- После journal start команда `git status --porcelain=v1 -- .
  ':(exclude)plans/2026.08.09-RELEASE-READINESS/**'` вернула пустой product diff.
- После завершения измерений появился чужой concurrent diff `CLAUDE.md`; он не входил в baseline,
  не относится к product/config scope P10.1 и оставлен без изменений. Запущенная затем штатная
  all-bundle regeneration также обновила связанные `bundles/P0.7.json` и `bundles/P1.7.json`;
  эти два generated diff остаются unstaged вместе с owning foreign input.
- `composer.json`, `composer.lock`, `phpstan.neon` и `phpstan-baseline.neon` не изменялись.
- Host PHPStan не считается analysis-result: Laravel bootstrap завершился до анализа с
  `SQLSTATE[08006]`, потому что hostname `postgres` доступен только в Compose network. Полные
  configured/max измерения выполнены в healthy Compose service `app` на том же bind-mounted tree.

## Runtime, tools и immutable inputs

| Носитель | Значение на baseline tree |
|:--|:--|
| Product commit | `469acf640fc95944a3accc378445190dbca2a9ba` |
| Host PHP | `8.4.1` (`PHP_VERSION_ID=80401`) |
| Compose `app` PHP | `8.5.8` |
| Composer | `2.8.3` |
| Laravel | `13.25.0` |
| Filament | `5.7.6` |
| Pint | `1.30.4` |
| PHPStan | `2.2.8` |
| Larastan | `3.10.0` |
| Pest / PHPUnit | `5.0.4` / `13.2.6` |
| Rector | отсутствует в lock, `vendor/bin/rector` отсутствует |
| `composer.json` SHA-256 | `99c3376c0c92c2cd421667e38cd010c855edddf7496a4091df60e2aff39b945e` |
| `composer.lock` SHA-256 | `2dda5bb380f4f3c2e32c3609370d3c14925827664a39278add90bff0900d714e` |
| `phpstan.neon` SHA-256 | `ffaf31a24657dfc33ca65c7951fb10f8d120798b50195fda466f7d5f7da93842` |
| `phpstan-baseline.neon` SHA-256 | `ed4bd788dd7dac3bd0045b4a94c8e5422310dbc303f03d18ef38db23e61cd2e0` |

Воспроизведение: `php -v`, `composer --version --no-ansi`,
`composer show --locked --format=json`, `./vendor/bin/{pint,phpstan,pest} --version` и
`sha256sum composer.json composer.lock phpstan.neon phpstan-baseline.neon`.

## Dependency graph и audit

`composer show --locked --format=json` возвращает полный graph из 204 locked packages;
`composer show --locked --direct --format=json` — 30 direct packages. Нормализованный список
`<name> <version>`, отсортированный `LC_ALL=C sort`, имеет SHA-256
`0682fc2022bdf0145748a39c4d44b8f1bd97e126e4efd3762d7210ed9cc498ca`.

| Direct package | Locked version |
|:--|:--|
| `axioma-studio/azguard-core` | `dev-main` |
| `axioma-studio/azguard-filament` | `dev-main` |
| `fakerphp/faker` | `1.24.1` |
| `filament/filament` | `5.7.6` |
| `larastan/larastan` | `3.10.0` |
| `laravel/boost` | `2.5.3` |
| `laravel/doctor` | `0.1.0` |
| `laravel/framework` | `13.25.0` |
| `laravel/horizon` | `5.48.2` |
| `laravel/pail` | `1.2.7` |
| `laravel/pao` | `1.1.3` |
| `laravel/pint` | `1.30.4` |
| `laravel/pulse` | `1.8.0` |
| `laravel/sail` | `1.65.0` |
| `laravel/tinker` | `3.0.2` |
| `league/flysystem-aws-s3-v3` | `3.35.2` |
| `mockery/mockery` | `1.6.12` |
| `mpdf/mpdf` | `8.3.1` |
| `nunomaduro/collision` | `8.9.5` |
| `pestphp/pest` | `5.0.4` |
| `pestphp/pest-plugin-agent` | `5.0.0` |
| `pestphp/pest-plugin-laravel` | `5.0.1` |
| `phpoffice/phpspreadsheet` | `5.9.0` |
| `phpunit/phpunit` | `13.2.6` |
| `prism-php/prism` | `0.100.1` |
| `sentry/sentry-laravel` | `4.27.0` |
| `spatie/laravel-data` | `4.23.0` |
| `spatie/laravel-settings` | `3.9.0` |
| `symfony/css-selector` | `8.1.0` |
| `symfony/dom-crawler` | `8.1.1` |

`composer validate --strict --no-check-publish --no-ansi` завершился exit 0.
`composer audit --locked --format=json` завершился exit 0: advisory packages 0, advisories 0,
abandoned packages 0.

## Pint dry baseline

`./vendor/bin/pint --test --format=json` завершился exit 1. Это evidence до mutation, а не
разрешение применить formatter.

| Path | Fixer candidates |
|:--|:--|
| `config/cache.php` | `fully_qualified_strict_types`, `ordered_imports` |
| `config/database.php` | `fully_qualified_strict_types`, `ordered_imports` |
| `config/filesystems.php` | `fully_qualified_strict_types`, `single_line_after_imports` |
| `config/queue.php` | `fully_qualified_strict_types`, `single_line_after_imports` |
| `config/services.php` | `fully_qualified_strict_types`, `single_line_after_imports` |
| `config/settings.php` | `array_indentation`, `fully_qualified_strict_types`, `cast_spaces`, `single_line_after_imports` |
| `routes/console.php` | `ordered_imports` |

## PHPStan/Larastan baseline

### Configured run

Команда:

```bash
docker compose exec -T -e PAO_DISABLE=true app ./vendor/bin/phpstan analyse \
  --no-progress --error-format=json --memory-limit=1G
```

Exit 1; `file_errors=34`; identifier groups 14; path groups 13. Существующий committed baseline
отдельно содержит 54 message entries / 59 suppressed occurrences. Configured run поэтому красный
поверх baseline и не выдаётся за zero-error.

| Identifier | Count |
|:--|--:|
| `argument.type` | 3 |
| `identical.alwaysFalse` | 1 |
| `instanceof.alwaysTrue` | 1 |
| `isset.offset` | 3 |
| `larastan.noEnvCallsOutsideOfConfig` | 2 |
| `match.alwaysTrue` | 1 |
| `method.notFound` | 6 |
| `nullCoalesce.offset` | 2 |
| `nullsafe.neverNull` | 3 |
| `property.defaultValue` | 1 |
| `property.notFound` | 6 |
| `property.onlyWritten` | 3 |
| `return.phpDocType` | 1 |
| `return.void` | 1 |

| Path | Count |
|:--|--:|
| `app/Actions/Procurement/Proxy/UpdateCrawlerProxySettingsAction.php` | 2 |
| `app/Actions/Procurement/Screening/ApplyScreeningDispositionAction.php` | 2 |
| `app/Dto/Procurement/Ai/Screening/StoredScreeningResult.php` | 1 |
| `app/Filament/Pages/CrawlerProxySettingsPage.php` | 3 |
| `app/Filament/Resources/Procurement/Ai/Analyses/Schemas/AnalysisInfolist.php` | 2 |
| `app/Filament/Resources/Procurement/Ai/Analyses/Tables/AnalysesTable.php` | 8 |
| `app/Filament/Resources/Procurement/Ai/Fixtures/Schemas/RunResultSchema.php` | 1 |
| `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php` | 9 |
| `app/Services/Procurement/Prepare/DocumentPreparer.php` | 1 |
| `app/Services/Procurement/Proxy/CrawlerProxySettingsResolver.php` | 1 |
| `app/Services/Procurement/Proxy/ProxyBindingResolver.php` | 1 |
| `app/Services/Procurement/Screening/ScreeningProcessor.php` | 1 |
| `app/Support/SecretValue.php` | 2 |

Normalized identifier/path maps have SHA-256
`ac1009a423e3e181296cb890448eadf5f04e9bde3767c28616c7205ce19f60f8` /
`d7b71ab0e7c0dc92aa1613c96380545480b15af4d83037903f0091e3e4cccff0`.

### Max without committed baseline

Команда использует Larastan extension напрямую, но не `phpstan.neon`, поэтому не включает
`phpstan-baseline.neon`:

```bash
docker compose exec -T -e PAO_DISABLE=true app ./vendor/bin/phpstan analyse \
  --configuration=vendor/larastan/larastan/extension.neon --level=max app \
  --no-progress --error-format=json --memory-limit=1G
```

Exit 1; `file_errors=1637`; identifier groups 41; path groups 271. Полный normalized path/count
map проверяется carrier по SHA-256
`015cfe0f532a3e4e04c19e62c09a52e13507d55a6322adceeafc13e462be6ee1`; normalized identifier/count
map — по SHA-256 `8dda28f6beb37def82e6890798628f11b0610f4601ea1dbc7f4dcbd8b6a9d899`.

| Identifier | Count |
|:--|--:|
| `argument.type` | 430 |
| `cast.int` | 183 |
| `offsetAccess.nonOffsetAccessible` | 179 |
| `cast.string` | 177 |
| `missingType.generics` | 163 |
| `return.type` | 99 |
| `property.nonObject` | 87 |
| `missingType.iterableValue` | 55 |
| `property.notFound` | 49 |
| `method.nonObject` | 28 |
| `encapsedStringPart.nonString` | 28 |
| `binaryOp.invalid` | 24 |
| `cast.double` | 24 |
| `offsetAccess.notFound` | 16 |
| `offsetAccess.invalidOffset` | 14 |
| `foreach.nonIterable` | 12 |
| `nullsafe.neverNull` | 9 |
| `argument.templateType` | 8 |
| `method.notFound` | 7 |
| `postInc.type` | 5 |
| `assignOp.invalid` | 4 |
| `unaryOp.invalid` | 4 |
| `instanceof.alwaysTrue` | 3 |
| `property.onlyWritten` | 3 |
| `larastan.noEnvCallsOutsideOfConfig` | 3 |
| `isset.offset` | 3 |
| `larastan.noUnnecessaryCollectionCall` | 2 |
| `class.notFound` | 2 |
| `identical.alwaysFalse` | 2 |
| `arrayValues.list` | 2 |
| `nullCoalesce.offset` | 2 |
| `array.invalidKey` | 1 |
| `return.void` | 1 |
| `trait.unused` | 1 |
| `match.alwaysTrue` | 1 |
| `property.defaultValue` | 1 |
| `argument.unknown` | 1 |
| `notIdentical.alwaysTrue` | 1 |
| `booleanOr.rightAlwaysFalse` | 1 |
| `assign.propertyType` | 1 |
| `return.phpDocType` | 1 |

Наиболее крупные path groups: `ExtractionOrchestrator.php` 146,
`EvalCompareCommand.php` 79, `ProxyResolver.php` 66, `FormProfileReader.php` 49 и
`GoldDocumentView.php` 37. Это candidates для P10.2–P10.5, не подтверждённые дефекты P10.1.

## Tree manifest и modern-PHP candidates

Воспроизводящие команды: `rg --files ... | wc -l`, `xargs wc -l`, `token_get_all()` и
declaration-only regex из carrier.

| Signal | Count on baseline tree | Interpretation |
|:--|--:|:--|
| PHP files in `app` | 700 | inventory only |
| PHP LOC in `app` | 54881 | `wc -l` total |
| PHP test files | 286 | inventory only |
| migration files | 71 | inventory only |
| architecture guards | 7 | полный список ниже |
| class declarations | 591 | declaration inventory |
| final classes | 496 | declaration inventory |
| readonly classes | 269 | declaration inventory |
| abstract classes | 3 | declaration inventory |
| non-final concrete class candidates | 92 | raw candidates; blanket finalization запрещена D39 |
| interfaces / traits / enums | 21 / 4 / 84 | declaration inventory |
| constructors | 275 | raw promotion/type candidates, не verdict |
| `switch` / `match` tokens | 0 / 85 | syntax inventory |
| arrow functions | 438 | syntax inventory |

Architecture guards (sorted-list SHA-256
`ff0677696f2e17dbaadf46909caed644bc328020016dbe1c6df328b1fb8093f4`):

- `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php`
- `tests/Unit/Architecture/DocumentFormatRegistryTest.php`
- `tests/Unit/Architecture/DomainLayoutTest.php`
- `tests/Unit/Architecture/HistoryModulePlatformAgnosticTest.php`
- `tests/Unit/Architecture/JobRetryContractGuardTest.php`
- `tests/Unit/Architecture/PlatformExtensionContractTest.php`
- `tests/Unit/Architecture/ScreeningContractTest.php`

## Rector compatibility branch D39

Verdict: **compatible, recommendation-only for P10.6; no dependency/config mutation in P10.1**.

- Official Rector repository states PHP 8.5 support, installation via `rector/rector`, and dry-run
  before apply: <https://github.com/rectorphp/rector> (checked 2026-08-13, confidence: high).
- Official community Laravel extension metadata publishes `driftingly/rector-laravel` 2.5.0,
  requires PHP `^7.4 || ^8.0` and Rector `^2.2.7`, and documents
  `LaravelLevelSetList::UP_TO_LARAVEL_130` plus composer-based Laravel detection:
  <https://packagist.org/packages/driftingly/rector-laravel> and
  <https://github.com/driftingly/rector-laravel> (checked 2026-08-13, confidence: high).
- Official Composer metadata query on 2026-08-13 reports `rector/rector` 2.6.2 with PHP
  `^7.4|^8.0`; `composer require --dev 'rector/rector:^2.5'
  'driftingly/rector-laravel:^2.5' --dry-run --no-scripts --no-plugins` resolves exactly
  Rector 2.6.2 + extension 2.5.0 on this lock. Post-command input hashes remained byte-identical.

Recommended P10.6 branch: pin `rector/rector` `2.6.2` and
`driftingly/rector-laravel` `2.5.0`; configure `LaravelSetProvider` with
`withComposerBased(laravel: true)` and start with an explicit dry run. Do not add the abandoned
`rector/rector-laravel` package. Rule selection remains P10.6 work and cannot imply blanket
`final_class`, mass named-argument rewrites, or an apply in P10.1.

RAG:✅ (official repositories/Composer registry above; checked 2026-08-13).

## Verification status

- RAG: Web search/open against official Rector, driftingly and Packagist sources; Composer
  metadata/solver cross-check; all checks completed 2026-08-13.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: ничего.
- Обходки/[POSSIBLE-DEFECT]: host PHPStan bootstrap failure recorded separately; authoritative
  analysis ran inside the healthy application service, not through a synthetic config.
