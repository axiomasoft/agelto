# P11 test baseline

## Measurement identity

- Measured 2026-08-14 on committed tree
  `48aa8f58be9197d83d7ab502cd6088ce42719fd4` (`main`). Product paths were clean. Foreign owner
  changes were present in `CLAUDE.md`, `bundles/P0.7.json` and `bundles/P1.7.json`; P11.1 did not
  touch or stage them. Journal/state/status changes belong to the active plan protocol.
- All 12 required endpoints were terminal before measurement: P0.4, P0.6, P1.5, P2.6, P3.5,
  P4.5, P5.5, P7.6, P8.5 and P9.6 were green; P6.6 and P10.6 were terminal orange with their
  deviations already recorded in their state projections.
- Safety preflight: `make test-preflight` exited 0 and resolved only `agelto_test`. No dev or
  production database was used.
- Raw JUnit/Clover/text and copied host timing evidence is under ignored
  `storage/app/p11-evidence-48aa8f58/`. The committed byte sizes and hashes below are the durable
  manifest; raw files are intentionally not product or plan inputs and the contract fails if absent.

## Runner and locked stack

| Dimension | Measured value |
|:--|:--|
| Host | x86_64, Intel Core i7-14700K, 20 cores / 28 logical CPUs, 32,613,188 KiB RAM |
| Container runtime | Docker 29.7.2, Compose v5.4.0 |
| PHP / coverage | PHP 8.5.8; PCOV enabled, directory `/var/www/html` |
| Database | PostgreSQL 17.10; canonical test database `agelto_test` |
| Application | Laravel framework v13.25.0 |
| Pest graph | Pest v5.0.4, pest-plugin-laravel v5.0.1, pest-plugin-agent v5.0.0 |
| PHPUnit / parallel | PHPUnit 13.2.6, Paratest v7.23.1 |
| Agent output | Laravel PAO v1.1.3, laravel/agent-detector v2.0.2 |

Tracked inventory: 50 Unit files + 213 Feature files = 263 PHP test files; 1,620 `test(` calls,
zero `it(` calls and no Browser suite. Runtime discovery produced one `default` group with 1,569
tests and 7,084 assertions. There are three conditional GD skip declarations, but the measured
runner satisfied them: zero runtime skips, todos, failures or errors.

## Exact command and run ledger

All commands except the isolated TIA-failure reproduction used this exact prefix; ledger commands
below are the remaining exact argv and are run as `"${C[@]}" <argv>`:

```bash
C=(docker compose --env-file .env -f docker/docker-compose.yml -f docker/docker-compose.dev.yml \
  -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app)
```

`/usr/bin/time` wrote the corresponding `.time` file. JUnit/Clover/text files are relative to
`storage/app/p11-evidence-48aa8f58/`; every exit and artifact is bound to a row rather than inferred
from a count.

