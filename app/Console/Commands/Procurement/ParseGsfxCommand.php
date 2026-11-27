<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement;

use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[Description('Parses bounded GSFX core files in an isolated PHP process')]
#[AsCommand(name: 'procurement:parse-gsfx')]
#[Signature('procurement:parse-gsfx {properties} {data} {output}')]
final class ParseGsfxCommand extends Command
{
    public function handle(GsfxReader $reader): int
    {
        $properties = (string) file_get_contents((string) $this->argument('properties'));
        $data = (string) file_get_contents((string) $this->argument('data'));
        $payload = json_encode($reader->parse($properties, $data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents((string) $this->argument('output'), $payload) === false) {
            throw new \RuntimeException('Cannot write parsed GSFX payload.');
        }

        return self::SUCCESS;
    }
}
