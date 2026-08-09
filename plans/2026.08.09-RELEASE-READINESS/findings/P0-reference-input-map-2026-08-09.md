# P0 reference input map — 2026-08-09

## Boundary

Это raw recon для авторинга P0, а не сравнительный вердикт. Внешний RAG не выполнялся. Reference repositories только читаются; их dirty state не трогается.

## Repository snapshots at authoring time

| Reference | Absolute path | HEAD | Dirty entries | `git status --porcelain=v1` SHA-256 |
|:--|:--|:--|--:|:--|
| Sova | `/home/vostrikov/sibintek/projects/sova` | `57b50ad786111c07a9fa2740aee84b8fcc53b9f8` | 340 | `ec51bd49c9b94382579f3c2c9c82bed4a21388530e4cc74234f1753d87865efb` |
| Vintera/CMS | `/home/vostrikov/projects/vintera/cms` | `94b6e3f49a75744f8b775bc49d7c27fd61e30420` | 18 | `39f761c44d18e3f00e957405a2bb60e83404a2cfd341e0769f68a6d9dba246c6` |
| Dterema | `/home/vostrikov/projects/dterema/dterema` | `205ea3415f19960a9ed4e7ae29e00b103381c464` | 6 | `f83f0eb436a340d39e824084fc80c062798ba0b7a31f16ed995d4983b0af68ad` |

Вывод: HEAD недостаточен для provenance; P0.2 повторно снимает HEAD/status digest и пинит SHA-256 каждого реально прочитанного файла. RAG:— (repo-grounded: local `git`/`sha256sum` recon 2026-08-09).

## Candidate files

### Sova

- `/home/vostrikov/sibintek/projects/sova/Makefile`
- `/home/vostrikov/sibintek/projects/sova/docker/Makefile`
- `/home/vostrikov/sibintek/projects/sova/docker-compose.yml`
- `/home/vostrikov/sibintek/projects/sova/docker-compose.prod.yml`
- `/home/vostrikov/sibintek/projects/sova/docker-compose.override.example.yml`
- `/home/vostrikov/sibintek/projects/sova/docker/php/Dockerfile`

### Vintera/CMS

- `/home/vostrikov/projects/vintera/cms/Makefile`
- `/home/vostrikov/projects/vintera/cms/docker/Makefile`
- `/home/vostrikov/projects/vintera/cms/docker-compose.yml`
- `/home/vostrikov/projects/vintera/cms/docker-compose.prod.yml`
- `/home/vostrikov/projects/vintera/cms/docker/php/Dockerfile`
- `/home/vostrikov/projects/vintera/cms/docker/frontend/Dockerfile`
- `/home/vostrikov/projects/vintera/cms/docs/server/deploy.md`
- `/home/vostrikov/projects/vintera/cms/routes/web.php`
- `/home/vostrikov/projects/vintera/cms/app/Http/Controllers/HandbookController.php`
- `/home/vostrikov/projects/vintera/cms/app/Http/Middleware/EnsureHandbookAccess.php`

### Dterema

- `/home/vostrikov/projects/dterema/dterema/Makefile`
- `/home/vostrikov/projects/dterema/dterema/docker/Makefile`
- `/home/vostrikov/projects/dterema/dterema/docker-compose.yml`
- `/home/vostrikov/projects/dterema/dterema/docker-compose.prod.yml`
- `/home/vostrikov/projects/dterema/dterema/docker/php/Dockerfile`
- `/home/vostrikov/projects/dterema/dterema/docs/operations/deployment.md`

## Read policy

1. Игнорировать `vendor/`, `node_modules/`, generated bundles, caches и secret values.
2. Не запускать deploy/compose/migrate/seed commands в reference repositories.
3. Не выполнять checkout, reset, stash, clean, formatter или commit.
4. Для dirty file в comparison помечать, что evidence относится к working-tree bytes, а не к HEAD.