| Run | Exact argv after prefix | Exit / wall, s | Raw artifacts |
|:--|:--|:--|:--|
| serial-1 | `php artisan test --ci --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/serial-1.xml` | 0 / 89.97 | `serial-1.{xml,time}` |
| serial-2 | `php artisan test --ci --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/serial-2.xml` | 0 / 92.33 | `serial-2.{xml,time}` |
| serial-3 | `php artisan test --ci --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/serial-3.xml` | 0 / 89.10 | `serial-3.{xml,time}` |
| serial-4 | `php artisan test --ci --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/serial-4.xml` | 0 / 87.58 | `serial-4.{xml,time}` |
| serial-5 | `php artisan test --ci --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/serial-5.xml` | 0 / 92.93 | `serial-5.{xml,time}` |
| profile | `php artisan test --ci --no-tia --compact --profile --log-junit=storage/app/p11-evidence-48aa8f58/profile.xml` | 0 / 87.37 | `profile.{xml,txt,time}` |
| coverage | `php artisan test --ci --no-tia --compact --coverage-clover=storage/app/p11-evidence-48aa8f58/coverage-clover.xml --log-junit=storage/app/p11-evidence-48aa8f58/coverage-junit.xml` | 0 / 104.58 | `coverage-{clover,junit}.xml`, `coverage.{txt,time}` |
| tia-fresh | `php artisan test --tia --fresh --ci --compact --log-junit=storage/app/p11-evidence-48aa8f58/tia-fresh.xml` | 0 / 114.65 | `tia-fresh.{xml,txt,time}` |
| tia-replay | `php artisan test --tia --ci --compact --log-junit=storage/app/p11-evidence-48aa8f58/tia-replay.xml` | 0 / 22.59 | `tia-replay.{xml,txt,time}` |
| parallel-2-1 | `./vendor/bin/pest --parallel --processes=2 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-2-1.xml` | 1 / 0.31 | `parallel-2-1.{txt,time}`; JUnit absent by discovery failure |
| parallel-2-2 | `./vendor/bin/pest --parallel --processes=2 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-2-2.xml` | 1 / 0.36 | `parallel-2-2.{txt,time}`; JUnit absent by discovery failure |
| parallel-2-3 | `./vendor/bin/pest --parallel --processes=2 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-2-3.xml` | 1 / 0.33 | `parallel-2-3.{txt,time}`; JUnit absent by discovery failure |
| parallel-4-1 | `./vendor/bin/pest --parallel --processes=4 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-4-1.xml` | 1 / 0.34 | `parallel-4-1.{txt,time}`; JUnit absent by discovery failure |
| parallel-4-2 | `./vendor/bin/pest --parallel --processes=4 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-4-2.xml` | 1 / 0.36 | `parallel-4-2.{txt,time}`; JUnit absent by discovery failure |
| parallel-4-3 | `./vendor/bin/pest --parallel --processes=4 --no-tia --compact --log-junit=storage/app/p11-evidence-48aa8f58/parallel-4-3.xml` | 1 / 0.34 | `parallel-4-3.{txt,time}`; JUnit absent by discovery failure |
| shard-1-1 | `php artisan test --ci --no-tia --compact --shard=1/1 --log-junit=storage/app/p11-evidence-48aa8f58/shard-1-1.xml` | 0 / 92.30 | `shard-1-1.{xml,txt,time}` |
| shard-1-2 | `php artisan test --ci --no-tia --compact --shard=1/2 --log-junit=storage/app/p11-evidence-48aa8f58/shard-1-2.xml` | 0 / 45.48 | `shard-1-2.{xml,txt,time}` |
| shard-2-2 | `php artisan test --ci --no-tia --compact --shard=2/2 --log-junit=storage/app/p11-evidence-48aa8f58/shard-2-2.xml` | 0 / 45.21 | `shard-2-2.{xml,txt,time}` |
| shard-1-4 | `php artisan test --ci --no-tia --compact --shard=1/4 --log-junit=storage/app/p11-evidence-48aa8f58/shard-1-4.xml` | 0 / 24.99 | `shard-1-4.{xml,txt,time}` |
| shard-2-4 | `php artisan test --ci --no-tia --compact --shard=2/4 --log-junit=storage/app/p11-evidence-48aa8f58/shard-2-4.xml` | 0 / 24.76 | `shard-2-4.{xml,txt,time}` |
| shard-3-4 | `php artisan test --ci --no-tia --compact --shard=3/4 --log-junit=storage/app/p11-evidence-48aa8f58/shard-3-4.xml` | 0 / 20.70 | `shard-3-4.{xml,txt,time}` |
| shard-4-4 | `php artisan test --ci --no-tia --compact --shard=4/4 --log-junit=storage/app/p11-evidence-48aa8f58/shard-4-4.xml` | 0 / 20.09 | `shard-4-4.{xml,txt,time}` |

The initial TIA failure was reproduced after repair with the same argv as `tia-fresh`, an empty
container HOME (`-e HOME=/tmp/p11-tia-empty-home-48aa8f58`) and intentionally absent local
`origin/HEAD`: exit 1 / 0.31 s, raw `tia-fresh-initial-failure.{txt,time}`. Remote `main` and
`origin/HEAD` were restored immediately; no tracked path changed.

## Five-run serial baseline

