<?php

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Filament\Resources\Procurement\Crawler\Proxies\Schemas\ProxyForm;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CrawlerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('registry_id')
                    ->relationship('registry', 'name')
                    ->required(),
                Textarea::make('name')
                    ->required()
                    ->columnSpanFull(),
                Select::make('mode')
                    ->options(CrawlerMode::class)
                    ->required(),
                Select::make('source_method')
                    ->options(SourceMethod::class)
                    ->required(),
                Select::make('fallback_method')
                    ->options(SourceMethod::class),
                KeyValue::make('filter')
                    ->columnSpanFull(),
                Textarea::make('schedule')
                    ->columnSpanFull(),
                KeyValue::make('options')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('use_proxy')
                    ->required(),
                Select::make('proxy_country')
                    ->options(ProxyForm::COUNTRIES),
            ]);
    }
}
