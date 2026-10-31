<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportProductsFromCsv extends Command
{
    protected $signature = 'product:import {merchant : The Merchant Id} {file}';
    protected $description = 'Import products from CSV';
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $merchantId = $this->argument('merchant');

        if(!ctype_digit($merchantId)) {
            $this->error("Merchant Id must be an number: {$merchantId}");
            return self::FAILURE;
        }

        $file = basename($this->argument('file')); // get only filename to prevent traversal attack
        $path = config('filesystems.imports.path') . '/' . $file;
        $disk = Storage::disk(config('filesystems.imports.disk'));

        // Check the File exists or not
        if(!$disk->exists($path)) {
            $this->error("File {$file} does not exists");
            return self::FAILURE;
        }

        // READ with Steam
        $stream = $disk->readStream($path);

        if($stream === null){
            $this->error("File {$file} is not readable");
            return self::FAILURE;
        }

        $lineNumber = 0;

        try{
            while(fgetcsv($stream, escape: '') !== false){
                $lineNumber++;
            }
        } finally {
            fclose($stream);
        }

        $this->info("Read {$lineNumber} lines}");
        return self::SUCCESS;
    }
}