All five runs exited 0 with 1,569 passed / 7,084 assertions. The normalized
`classname + name + status` SHA-256 was identical in every run:
`29daf6fa6e82839f8f7081e370d65ebda45692c6b2937d1fbf387f43254820a5`.

| Run | Wall, s | JUnit SHA-256 |
|:--|--:|:--|
| 1 | 89.97 | `06b4be750f126dba949e6ceca1e1ba0239a0cc123e1cce4f57ee8f434432d3ab` |
| 2 | 92.33 | `11e695ac0c1fa974698815d6c3c25a14e2b34bc047d6eb03f0388e4ed26e1135` |
| 3 | 89.10 | `1b0cb8f9e2acfbf254492085f17f8c065c192fce248073017b209fd0c73e83ef` |
| 4 | 87.58 | `0211c7336cade32dbc49cf953106a38b1fbc43c97ff2e7b09b2d0a485c2ed127` |
| 5 | 92.93 | `38105cb23b4162bdbb647f8c5a0d8963b674e338484f3fb7abba63ccdf19462e` |

Median wall time is **89.97 s**; range is 87.58–92.93 s. This is the immutable no-TIA reference for
later performance budgets, not yet the P11.6 budget itself.

## Profile and coverage

- Profile run: exit 0, 87.37 s, same 1,569-name/status set. JUnit SHA-256
  `cc189309643aa1aacd4f44cf7c2549e799dbbfec12faa58ae3e602097ac4b30b`.
- The slowest case was `CsvBlockIngestorTest` deduplication of 510/500 rows at 1.803 s. The next
  four were `RevisionTest` idempotent re-ingest (1.153 s), screening schema migration (1.119 s),
  reverse-time procurement concurrency (1.021 s) and status applicability migration (0.894 s).
  No single test dominates the 90-second suite.
- Coverage run: exit 0, 104.58 s, same name/status set. Clover SHA-256
  `bf2ce5e1dee6ec3252e3f391302ac15e3fb1590ebf03eae8262c7504de62ac40`; JUnit SHA-256
  `67985624122d720198f3f30261d7d30d8e7c547e400ea96361c6df3b7069de39`.
- Clover totals: 703 files, 50,612 NCLOC, 579 classes, 2,283 methods / 1,525 covered (66.80%),
  20,246 statements / 17,872 covered (**88.27%**), 22,529 elements / 19,397 covered (86.10%).
  P11.4 still decides semantic risk closure; this percentage alone is not acceptance.

## TIA evidence

The first fresh attempt failed before tests because the clone lacked `origin/HEAD`. Remote HEAD was
verified as `main`; `git remote set-head origin --auto` repaired local Git metadata without a tracked
change, and the same attempt continued.

| Class | Wall, s | Executed / replayed | Result | JUnit SHA-256 |
|:--|--:|:--|:--|:--|
| fresh graph | 114.65 | 1,569 executed | green; exact serial name/status parity | `666d50176a08d8b845f336410d692275e13bcd3502c4178f0b05e1b2f6e83f02` |
| replay | 22.59 | 0 executed / 1,569 replayed | green replay, **not acceptance evidence** | `b11aa59ab390d000b60324586acbded00c72c4a2b3e16544044cc255a9d33c55` |

Replay reduced wall time by 74.9% versus the serial median. D40 therefore retains TIA for the local
edit loop, while ordinary item/phase/CI acceptance remains full `--ci --no-tia`.

## Parallel and shard candidates

Both explicit parallel candidates were repeated three times and failed deterministically during
discovery before JUnit creation:

```text
ErrorException: The use statement with non-compound name 'RuntimeException' has no effect
tests/Unit/Llm/GeminiResponseSchemaAdapterTest.php:11
```

The direct no-TIA probes for 2 and 4 workers exited 1 in 0.31–0.36 s. Probe output SHA-256 for both
worker counts was `dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2`.
P11.1 did not edit the test or suppress the warning. Result: **parallel is rejected on this tree**;
P11.2/P11.3 may repair and remeasure it within their declared scopes.

Sharding remained serial within each runner and was green:

