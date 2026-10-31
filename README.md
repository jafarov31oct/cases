# Backend Case Studies

Two backend case studies. Each folder has its own README with the details.

| Case | Topic |
|---|---|
| [Case One](case-one/) | Cross-docking steering pipeline: block and unblock products from a daily CSV |
| [Case Two](case-two/) | Import a merchant's products from a CSV file with an Artisan command |

## Case One: Cross-Docking Steering Pipeline

The product team uploads a daily CSV (`product_variant_id, shop_id, warehouse_id`, 2M+ rows) to SFTP.
The pipeline compares it with the currently blocked items and calls an external API to block or unblock the differences.

```
SFTP -> File Watcher -> Import Worker -> Diffing Worker -> API Worker -> POST /block, POST /unblock
          (cron)      (LOAD DATA into    (anti-join:        (batches of 500,
                        staging table)     block / unblock)   retry + DLQ)
```

- Each step runs as its own job on a separate RabbitMQ queue, and the file status (`DETECTED → IMPORTING → IMPORTED → DIFFING → DIFFED → DONE` or `FAILED`) is tracked in `cd_steering_files` in MySQL.
- Block requests are sent before unblock requests, so unprofitable products are blocked as early as possible.
- Transient API errors are retried with exponential backoff and jitter; permanent errors go to a DLQ with an alert.

## Case Two: Product Import from CSV

`php artisan product:import {merchant} {file}` reads a merchant's CSV and inserts or updates their products.
It streams the file, validates every row, upserts in batches of 500 and locks per merchant.

`case-two` contains only the files written for the case. The full Laravel app is on the `case-two-laravel-app` branch.

## What I learned

- **Working with large CSV.** Real files can be messy: blank lines, an optional header, an invisible UTF-8 BOM from Excel, `;` instead of `,`.
- **Laravel Storage abstraction.** Reading through `Storage::disk(...)`. Moving from the local disk to S3 is just a config change.
- **A cross-docking pipeline design.** Splitting the work into small async steps with a status per file makes each step retryable and easy to monitor.
- **`LOAD DATA` from CSV straight into MySQL.** It is much faster than inserting rows from PHP. The trade-off: rows skip application validation, so the import does not check, for example, whether a warehouse exists.
