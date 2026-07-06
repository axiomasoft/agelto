<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('crawler.name')
                    ->label('Crawler'),
                TextEntry::make('status')
                    ->badge()
                    ->columnSpanFull(),
                TextEntry::make('started_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('finished_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_heartbeat_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('attempts')
                    ->numeric(),
                TextEntry::make('last_error')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('last_error_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('next_retry_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('failed_permanently')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
