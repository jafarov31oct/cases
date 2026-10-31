<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportProductsFromCsv extends Command
{
    protected $signature = 'product:import {merchant : The Merchant Id} {file}';
    protected $description = 'Import products from CSV';
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $merchantId = $this->getMerchantId()

        $file = basename($this->argument('file')); // get only filename to prevent traversal attack
        $path = config('filesystems.imports.path') . '/' . $file;
        $disk = Storage::disk(config('filesystems.imports.disk'));

        // Check the File exists or not
        if(!$disk->exists($path)) {
            $this->error("File {$file} does not exists");
            return self::FAILURE;
        }

         // Read CSV using a stream
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

    /*
     * Get Merchant id and check exist in database or not
     */
    private function getMerchantId(): int
    {
        $merchantId = $this->argument('merchant');

        if(!ctype_digit($merchantId)) {
            throw new RuntimeException("Merchant Id must be an integer: {$merchantId}");
        }

        $merchantId = (int)$merchantId;

        if(!DB::table('merchants')->where('id', $merchantId)->exists()) {
            throw new RuntimeException("Merchant Id {$merchantId} does not exists");
        }

        return $merchantId;

    }
}
