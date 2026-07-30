<?php

declare(strict_types=1);

namespace App\Guards\Admin\Permissions;

enum FixturePermission: string
{
    case ViewAny = 'fixture.view_any';
    case View = 'fixture.view';
    case Create = 'fixture.create';
    case Update = 'fixture.update';
    case Delete = 'fixture.delete';
    case Restore = 'fixture.restore';
    case ForceDelete = 'fixture.force_delete';
    case Replicate = 'fixture.replicate';
    case Reorder = 'fixture.reorder';
}
