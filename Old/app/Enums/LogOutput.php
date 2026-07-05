<?php

namespace App\Enums;

enum LogOutput: string
{
    case Full = 'full';       // И в лог, и в консоль
    case File = 'file';       // Только в файл (стандартный Log::info)
    case Console = 'console'; // Только в консоль (STDOUT)
}
