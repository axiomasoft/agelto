# RAG по системным инструментам фазы P3 (2026-07-26)

> Слой 1 (`plan-protocol` §16): внешние факты, снятые при детализации P3. Проверялись через
> Perplexity Web 2026-07-26; вердикты ниже — вход D-записей фазы. Всё, что касается устройства
> нашего репозитория, помечено `RAG:—` в самих D-записях и здесь не дублируется.

## 1. Распаковка RAR: лицензионная развилка Debian

**Запрос:** «Debian package to extract RAR archives 2026: does 7zip package (7zz) support RAR
extraction, vs unrar-free vs unar? Which is in Debian main (non-free issue)?»

**Вердикт (RAG:✅ 2026-07-26):**

| Инструмент | Репозиторий | Покрытие RAR | Замечание |
|:--|:--|:--|:--|
| `7zz` (пакет `7zip`) | main | **нет** — код unRAR выпилен | RAR работает только с `7zip-rar` из **non-free** |
| `unrar` (официальный) | **non-free** | полное, включая RAR5 | требует подключения non-free в образ |
| `unrar-free` | main | базовое, ограниченнее официального | самый маленький выделенный распаковщик |
| `unar` / `lsar` | main | RAR в т.ч. | универсальный распаковщик, main-only |

**Следствие для P3.4:** `7zz` из main закрывает 7z, но НЕ RAR; чтобы остаться на main —
`unar`; официальный `unrar` = подключение non-free в базовый образ проекта.

**Границы факта:** проверялось наличие/лицензия пакетов, а не качество распаковки конкретных
RAR5-архивов закупок — это проверяется только на реальных файлах, в корпусе `estimates/files`
архивов нет вообще (recon §1: `xlsx`/`pdf`/`ini`/`xls`/`docx`/`jpg`/`gsfx`).

Источники: обсуждения Debian-упаковки unRAR (github.com/orgs/Homebrew/discussions/3355,
r/debian «unrar confusion»).

## 2. OCR русских сканов сметных таблиц

**Запрос:** «OCR for Russian scanned construction estimate tables 2026: tesseract-ocr rus vs
ocrmypdf vs PaddleOCR / Surya table structure recognition — Docker install size and table
structure accuracy»

**Вердикт (RAG:✅ 2026-07-26):**

- **Tesseract + `rus`** — самый лёгкий вариант (порядка ~10 МБ зависимостей), но это
  ТЕКСТОВЫЙ экстрактор: структуру таблицы восстанавливает частично. Языковой пакет решает
  распознавание символов, но не реконструкцию строк/колонок.
- **OCRmyPDF** — обвязка над Tesseract: добавляет текстовый слой в PDF (наш прямой сценарий —
  скан-PDF → PDF с текстом), но сам по себе таблицы не парсит; требователен к RAM/CPU/temp.
- **PaddleOCR** — заметно лучше на шумных сканах, но ~500 МБ стека; текст читает надёжнее,
  структуру таблицы всё равно теряет без отдельной table-detection логики.
- **Document-VLM/Surya-класс** — таблицы держит лучше всех, но это ~6 ГБ весов + torch.

**Ключевой вывод для OQ-1:** ни один локальный OCR не даёт «таблицу как таблицу» бесплатно;
выбор идёт не между «плохо/хорошо», а между *текстовым слоем* (Tesseract/OCRmyPDF, дёшево) и
*отдельным конвейером распознавания структуры* (PaddleOCR+постобработка / VLM, дорого). Это
ровно та развилка, которую OQ-1 держал открытой.

Источники: ocrmypdf.readthedocs.io/en/v14.4.0/docker.html; сравнение OCR-движков по структуре
таблиц (linkedin-разбор, 2026-06).

## 3. LibreOffice headless в Docker

**Запрос:** «LibreOffice headless soffice --convert-to pdf in Docker 2026: apt package size for
libreoffice-calc/writer no-install-recommends, concurrency issue single user profile,
-env:UserInstallation workaround»

**Вердикт (RAG:✅ 2026-07-26):**

- Размер (Debian bookworm, amd64): `libreoffice-calc` ≈ 7.2 МБ загрузки / 30.6 МБ
  установленного, `libreoffice-writer` ≈ 9.0 МБ / 38.2 МБ — но обе тянут `libreoffice-core` +
  `libreoffice-common` + UNO-рантайм, поэтому реальный прирост образа заметно больше суммы
  этих двух чисел. `--no-install-recommends` обязателен; ставить мета-пакет `libreoffice` —
  нет.
- **Конкурентность — реальная ловушка:** параллельные `soffice --headless --convert-to`
  дерутся за ОДИН профиль пользователя и его lock-файл, что даёт молчаливые сбои и
  недетерминированное поведение. Канонический обход — свой профиль на каждый вызов:
  `-env:UserInstallation=file:///tmp/lo-profile-<uniq>` с удалением каталога после процесса.
  Для очереди Horizon (несколько воркеров на одном контейнере) это обязательное требование,
  а не «желательно».

Источник: thomastsoi.com/blog/a-real-world-concurrency-bug-caused-by-libreoffice-profile-locking.

**Сверка с нашей спекой:** `docs/specs/v2/08-estimate-extraction.md` §5.3 уже называла
LibreOffice рычагом с пометкой «вызовы сериализуются (не параллелится в headless-режиме)» —
RAG уточняет: параллелить МОЖНО при изолированном профиле на вызов; сериализация — не
единственный вариант.
