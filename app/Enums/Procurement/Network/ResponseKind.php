<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Request profile объявляет вид тела: текст декодируется, binary никогда не проходит text decoder. */
enum ResponseKind: string
{
    case Text = 'text';
    case Binary = 'binary';
}