| Set | Member walls, s | Sum runner-seconds | Distributed critical path | Name/status parity | JUnit SHA-256 by member |
|:--|:--|--:|--:|:--|:--|
| 1× | 92.30 | 92.30 | 92.30 | exact, 1,569 | `2c5c66561530a1721b4dc98e957df7e8c06257f742de0dc2c849c0ca366f61e7` |
| 2× | 45.48, 45.21 | 90.69 | 45.48 | exact union, 1,569 unique; 0 missing/extra/duplicate | `170d3a3c…`, `2f338ac8…` |
| 4× | 24.99, 24.76, 20.70, 20.09 | 90.54 | 24.99 | exact union, 1,569 unique; 0 missing/extra/duplicate | `fadaafd3…`, `434c555c…`, `37e22738…`, `7b3c7694…` |

Both unions reproduce normalized SHA-256
`29daf6fa6e82839f8f7081e370d65ebda45692c6b2937d1fbf387f43254820a5`.
Against the 89.97-second median, 2 shards have a projected 49.4% wall reduction at 100.8% runner
cost; 4 shards project 72.2% at 100.6% cost. D40 chooses the smallest qualifying mode, so the P11.2
input is **provisional 2-way sharding**, subject to three clean repetitions and its isolation guard.
No `tests/.pest/shards.json` was created by this read-only item.

Full shard JUnit hashes: `1/2=170d3a3c8f3b5373538a9b333f4dd0dea9f5b11d7441329e99fd50ca80ea92d4`,
`2/2=2f338ac85a2256d49e9f8261513cc97f95e0397a3c0a3e9544f84e586ddf26c0`,
`1/4=fadaafd38a51585d06c670f463b3958b97a2e1efbc1517f33f4f766969ceda19`,
`2/4=434c555cb04f9e6618cdabda30a2532bb272ee189905dccda93a06f2221cb509`,
`3/4=37e22738361917c4461433ff0609d7f9077349088dfd1ebabb940982d14e76f9`,
`4/4=7b3c7694508d0be76d9b651d8aa29b4e573338e06c6e1bfd66b1c7a052d4a0b7`.

## P11.2 follow-up measurement

`bash docker/tests/test-runtime-contract.sh` revalidated the local TIA cache reset/refresh and
edit-loop, then completed three independent full `--ci --no-tia` serial runs. Their normalized
name/status sets were identical: 1,569 passed / 7,084 assertions; the measured serial median was
**91.97 s**, within the 125% guard of the P11.1 89.97-second baseline. The P11.1 profile remains
the performance explanation: no individual slow test dominates enough to justify changing its
behavior or weakening its assertions.

The obsolete global `RuntimeException` import in `GeminiResponseSchemaAdapterTest` was removed, so
Pest can discover the test normally. An actual simultaneous 2-way external shard repetition then
reproduced shared-`agelto_test` interference: missing tables, migration deadlocks and cross-test
state assertions. It is an isolation defect, not a performance result. Per D40 P11.2 therefore
selects **serial** as the only safe release/CI mode; no `tests/.pest/shards.json` timing map is
committed. P11.3 owns the required database/filesystem/process isolation proof before a shard
candidate can be reconsidered.

## P11.4 post-repair measurement

- `make test:full`: exit 0, **1,575 passed / 7,107 assertions**, 91.35 s.
- `make test:coverage`: exit 0, **1,575 passed / 7,107 assertions**, 102.75 s. Clover
  `storage/app/coverage/clover.xml` SHA-256
  `01468cd5d0f30ff43dfdf5d7e7d6883bc80fa99327386f09b9d8226399d13217` reports 703 files,
  2,283 methods / 1,525 covered (66.80%), and 20,260 statements / 17,873 covered
  (**88.22%**). This exceeds the P11.4 global 70% floor; the full critical-file floor remains a
  P11.5 CI gate, not a percentage-based substitute for the risk matrix.
- `make test:profile`: exit 0, **1,575 passed / 7,107 assertions**, 85.45 s. The selected D40
  release mode remains serial: no shard map is created or implied by this run.
- `bash docker/tests/test-runtime-contract.sh` and
  `bash docker/tests/test-isolation-contract.sh` are green after the native-Paratest topology
  repair. They are the deterministic D40 and D41 carriers respectively.

