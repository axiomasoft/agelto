<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldDocumentView;
use App\Exceptions\Procurement\Ai\GoldFileUnreadable;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/** Читает текущее содержимое gold-файла, не материализуя его в БД и не кэшируя между запросами. */
final readonly class GoldDocumentReader
{
    public function read(Fixture $fixture): ?GoldDocumentView
    {
        $path = $this->pathFor($fixture->code);

        if (! is_file($path)) {
            return null;
        }

        try {
            $gold = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoldFileUnreadable("Gold-файл не распарсен: {$exception->getMessage()}");
        }

        if (! is_array($gold) || ! is_array($gold['estimates'] ?? null)) {
            throw new GoldFileUnreadable('Ожидается объект вида {"estimates": [...]}');
        }

        foreach (array_values($gold['estimates']) as $index => $estimate) {
            if (! is_array($estimate)) {
                throw new GoldFileUnreadable("estimates.{$index}: ожидается объект");
            }
        }

        try {
            return GoldDocumentView::fromPayload($gold);
        } catch (ValidationException|CannotCreateData $exception) {
            throw new GoldFileUnreadable("Gold-файл не соответствует контракту: {$exception->getMessage()}");
        }
    }

    private function pathFor(string $code): string
    {
        if (preg_match(Fixture::CODE_PATTERN, $code) !== 1) {
            throw new GoldFileUnreadable("Отказ чтения: небезопасный код эталона «{$code}».");
        }

        $directory = realpath((string) config('ai.fixtures.gold_dir'));

        if ($directory === false || ! is_dir($directory)) {
            throw new GoldFileUnreadable('Каталог gold-файлов недоступен.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.$code.'.gold.json';
        $resolvedPath = realpath($path);

        if ($resolvedPath !== false && ! str_starts_with($resolvedPath, $directory.DIRECTORY_SEPARATOR)) {
            throw new GoldFileUnreadable('Отказ чтения: путь gold-файла выходит за разрешённый каталог.');
        }

        return $path;
    }
}
