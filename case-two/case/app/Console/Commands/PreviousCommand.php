<?php

/**
 * Acceptance Criteria (as far as I remember)
 *
 * - We need a command file that imports products from a CSV file.
 * - Every merchant sends their own CSV, so filename is not always the same. ( must not the same)
 * - Files currently located in local storage but it is possbile to move S3 (files can be pretty big.)
 *
 * Columsn ( name, color, ean, price, quantity) -> header is not guarantee
 *
 * Example php artisan product:import merchant_01.csv
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use mysqli;

class PreviousCommand extends Command
{
    protected $signature = "product:import {file}";
    protected $description = "Import products via CSV";

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        echo "starting";

        $host = "localhost";
        $user = "root";
        $password = "password";
        $database = "database";

        $conn = new mysqli($host, $user, $password, $database);

        // Drop the rows from product
        $conn->query("DROP TABLE IF EXISTS `products`");

        $conn->query("CREATE TABLE `products` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255),
                color VARCHAR(255),
                ean VARCHAR(255),
                price DECIMAL(10, 2),
                quantity BIGINT DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME
        )");

        $rows = file($this->argument("file"));

        foreach ($rows as $k => $v) {
            $conn->query("INSERT INTO products (name, color, ean, price, quantity, created_at, updated_at) VALUES (
                $v[0], $v[1], $v[2], $v[3], $v[4], NOW(), NOW()
             )");
        }

        echo "0";

    }
}
