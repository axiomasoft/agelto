---
name: eis-recon
description: "Живая разведка госплощадок (zakupki.gov.ru / ЕИС) через Playwright MCP: обязательные настройки запуска — RU-прокси и TLS-цепочка Минцифры, рабочий рецепт снятия фактов, карта эндпоинтов, куда класть результат. Активировать при любой задаче «сходить на ЕИС и проверить», разборе фильтров/вёрстки площадки, ошибке net::ERR_CERT_AUTHORITY_INVALID, а также перед правкой EisCsvUrlBuilder/EisSearchFilter/парсеров карточки."
paths:
  - "app/Services/Procurement/Eis/**"
  - "app/Dto/Procurement/Crawler/**"
  - "scripts/playwright-mcp.sh"
---

# Разведка ЕИС через Playwright MCP

Проектный скилл (не managed, правь свободно). Отвечает на один вопрос: **как физически
сходить на zakupki.gov.ru из агентской сессии и снять факт, которому можно верить.**

Что это НЕ покрывает: устройство пайплайна (`procurement-pipeline-dev`), тексты промтов
(`prompt-engineering-agelto`), извлечение смет (`estimate-extraction-dev`).

## 1) Два обязательных условия канала

Без обоих разведка либо не запустится, либо даст факт, снятый не оттуда.

### 1.1 RU-прокси — обязателен

ЕИС не отвечает на прямые запросы с не-российских адресов: соединение **виснет до таймаута**
(проверено 2026-08-06: `curl` без прокси — timeout 45 с, без ошибки и без ответа). Прокси
задаётся в `.env` ключом `PLAYWRIGHT_MCP_PROXY_SERVER` и подставляется launcher-ом
`scripts/playwright-mcp.sh`.

**Первым запросом сессии проверяй egress**, а не полагайся на конфиг:

```js
await ctx.request.get('https://ipinfo.io/json')  // ожидаем country: "RU"
```

Разведка, начатая без этой проверки, недействительна: факт мог быть снят с другого гео,
а ЕИС отдаёт разную выдачу.

### 1.2 TLS: цепочка Минцифры

ЕИС отдаёт сертификат, подписанный Russian Trusted CA (корень 2022 + Sub CA 2024). В
дефолтном наборе корней Chromium его нет → `browser_navigate` падает с
`net::ERR_CERT_AUTHORITY_INVALID`.

**Почему репозиторный бандл не помогает браузеру.** `docker/php/certs/russian-trusted-ca.pem`
существует и используется боевым клиентом, но Chromium читает системный NSS-store, а
`NODE_EXTRA_CA_CERTS` влияет только на Node — не на сетевой стек браузера.

**Решение — `ignoreHTTPSErrors` в контексте разведки.** `scripts/playwright-mcp.sh` c
2026-08-06 пишет его в конфиг сам (`browser.contextOptions.ignoreHTTPSErrors`), поэтому
после перезапуска MCP-сервера штатная навигация работает.

**Если MCP-сервер поднят СТАРЫМ launcher-ом** (перезапускать не хочется) — поднимай
собственный контекст внутри `browser_run_code_unsafe`, см. §2. Это же единственный путь,
когда нужен контекст с особыми опциями.

> **Граница послабления.** `ignoreHTTPSErrors` живёт ТОЛЬКО в launcher-е разведки.
> Боевой HTTP-клиент проверку не ослабляет: узкая опция `verify` с бандлом по карте
> `crawler.tls.bundles`, единственный носитель правила — `SourceTlsTrust`;
> `verify => false` запрещён абсолютно и закреплён тестом. Вносить бандл в системный трест
> образа тоже запрещено: доверие российскому УЦ получил бы весь контейнер, включая
> исходящий канал к LLM-провайдеру (D125 плана `2026.07.31-SYS-INTEGRITY`).

## 2) Рабочий рецепт (копируется как есть)

```js
// mcp__playwright__browser_run_code_unsafe
async (page) => {
  const ctx = await page.context().browser().newContext({
    ignoreHTTPSErrors: true,                       // §1.2
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
             + '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  });
  const ip = await ctx.request.get('https://ipinfo.io/json');   // §1.1 — ПЕРВЫМ делом
  const p = await ctx.newPage();
  await p.goto('https://zakupki.gov.ru/epz/order/extendedsearch/results.html?fz44=on&recordsPerPage=_10',
               { waitUntil: 'domcontentloaded', timeout: 90000 });
  // ... снятие фактов ...
  await ctx.close();
  return JSON.stringify({ egress: await ip.json(), /* ... */ }, null, 1);
}
```

**Грабли песочницы `browser_run_code_unsafe`** (проверено 2026-08-06):

