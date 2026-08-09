# FINDING (слой 1) — внешние факты под P4: общий бюджет запросов, троттлинг очереди, подача пачками

**Снято:** 2026-08-06 · **Движок:** `laravel-boost search-docs` (версионно-привязанный к
установленному дереву: `laravel/framework@13.x`) · **Зачем:** детализация P4 требует ВЫБОРА
механизма общего бюджета запросов к площадке; без подтверждённой семантики примитивов выбор
опирался бы на интуицию.

**Отношение к P1.3.** P1.3 (RAG-свод практик) остаётся в плане и НЕ переснимает то, что помечено
`RAG:✅` здесь: его собственный Scope Excluded прямо запрещает переснимать уже подтверждённое
(`phases/P1.md` P1.3). Здесь сняты ровно те факты, без которых нельзя было закрыть развилку
носителя бюджета при детализации P4; темы 4–5 P1.3 (детект чужой вёрстки, `spatie/laravel-health`)
не трогались вовсе — они питают P5.

---

## 1. `RateLimiter` — счётчик попыток поверх кэша

`Illuminate\Support\Facades\RateLimiter`: `attempt($key, $maxAttempts, $callback, $decaySeconds)`
возвращает `false`, когда попыток не осталось; ручной контур — `tooManyAttempts($key, $max)`,
`remaining()`, `increment($key, amount:)`, `availableIn($key)` (сколько секунд до освобождения),
`clear($key)`.
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, rate-limiting.md «Basic
Usage», «Manually Incrementing Attempts», https://github.com/laravel/docs/blob/13.x/rate-limiting.md)`

**Носитель состояния — кэш:** «the rate limiter utilizes your default application cache as defined
by the `default` key within your `cache` configuration file», отдельный стор задаётся ключом
`limiter` в `config/cache.php`.
`RAG:✅ 2026-08-06 (там же, rate-limiting.md «Cache Configuration»)`

**Вердикт:** применимо. Счётчик живёт в общем сторе, поэтому ОДИН ключ виден и queue-воркеру, и
artisan-процессу, и веб-запросу — это ровно то свойство, которого требует «общий бюджет на
площадку поверх нескольких процессов». **На что ложится у нас:** `config/cache.php` →
`'default' => env('CACHE_STORE', 'redis')` (файл, строка 20) — лимитер уже на Redis, отдельный
стор заводить не нужно; `config/queue.php` использует то же соединение Redis.

**Граница честности:** `attempt`/`increment` — счётчик в окне (fixed window), НЕ равномерная
раздача слотов внутри окна. Ровный темп внутри минуты обеспечивается паузой между запросами
(п. 2), а не самим счётчиком.

## 2. `Redis::throttle()` — воронка с ОЖИДАНИЕМ слота

Документированный примитив троттлинга под очередями:

```php
Redis::throttle('key')->block(0)->allow(1)->every(5)->then(
    fn () => /* слот получен */,
    fn () => /* слот не получен */ $this->release(5),
);
```

`block($seconds)` — сколько ждать слот, `allow(N)->every(S)` — ёмкость окна.
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, queues.md «Job Middleware»,
https://github.com/laravel/docs/blob/13.x/queues.md)`

**Вердикт:** применимо и предпочтительнее п. 1 для нашей раскладки — у нас нужна не «проверка,
можно ли», а «дождись своей очереди и иди»: точка исхода синхронна внутри процесса (цикл прогона,
job детальной карточки), и ждать слот там законно, пока ожидание укладывается в чекпоинт-бюджет.
**На что ложится у нас:** три точки исхода, где сегодня стоит `PoliteThrottle::wait()` —
`app/Services/Procurement/Eis/CsvSourceMethod.php` (листинг, строки ~84 и вкладки карточки ~187),
`app/Services/Procurement/DocumentDownloader.php` (~97).

## 3. Middleware `RateLimited` очереди — НЕПРИМЕНИМО у нас

