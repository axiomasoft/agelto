# Bootstrap recon — 2026-08-09

## Границы

Это первичная локальная разведка перед созданием скелета. Внешний RAG не выполнялся; выводы о
best practice не принимались. Полный сравнительный анализ и источники принадлежат P0.

## Agelto

- Корневой Makefile делегирует цели в docker/Makefile.
- docker/Makefile уже различает dev/prod env files, имеет prod deploy targets и передаёт
  CLAUDECODE, CURSOR_AGENT и AI_AGENT внутрь контейнера для agent-aware вывода.
- Development Compose bind-mount'ит исходники проекта, поэтому code-only изменения уже могут
  применяться без rebuild; контракт rebuild и дублирование build между сервисами требуют
  отдельного измерения.
- Production и development Compose разделены файлами docker-compose.prod.yml и
  docker-compose.yml; будущий Vite-контур пока не является законченным first-class workflow.
- composer.json использует path repositories для трёх packages AzGuard по внешнему пути
  ../../packages/azguard/packages/* и symlink=false.
- composer.json уже содержит post-update-cmd с vendor:publish --tag=laravel-assets --force.
  Следовательно, Livewire 404 нельзя заранее списывать только на отсутствие такого hook.
- docker/php/entrypoint.d/10-secrets.sh уже умеет читать часть переменных из *_FILE; полный
  secret contract, generation и leakage tests ещё не установлены.
- В текущей документации есть docker development/production и process/deploy материалы, но
  owner brief требует один проверенный zero-to-production маршрут.

## Локальные референсы

- Sova обнаружен по пути /home/vostrikov/sibintek/projects/sova.
- Vintera/CMS обнаружен по пути /home/vostrikov/projects/vintera/cms.
- Dterema обнаружен по пути /home/vostrikov/projects/dterema/dterema.
- Все три имеют Makefile/Compose carriers; Vintera и Dterema имеют deployment docs.
- Dterema deployment guide уже разделяет base seed и создание/сброс администратора и
  подчёркивает обязательность Filament assets.

## Vintera handbook

- routes/web.php направляет /handbook/{path?} в HandbookController через
  EnsureHandbookAccess.
- HandbookController читает build из storage/app/handbook-dist, проверяет realpath,
  блокирует traversal и вручную выставляет MIME для CSS/JS/HTML/fonts.
- EnsureHandbookAccess намеренно пропускает все non-HTML extensions без auth и проверяет
  panel access только для HTML.
- Это подтверждает замечание владельца: route/controller pattern пригоден как reference,
  но authorization boundary для assets не соответствует требованию Agelto.
- Production build использует base /handbook/ и named volume handbook_dist; корректность
  asset URLs и cache headers должна быть воспроизведена тестом, а не принята по коду.

## Bootstrap verdict

Скелет плана может быть создан без выбора технологий. Несущие решения оставлены фазе P0:
image/build topology, secret backend, package clone contract, asset publication lifecycle,
documentation generator/serving model, Pest 5 fast path и document taxonomy.

## Concurrent workspace update

После первичного preflight Git history получил три внешних коммита: afd08261 физически
архивировал 2026.08.06-CRAWL-HARDEN, 1cd23bb8 перенёс его root-материалы в project docs,
864e06ea зафиксировал bootstrap нового плана. Live-каталог старого плана отсутствует,
plans/archive/2026.08.06-CRAWL-HARDEN существует. Это supersedes отложенную часть D3 через D12.