| Не работает | Почему | Что вместо |
|---|---|---|
| `setTimeout` | нет в области видимости серверного процесса | `await page.waitForTimeout(ms)` — снаружи; `setTimeout` — только ВНУТРИ `page.evaluate` |
| `TextDecoder` | там же | делать `fetch` внутри `page.evaluate` (в браузере он есть) |
| `element.innerHTML = <html модалки>` | скрипты вставленного HTML не выполняются | открывать модалку кликом по реальному якорю |
| наивный `text.split('\n')` для CSV ЕИС | в полях есть переводы строк внутри кавычек | полноценный парсер с учётом `"` и `""` |

**windows-1251.** Ответы CSV-выгрузки — `windows-1251`. Декодировать только внутри страницы:

```js
await p.evaluate(async () => {
  const r = await fetch('/epz/order/orderCsvSettings/download.html?...', { credentials: 'omit' });
  return new TextDecoder('windows-1251').decode(await r.arrayBuffer());
});
```

**Вежливость.** Между запросами — пауза ≥1.5 с (боевой троттл — `crawler.throttle`,
1000–4000 мс). Разведка не имеет права быть агрессивнее боевого клиента.

## 3) Карта эндпоинтов (снято 2026-08-06)

| Что | URL | Заметка |
|---|---|---|
| SSR-листинг | `/epz/order/extendedsearch/results.html` | несёт `totalCount` в разметке |
| CSV-выгрузка | `/epz/order/orderCsvSettings/download.html` | `windows-1251`, сессия не нужна, блок 500 строк |
| Модалка ОКПД2 | `/epz/nsi/okpd2/openModal.html?...&selectedCodes=` | дерево dynatree в `#tree`, кнопка `#btn-primary.okpd2SelectButton` |
| Дети узла NSI | `/epz/api/nsi/okpd2/children.html?parentId=<id>` | обход справочника без UI |
| Карточка закупки | `/epz/order/notice/<procedureUrlType>/view/<tab>.html?regNumber=<N>` | 4 вкладки |

**`totalCount` без браузера** — из SSR-разметки:

```js
html.match(/downloadCsv\('([^']*)',\s*'(\d+)'\)/)   // [1] — query выгрузки, [2] — число найденного
```

Это же главный измерительный инструмент: **действие фильтра доказывается изменением
`totalCount`**, а не тем, что параметр «принялся» (ЕИС молча игнорирует неизвестные ключи —
`zzzNoSuchParam` не меняет выдачу, статус 200).

**Работа с dynatree** (дерево классификаторов):

```js
const tree = window.jQuery('#tree').dynatree('getTree');
const roots = tree.getRoot().getChildren();          // разделы A…U
const f = roots.find(n => n.data.title.startsWith('F'));
f.expand(true);                                       // lazy-load детей
// n.data.key — ВНУТРЕННИЙ id НСИ, n.data.title — «43: Работы строительные специализированные»
```

## 4) Дисциплина фиксации результата

1. **Сырьё — в `plans/<PLAN-ID>/artifacts/*.json`** (машинная выжимка) и
   `plans/<PLAN-ID>/findings/*.md` (человеческий разбор) — В ТОМ ЖЕ ходе, когда снято.
2. Каждый факт несёт **метку исхода**: `подтверждено` / `изменилось` / `не установлено`.
   «Не установлено» — честный статус, а не повод додумать.
3. Каждый факт несёт **чем снят** — конкретный URL и наблюдаемое следствие
   (`totalCount 22761 → 1233`), а не «проверено».
4. Метка `RAG:✅ <дата> (живая разведка zakupki.gov.ru, Playwright MCP; артефакт <файл>)`
   в плане/докe ставится только на факты со статусом «подтверждено».
5. **Тесты на площадку не ходят никогда.** Снятое превращается в фикстуру
   (`tests/Fixtures/**`), тест работает по ней.

## 5) Быстрая диагностика

| Симптом | Причина | Действие |
|---|---|---|
| `net::ERR_CERT_AUTHORITY_INVALID` | цепочка Минцифры, §1.2 | перезапустить MCP (launcher уже чинит) либо свой контекст с `ignoreHTTPSErrors` |
| запрос виснет до таймаута | нет RU-прокси, §1.1 | проверить `PLAYWRIGHT_MCP_PROXY_SERVER` в `.env`, затем egress |
| `net::ERR_INVALID_AUTH_CREDENTIALS` | креды прокси в URL — Chromium их игнорирует | launcher уже раскладывает их на `server`/`username`/`password`; проверить, что MCP поднят именно им |
| фильтр «не работает» | параметр неизвестен серверу — он молча игнорируется | сравнивать `totalCount` с базой и с заведомо мусорным значением параметра |
| выдача «поехала» между двумя одинаковыми запросами | `sortBy=UPDATE_DATE` — голова блока сменяется | фиксировать время снятия; сверять множествами, а не позициями |
