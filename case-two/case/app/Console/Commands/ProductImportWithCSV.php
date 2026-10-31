<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProductImportWithCSV extends Command
{
    protected $signature = 'product:import {file}';
    protected $description = 'Import products via CSV';
    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
    }
}
