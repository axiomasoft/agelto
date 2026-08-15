# Трассировка требований владельца

Источники: brief/00-brief.md, brief/01-refinements.md и
findings/bootstrap-recon-2026-08-09.md. Внешних RAG-источников на bootstrap-проходе нет.

| Мысль владельца | Адрес |
|:--|:--|
| Сравнить Sova, Vintera/CMS, Dterema и актуальные внешние практики | P0.1–P0.5 |
| Текущий Makefile — requirements, можно переписать | D10 · P1 |
| Dev code change без rebuild | P0.3 · P1.2 · P1.5 |
| Production image/deploy topology | P0.3 · P1.3–P1.5 |
| Готовность к Vite/frontend | P0.3 · P1.4 |
| Запуски от имени агентов | P0.3–P0.4 · P1.1–P1.2 · P11.3 |
| local/vendors и отдельный clone AzGuard | D6 · P2.1 · P2.4 |
| Генерация env/passwords и безопасные Docker secrets | P2.2–P2.3 |
| Laravel Doctor | P0.4 · P2.6 · P3.3 |
| Livewire asset 404 на чистом проекте | P3.1–P3.4 |
| Закрытая production-документация вне public | D7 · P4 |
| Путь profile proxy → default proxy → direct | D8 · P5 |
| PLAYWRIGHT_MCP_PROXY_SERVER не смешивать с runtime | D8 · P5.1/P5.5 |
| Архив создаёт дочерние file records и запускает обработку | P6.3–P6.5 |
| Registry handlers и terminal reason для unsupported | P6.1–P6.2/P6.6 |
| Тип документа, одна/несколько смет, unknown/null | P7.1/P7.4/P7.6 |
| Summary даже для не-estimate результата | P7.2–P7.4 |
| Tags/anchors | P7.2/P7.5 |
| Лёгкое добавление platforms/file handlers/schemas | P6.6 · P8.1–P8.2/P8.5 |
| Гибкая status mapping без жёсткого ФЗ | P8.3–P8.4 |
| Русское и понятное Filament меню, AzGuard вниз | P9.1 |
| Sections/helper text по всем ресурсам | P9.3/P9.5 |
| UUID hidden, wrapping, responsive tables | P9.4 |
| Enum labels и 1 → Да | P9.2 |
| Современный PHP, patterns, naming, duplication, security | P10 |
| Pest 5 fast engine и CI | P11 |
| Пустой Ubuntu, deploy user, SSH/Git clone | P12.1–P12.2 |
| Seeds, admin, assets, docs, doctor в первом deploy | P12.3–P12.4 |
| Subsequent deploy/rollback/backup | P12.3–P12.5 |
| Полный fresh/prod rehearsal и DB integrity | D9 · P12.4–P12.7 |
| Предыдущий CRAWL-HARDEN архивный, не paused | D12 (supersedes D3) |
| Финальный RED-remediation P2 закрывает F1–F8 одним обязательным real carrier | D70 · P2.7 |
| После P2.7 новых design-прогонов не делать; close/audit и bounded fixes исполнять по frozen acceptance | D70 · brief/01-refinements.md |

## Синтез порядка

P0 сначала замораживает внешние и сравнительные факты. Инфраструктурные carriers P1–P4 идут
до доменных изменений, чтобы каждый последующий item имел воспроизводимые test/deploy команды.
P5–P8 закрывают proxy/document/extension contracts. P9 применяет итоговую предметную структуру
к Filament, P10 выполняет сквозной quality/security pass, P11 закрепляет тестовую и CI
обратную связь. P12 является единственным финальным production gate и не заменяет проверки
ранних фаз.
