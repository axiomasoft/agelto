<?php

declare(strict_types=1);

namespace Platform\Models\Parser;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ParserCrawlerUrl extends Model
{
    use HasFactory;

    protected $table = 'plt_parser_crawler_urls';

    protected $fillable = [];
}
