<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ImportProductsFromCsv extends Command
{
    protected $signature = 'product:import {file}';
    protected $description = 'Import products from CSV';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
    }
}
