<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum CrawlerPermission: string
{
    case ViewAny = 'crawler.view_any';
    case View = 'crawler.view';
    case Create = 'crawler.create';
    case Update = 'crawler.update';
    case Delete = 'crawler.delete';
    case Restore = 'crawler.restore';
    case ForceDelete = 'crawler.force_delete';
    case Replicate = 'crawler.replicate';
    case Reorder = 'crawler.reorder';
}
