# Руководство по разработке

## Создание нового краулера
Для добавления новой площадки (например, 'TenderPro'):
1. Создайте модуль `app/Modules/TenderPro`.
2. Настройте namespace `TenderPro\` в `composer.json`.
3. Создайте класс `TenderProCrawlingService`, наследуя `Platform\Crawling\AbstractCrawlingService`.

## Работа с данными (DTO)
Запрещено передавать сырые массивы. Используйте `Platform\Dto\Crawling\LinkDto`.

```php
// Пример в CrawlingService
$dto = new LinkDto(
    regNumber: $item['id'],
    detailUrl: $item['link'],
    publishedAt: Carbon::parse($item['date'])
);

$this->storeLink($crawler, $dto);