`Illuminate\Queue\Middleware\RateLimited` / `RateLimitedWithRedis` отпускает job обратно в очередь
при исчерпании лимита. Прямая оговорка документации: «Releasing a rate limited job back onto the
queue will still increment the job's total number of `attempts`. You may wish to tune your `Tries`
and `MaxExceptions` attributes» — и для `WithoutOverlapping` та же оговорка сформулирована ещё
жёстче: «leaving `Tries` to 1 as it is by default would prevent any overlapping job from being
retried later».
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, queues.md «Rate Limiting»,
«Preventing Job Overlaps», https://github.com/laravel/docs/blob/13.x/queues.md)`

**Вердикт: НЕ применять.** У нас `tries = 1` во ВСЕХ supervisor'ах Horizon
(`config/horizon.php`), а объявлять `$tries`/`$backoff`/`retryUntil()` у job'ов
`app/Jobs/Procurement/**` запрещено арх-сторожем `tests/Unit/Architecture/JobRetryContractGuardTest.php`.
При таком контуре отпущенный лимитером job не будет запущен повторно — он просто пропадёт.
Отрицательный результат здесь — полноценный результат: он закрывает «очевидный» вариант
реализации бюджета до того, как его начнут писать.

## 4. `WithoutOverlapping(...)->shared()` — общий ключ между КЛАССАМИ job'ов

По умолчанию middleware разводит только job'ы одного класса; `shared()` распространяет ключ на
разные классы. Требует кэш-драйвер с атомарными локами (`redis` в их числе).
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, queues.md «Sharing Lock Keys
Across Job Classes», https://github.com/laravel/docs/blob/13.x/queues.md)`

**Вердикт:** применимо, но решает ДРУГУЮ задачу — взаимное исключение (один job за раз), а не
темп. Бюджет «N запросов в минуту при K параллельных потребителях» через лок не выражается.
**На что ложится у нас:** не используется в P4; фиксируется, чтобы следующая сессия не приняла
лок за лимитер.

## 5. Планировщик: именование, `withoutOverlapping`, очередь

`Schedule::job(new Job, 'queue', 'connection')` диспатчит job из расписания; `->name()` обязателен
для `onOneServer()` и для замыканий; `withoutOverlapping()` — штатная защита тика от наложения.
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, scheduling.md «Scheduling
Queued Jobs», «Naming Single Server Jobs», https://github.com/laravel/docs/blob/13.x/scheduling.md)`

**Вердикт:** применимо к P4.5 (подача детальной стадии пачками). **На что ложится у нас:**
`routes/console.php` уже использует этот приём для `pipeline:dispatch-backlog`
(`Schedule::call(...)->name('pipeline:dispatch-backlog')->everyMinute()->withoutOverlapping()`) —
подача детальной стадии обязана следовать тому же канону, а не изобретать свой.

## 6. `ThrottlesExceptionsWithRedis` — троттлинг ПОСЛЕ серии исключений

`new ThrottlesExceptionsWithRedis(10, 10 * 60)` — размыкатель на уровне job'а.
`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, queues.md «Throttling
Exceptions With Redis», https://github.com/laravel/docs/blob/13.x/queues.md)`

**Вердикт:** не применять. Ту же роль у нас уже играет собственный двухуровневый circuit breaker
прокси (`app/Services/Procurement/Proxy/ProxyCircuitBreaker.php`), и он богаче (уровни
`global`/`target`, экспоненциальный cooldown, half-open проба). Второй размыкатель поверх первого —
вторая поверхность отладки при инциденте.

## 7. Что НЕ подтвердилось / не искалось

- Готового «общего бюджета на внешний хост поверх нескольких job-классов И синхронной команды» во
  фреймворке НЕТ — ни одного класса с такой семантикой в документации 13.x не найдено. Собственный
  сервис поверх п. 2 — не изобретение велосипеда, а отсутствие штатного носителя. `RAG:✅
  2026-08-06 (перебор разделов queues.md «Job Middleware», rate-limiting.md целиком)`
- Справедливость (fairness) раздачи слотов между потребителями одного ключа в документации не
  описана и не обещана — `Redis::throttle(...)->block()` крутит попытки, а не строит очередь.
  Практическое следствие для P4.4 записано отдельной границей честности. `[UNVERIFIED]` в части
  «как именно ведёт себя раздача под конкуренцией» — проверяется только боевым прогоном.
- Темы 4 и 5 свода P1.3 (детект чужой вёрстки; `spatie/laravel-health`) здесь НЕ трогались.
