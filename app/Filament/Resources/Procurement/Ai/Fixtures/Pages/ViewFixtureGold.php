<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Pages;

use App\Dto\Procurement\Gold\GoldDocumentView;
use App\Exceptions\Procurement\Ai\GoldFileUnreadable;
use App\Exceptions\Procurement\Ai\ManifestUnreadable;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\GoldContentSchema;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Gold\GoldDocumentReader;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class ViewFixtureGold extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = FixtureResource::class;

    protected string $view = 'filament.resources.fixtures.pages.view-fixture-gold';

    private bool $documentResolved = false;

    private ?GoldDocumentView $document = null;

    private ?string $readError = null;

    public function getTitle(): string
    {
        return 'Золото эталона';
    }

    public function table(Table $table): Table
    {
        $document = $this->goldDocument();

        return $document === null
            ? $table->records(fn (): array => [])
            : GoldContentSchema::table($table, $document);
    }

    public function goldDocument(): ?GoldDocumentView
    {
        if ($this->documentResolved) {
            return $this->document;
        }

        $this->documentResolved = true;

        try {
            return $this->document = app(GoldDocumentReader::class)->read($this->fixture());
        } catch (GoldFileUnreadable $exception) {
            $this->readError = $exception->getMessage();

            return null;
        }
    }

    public function emptyStateMessage(): string
    {
        if ($this->readError !== null) {
            return $this->readError;
        }

        if ($this->manifestPromisesGold()) {
            return 'Манифест обещает gold-файл, но он отсутствует на диске.';
        }

        return 'Золота нет. Соберите его командой php artisan estimate:gold:build.';
    }

    private function fixture(): Fixture
    {
        /** @var Fixture $fixture */
        $fixture = $this->getRecord();

        return $fixture;
    }

    private function manifestPromisesGold(): bool
    {
        try {
            $manifestPath = config('ai.fixtures.manifest_path');

            if (! is_string($manifestPath) || $manifestPath === '') {
                return false;
            }

            $manifest = app(FixtureManifestReader::class)->read($manifestPath);
        } catch (ManifestUnreadable) {
            return false;
        }

        foreach ($manifest['fixtures'] as $entry) {
            if (is_array($entry) && ($entry['code'] ?? null) === $this->fixture()->code) {
                return filled($entry['gold_path'] ?? null);
            }
        }

        return false;
    }
}
