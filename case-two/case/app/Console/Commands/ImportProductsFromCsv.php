<?php
declare(strict_types=1);

/**
 * example $schedule->command('product:import 1 merchant_01.csv --isolated')->everyFiveMinutes();
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;



/**
 * @phpstan-type ProductRow array{
 *     merchant_id: int,
 *     name: string,
 *     color: string|null,
 *     ean: string,
 *     price: string,
 *     quantity: string,
 *     created_at: Carbon,
 *     updated_at: Carbon
 *     }
 */
class ImportProductsFromCsv extends Command implements Isolatable
{
    protected $signature = 'product:import {merchant : The Merchant Id} {file}';
    protected $description = 'Import products from CSV';

    private const int BATCH_SIZE = 500;

    /**
     * Lock per merchant: the same merchant cannot import twice at the same time,
     * different merchants can still run in parallel.
     */
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


        try {
            $result = $this->importRows($stream, $merchantId);
        } finally {
            fclose($stream);
        }

        $this->report($result, $merchantId);

        return $result->imported === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param resource $stream
     */
    private function importRows($stream, int $merchantId): ImportResult
    {
        $lineNumber = 0;
        $imported = 0;
        $batch = [];
        $errors = [];

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
                $imported += $this->flushBatch($batch);
                $batch = [];
            }

        }

        // IF the list shorter than 500 list
        if ($batch != []) {
            $imported += $this->flushBatch($batch);
        }

        return new ImportResult($imported, $errors);
    }

    private function report(ImportResult $result, int $merchantId): void
    {
        if ($result->errors !== []) {
            $this->warn('Skipped rows: ' . count($result->errors));
            $this->table(['Line', 'Reason'], $result->errors);
        }

        if ($result->imported === 0) {
            $this->error('No rows were imported.');
        }

        Log::info('Product import finished', [
            'merchant_id' => $merchantId,
            'imported' => $result->imported,
            'skipped' => count($result->errors),
        ]);
    }

    /**
     * @param array<int, string|null> $row
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
     * @param array<int, string|null> $row
     * @return array<string, mixed>
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

    /**
     * @param list<ProductRow> $batch
     */
    private function flushBatch(array $batch): int
    {
        DB::table('products')->upsert(
            $batch,
            ['merchant_id', 'ean'],
            ['name', 'color', 'price', 'quantity', 'updated_at']
        );

        return count($batch);
    }
}
