<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum DocumentFormat: string
{
    case Pdf = 'pdf';
    case Xls = 'xls';
    case Xlsx = 'xlsx';
    case Doc = 'doc';
    case Docx = 'docx';
    case Rtf = 'rtf';
    case Odt = 'odt';
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Tiff = 'tiff';
    case Bmp = 'bmp';
    case Webp = 'webp';
    case Gsfx = 'gsfx';
    case Zip = 'zip';
    case SevenZip = '7z';
    case Rar = 'rar';
}
