<?php

namespace Eis\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class EisMapCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'eis:map';

    /**
     * The console command description.
     */
    protected $description = 'Command description.';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        dd(7);
    }
}
