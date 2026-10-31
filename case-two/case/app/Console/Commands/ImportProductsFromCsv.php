<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportProductsFromCsv extends Command implements Isolatable
{
    protected $signature = 'product:import {merchant : The Merchant Id} {file}';
    protected $description = 'Import products from CSV';

    private const int BATCH_SIZE = 500;

    public function isolatableId(): string
    {
        return $this->argument('merchant');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {

            $merchantId = $this->getMerchantId();
            $stream = $this->openImportStream();

        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }


        $lineNumber = 0;
        $imported = 0;
        $batch = [];
        $errors = [];

        try {
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $lineNumber++;

                if ($row === [null]) {
                    continue; // blank line: not an error, just skip
                }

                $error = $this->validateRow($row);

                if ($error !== null) {
                    $errors[] = ['line' => $lineNumber, 'reason' => $error]; // to collect the errors but all of them goes to RAM.
                    continue;
                }

                $batch[] = $this->mapRow($row, $merchantId);

                if (count($batch) === self::BATCH_SIZE) {
                    $this->saveProducts($batch);
                    $imported += count($batch);
                    $batch = [];
                }


            }

            // IF the list shorter than 500 list
            if ($batch != []) {
                $this->saveProducts($batch); // flush the last, not-full batch
                $imported += count($batch);
            }

        } finally {
            fclose($stream);
        }

        $context = [
            'merchant_id' => $merchantId,
            'imported' => $imported,
            'skipped' => count($errors),
        ];

        if ($imported === 0) {
            $this->error('No products were imported.');
            Log::error('Product import failed: nothing imported', $context);
            return self::FAILURE;
        }

        if($errors !== []){
            $this->warn('Skipped rows: ' . count($errors));
            $this->table(['Line', 'Reason'], $errors);
        }

        $this->info("Read {$lineNumber} lines, imported {$imported} products");
        return self::SUCCESS;
    }

    /**
     * @param array $row
     */
    private function validateRow(array $row): ?string
    {
        if (count($row) !== 5) {
            return 'expected 5 columns, got ' . count($row);
        }

        [$name, $color, $ean, $price, $quantity] = array_map(trim(...), $row);

        if ($name === '') {
            return "name is empty";
        }

        if (!ctype_digit($ean) || strlen($ean) < 8 || strlen($ean) > 14) {
            return "invalid ean '{$ean}'";
        }

        if (!is_numeric($price) || (float)$price < 0) {
            return "invalid price '{$price}'";
        }

        if (!ctype_digit($quantity)) {
            return "invalid quantity '{$quantity}'";
        }

        return null;
    }

    /**
     * Opens the merchant's CSV file as a stream (local disk today, S3 later).
     * @return resource
     */
    private function openImportStream()
    {
        $file = basename($this->argument('file')); // get only filename to prevent traversal attack
        $path = config('filesystems.imports_path') . '/' . $file;

        // Disk read from 'local' today. S3 is possible check the config filesystem.php
        $disk = Storage::disk(config('filesystems.imports_disk'));

        // Check the File exists or not
        if (!$disk->exists($path)) {
            throw new RuntimeException("File {$file} does not exists.");
        }

        // If it is null throw and Exception.
        return $disk->readStream($path)
            ?? throw new RuntimeException("File {$file} is not readable. Could not opne it");
    }

    /*
     * Get Merchant id and check exist in database or not
     */
    private function getMerchantId(): int
    {
        $merchantId = $this->argument('merchant');

        if (!ctype_digit($merchantId)) {
            throw new RuntimeException("Merchant Id must be an integer: {$merchantId}");
        }

        $merchantId = (int)$merchantId;

        if (!DB::table('merchants')->where('id', $merchantId)->exists()) {
            throw new RuntimeException("Merchant Id {$merchantId} does not exists");
        }

        return $merchantId;

    }

    /**
     * @param array $row
     */
    private function mapRow(array $row, int $merchantId): array
    {
        [$name, $color, $ean, $price, $quantity] = array_map(trim(...), $row);

        $now = now(); // one time

        return [
            'merchant_id' => $merchantId,
            'name' => $name,
            'color' => $color === '' ? null : $color,
            'ean' => $ean,
            'price' => $price,
            'quantity' => (int)$quantity,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function saveProducts(array $products): void
    {
        DB::table('products')->upsert(
            $products,
            ['merchant_id', 'ean'],
            ['name', 'color', 'price', 'quantity', 'updated_at']
        );
    }
}
