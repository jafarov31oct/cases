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
 *     quantity: int,
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

        // If it is null throw an exception.
        return $disk->readStream($path)
            ?? throw new RuntimeException("File {$file} is not readable. Could not opne it");
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

            $row = $this->normalizeRow($row);

            if ($row === null) {
                continue;
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

        // flush the last, not-full batch
        if ($batch !== []) {
            $imported += $this->flushBatch($batch);
        }

        return new ImportResult($imported, $errors);
    }

    /**
     * @param list<string|null> $row
     * @return list<string>|null  null = blank line, skip it
     */
    private function normalizeRow(array $row): ?array
    {
        if ($row === [null]) {
            return null; // blank line: not an error, just skip
        }

        return array_map(static fn(?string $value): string => trim($value ?? ''), $row);
    }


    /**
     * Row is already normalized: trimmed, blank lines skipped.
     *
     * @param list<string> $row
     */
    private function validateRow(array $row): ?string
    {
        if (count($row) !== 5) {
            return 'expected 5 columns, got ' . count($row);
        }

        [$name, $color, $ean, $price, $quantity] = $row;

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
     * Row is already validated by validateRow().
     *
     * @param list<string> $row
     * @return ProductRow
     */
    private function mapRow(array $row, int $merchantId): array
    {
        [$name, $color, $ean, $price, $quantity] = $row;

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
    private function report(ImportResult $result, int $merchantId): void
    {
        if ($result->errors !== []) {
            $this->warn('Skipped rows: ' . count($result->errors));
            $this->table(['Line', 'Reason'], $result->errors);
        }

        $context = [
            'merchant_id' => $merchantId,
            'imported' => $result->imported,
            'skipped' => count($result->errors),
        ];

        if ($result->imported === 0) {
            $this->error('No rows were imported.');
            Log::error('Product import failed: nothing imported', $context);
            return;
        }

        Log::info('Product import finished', $context);
    }

}
