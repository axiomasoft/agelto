<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;


class EisSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:eis-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = Http::get('http://host.docker.internal:8090/proxy.php', [
            'service' => 'api'
        ]);

        // Вместо json() используем body() для сырого ответа
        $body = $response->body();
        dd($body);
    }
}
