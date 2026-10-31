# Case Two: Product Import from CSV

An Artisan command that imports a merchant's products from a CSV file.
Existing products are updated, new ones are inserted.

> This folder contains only the files written for this case.
> The full Laravel application (to run the command and the tests) is on the
> [`case-two-laravel-app`](https://github.com/jafarov31oct/cases/tree/case-two-laravel-app/case-two/case) branch.

## Acceptance criteria

- Import products from a CSV file with a PHP Command.
- Every merchant sends their own CSV, so the file name is not fixed.
- Files are stored on the local disk today and may move to S3 later. Files can be large.
- If a product already exists it is updated, otherwise it is inserted.
- Columns: `name, color, ean, price, quantity`. A header line may or may not be present.

## Usage

```bash
php artisan product:import {merchant} {file}

php artisan product:import 1 merchant_01.csv
```

In the scheduler, use `--isolated` so the same merchant is never imported twice at the same time:

```php
$schedule->command('product:import 1 merchant_01.csv --isolated')->everyFiveMinutes();
```

### Exit codes

| Code | When |
|---|---|
| `0` (`SUCCESS`) | At least one product was imported. |
| `1` (`FAILURE`) | Invalid merchant id, unknown merchant, missing or unreadable file, or no rows imported. |

## How it works

```
handle()
 ├─ getMerchantId()      validate the argument, check the merchant exists
 ├─ openImportStream()   open the file as a stream from the configured disk
 ├─ importRows()         read line by line
 │    ├─ normalizeRow()  trim cells, strip the UTF-8 BOM, skip blank lines
 │    ├─ isHeader()      skip the optional header on line 1
 │    ├─ validateRow()   collect invalid rows with their line number
 │    ├─ mapRow()        CSV row -> database row
 │    └─ flushBatch()    upsert 500 rows per query
 └─ report()             print skipped rows, write the log
```

`importRows()` never prints anything. It returns an `ImportResult`, and `report()` decides how to show it.

## Design decisions

- **Streaming.** The file is read with `fgetcsv` one line at a time, so memory does not grow with the file size.
- **Storage disk abstraction.** The file is read through `Storage::disk(...)`. Moving to S3 is a config change, not a code change.
- **Batch upsert.** Valid rows are written 500 at a time with one `upsert` query: about 2,000 queries for 1M rows instead of 1M.
- **Unique index on `(merchant_id, ean)`.** MySQL's upsert decides between insert and update through this index. Without it, every run would insert duplicates.
- **`created_at` is not updated.** It is not in the upsert's update list, so the original creation date is kept.
- **Bad rows do not stop the import.** They are skipped and reported with their line number and reason.
- **Validation before the database.** A single invalid value (e.g. price `abc`) would otherwise make MySQL reject the whole batch of 500 rows.
- **Price stays a string.** It goes into a `DECIMAL(10,2)` column exactly as it came in. Casting to float would add rounding errors.
- **EAN stays a string.** EANs can start with `0`; an integer column would drop it.
- **One lock per merchant (`Isolatable`).** The same merchant cannot import twice in parallel; different merchants still can.
- **No single transaction for the whole import.** Each upsert is already atomic. One long transaction on a large file would hold locks for minutes. The upsert is idempotent, so a failed run can simply be rerun.
- **Path traversal protection.** Only the file name from the argument is used (`basename`), so `../../.env` cannot be read.

## Validation rules

| Field | Rule |
|---|---|
| row | exactly 5 columns |
| `name` | not empty |
| `color` | optional, empty becomes `NULL` |
| `ean` | digits only, 8–14 characters (EAN-8 to GTIN-14) |
| `price` | numeric, not negative |
| `quantity` | whole number, 0 or more |

## Configuration

`config/filesystems.php`:

```php
'imports_disk' => env('IMPORTS_DISK', 'local'),
'imports_path' => env('IMPORTS_PATH', 'imports'),
```

With Laravel 11+ the `local` disk root is `storage/app/private`, so files go to
`storage/app/private/imports/`. Set `IMPORTS_DISK=s3` to read from S3.

The `--isolated` lock uses the cache, so `CACHE_STORE` must be a shared store (`database` or `redis`), not `array`.

## Files

| File | Location in the Laravel app |
|---|---|
| `ImportProductsFromCsv.php` | `app/Console/Commands/` |
| `ImportResult.php` | `app/Console/Commands/` |
| `*_create_merchants_table.php` | `database/migrations/` |
| `*_create_product_table.php` | `database/migrations/` |
| `ImportProductsFromCsvTest.php` | `tests/Feature/Console/` |
| `merchant_01.csv` | `storage/app/private/imports/` (sample file) |

## Tests

Feature tests (Pest) run the command end to end against a real database and a fake storage disk.
They were drafted with AI assistance, then reviewed and run locally.

```bash
php artisan test
./vendor/bin/phpstan analyse
```

## Known limitations

These are known and left out on purpose to keep the scope of the case:

- **Duplicate EANs inside one file:** the last row wins silently. I would track seen EANs and report duplicates.
- **Error list grows with the file:** with 1M invalid rows the list of skipped rows uses a lot of memory. I would keep the first N errors and only count the rest.
- **Delimiter:** only `,` is supported. German Excel exports use `;`.
- **Partial success:** one imported row counts as success. A high skip rate could also fail the run; this is a business decision.
- **Price format:** `is_numeric` also accepts values like `1e3`, and prices with more than 2 decimals are rounded by the database. A stricter money format check would fix this.
- **Header detection:** only checked on line 1. A blank line before the header would make it a reported error.