## Raw evidence manifest

The contract requires this ignored directory and validates every byte count and SHA-256 below; it
fails on a clean checkout without the raw evidence instead of accepting prose. Format is
`sha256 size_bytes filename`.

<!-- raw-manifest:start -->
```text
bf2ce5e1dee6ec3252e3f391302ac15e3fb1590ebf03eae8262c7504de62ac40 1672191 coverage-clover.xml
67985624122d720198f3f30261d7d30d8e7c547e400ea96361c6df3b7069de39 789102 coverage-junit.xml
a020402ab7793d6187876d299f61208593ddffd9d732c3200edb372f3c30dbcb 50 coverage.time
4bf8a824a45f06b5eac851e7a614d9b72ef510bdb5e4651162381c067947bb5b 300985 coverage.txt
0a96da42a5a8f67992e3315f99d266a9ad222bb14eee86d4546ae559ac1a5a33 90 parallel-2-1.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-2-1.txt
b20f65920a1da3049629e268a30522d2f7aca9ec9010e04455b22e12b46a3242 90 parallel-2-2.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-2-2.txt
006770fb57520bfb1f062bf79893bc858219d18e968f65d09d8dbbc162677cc7 90 parallel-2-3.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-2-3.txt
9f53480d5b6dd454bd0163ad355a0a8af726a076a87e437363cd4e5f7828fa30 90 parallel-4-1.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-4-1.txt
e63f054d82cb4475ba57b6fbc634e81ab8f2b627421233b4f565f4df77675d92 90 parallel-4-2.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-4-2.txt
f4b8f80421e9dbb7e8d51c8df5af94f2891f5b6171885f5d05272f6a932e6fa7 90 parallel-4-3.time
dd986e7f25d244cd8a737202d21fb49fe01ef21170e91c01ee8a6355d8872fa2 776 parallel-4-3.txt
0a067bf7d6be4fee4f33992e015f7065ae4c988dedad1e093ab9a01abc029204 49 profile.time
8c67e6ff78f9796c037fabd587bdac0d202c121d0bd4b2be3a0f4b4cde10d4f5 300984 profile.txt
cc189309643aa1aacd4f44cf7c2549e799dbbfec12faa58ae3e602097ac4b30b 789101 profile.xml
fb4f6753ec49a185203d53fad7835f0ead3ceba23bc48cecb58d9dd546da09ca 49 serial-1.time
06b4be750f126dba949e6ceca1e1ba0239a0cc123e1cce4f57ee8f434432d3ab 789101 serial-1.xml
7d46722046e84ca42fc7dc92cbbff371fa90bd3040e1efa12bb3ad2d53b9a705 49 serial-2.time
11e695ac0c1fa974698815d6c3c25a14e2b34bc047d6eb03f0388e4ed26e1135 789101 serial-2.xml
8b7a92693b5361f7ad9e636ba46390c34ffb3db3c17a3c69de13a2c6b4704c55 49 serial-3.time
1b0cb8f9e2acfbf254492085f17f8c065c192fce248073017b209fd0c73e83ef 789101 serial-3.xml
84e56bd6cc6398273aa8d1a5aef0775cfcd51fa583bd20cc87912e6edd1ae922 49 serial-4.time
0211c7336cade32dbc49cf953106a38b1fbc43c97ff2e7b09b2d0a485c2ed127 789101 serial-4.xml
a20769b256f714a6514547325308c612314c5d026845f2c1001122a8537ad4e9 49 serial-5.time
38105cb23b4162bdbb647f8c5a0d8963b674e338484f3fb7abba63ccdf19462e 789101 serial-5.xml
d66bfad72bc5c7512fcc56dcc333ae20f9ce1b20e4ad41e8749e19067c823de8 53 shard-1-1.time
a05688a9db7bfa7ce725dad6ad4a1973cdc9b6f9a60cd021297e44825454cb14 301054 shard-1-1.txt
2c5c66561530a1721b4dc98e957df7e8c06257f742de0dc2c849c0ca366f61e7 789101 shard-1-1.xml
af18eaa38cc0e1be61123a7c9708492778ad7f54785c90ab96f6b415b58e6a75 53 shard-1-2.time
3f8131297037178eca67314e7dcbf1d27309793b0851b1a8f946dcaa1a35815b 154379 shard-1-2.txt
170d3a3c8f3b5373538a9b333f4dd0dea9f5b11d7441329e99fd50ca80ea92d4 405423 shard-1-2.xml
8fe496d588da14d924d4e9c4b329f7e2084e110dcf6e1e48cb25bf9fba3b53b9 53 shard-1-4.time
94a70575122ccdbc36e6ba9677435e32e77d852c3888ab18db2f36ddca29b25a 73088 shard-1-4.txt
fadaafd38a51585d06c670f463b3958b97a2e1efbc1517f33f4f766969ceda19 189372 shard-1-4.xml
6434c85f7106488c40caeb54710f0119edcabfc2556941c71f91cb8e43f50417 53 shard-2-2.time
c690f178f5885e4805a8f8825a2af616b65377f092bafe85077b2521aff29368 146861 shard-2-2.txt
2f338ac85a2256d49e9f8261513cc97f95e0397a3c0a3e9544f84e586ddf26c0 384143 shard-2-2.xml
34fd1959388482722dd3693dc1aa1bcbe5be3d55422ef5db5101374ed65ae8ac 53 shard-2-4.time
76c20e6c449dd3c1838fb3e4aff5d40480844ab42d58ad7ada675e82ee4b5f12 77618 shard-2-4.txt
434c555cb04f9e6618cdabda30a2532bb272ee189905dccda93a06f2221cb509 204203 shard-2-4.xml
895bc3c596e9fe96042a54cdb9d0d582c49da368ec5700e734bbf6617ede0ebc 53 shard-3-4.time
1cff0adf6f2db0c6bf00d60b73a16363daa4ccc1989714eddff263d75f107d80 81476 shard-3-4.txt
37e22738361917c4461433ff0609d7f9077349088dfd1ebabb940982d14e76f9 216517 shard-3-4.xml
531a5296a58c46681b0ce4e184e4ece100ab40f596a8f911ea2d4f49d2ec93cd 53 shard-4-4.time
34ef5138de5ba0f7babca54f24b84658ad406d0e331d8904f0e97c32cc615582 69428 shard-4-4.txt
7b3c7694508d0be76d9b651d8aa29b4e573338e06c6e1bfd66b1c7a052d4a0b7 180406 shard-4-4.xml
f5c3d69c7b545c53e970d7faee179f747fc814f09fa57b8c248431077770b463 90 tia-fresh-initial-failure.time
035e74b013934eefc49668adfd40f4b07229e0d2a685802274fada4bb43df845 583 tia-fresh-initial-failure.txt
a712354ed21b7268b8cd2f163452af4065a1ed4939cc93e3eeace9ff0dce2704 54 tia-fresh.time
02382cc68c11fde5c25f55168175d1ab980ba99ab26036b339e2979b7c9e0fb6 301022 tia-fresh.txt
666d50176a08d8b845f336410d692275e13bcd3502c4178f0b05e1b2f6e83f02 789102 tia-fresh.xml
b98df97af0ea48043b2f879a41d0b07d1fd428ec8dae3745d88c8b9685ec7249 53 tia-replay.time
a1f6ce2d8ac7bdc2ce79f521c7856ae42d726f76b93e0277a4b2a1ce5a097351 269626 tia-replay.txt
b11aa59ab390d000b60324586acbded00c72c4a2b3e16544044cc255a9d33c55 789098 tia-replay.xml
```
<!-- raw-manifest:end -->

## P11.1 verdict

The terminal tree has a stable green serial baseline, useful local TIA replay and high measured
statement coverage. It does **not** yet have an admissible parallel carrier. Two-way sharding is the
smallest measured candidate that clears D40's speed/cost thresholds, but P11.2 owns the repeated
selection and P11.3 owns exact process isolation. Risk completeness remains governed by
`P11-risk-test-matrix.md`, not by the green count or 88.27% statement coverage.
