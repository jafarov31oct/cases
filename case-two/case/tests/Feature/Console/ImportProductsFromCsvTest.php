<?php

use Illuminate\Console\Command;
use Illuminate\Console\CommandMutex;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'filesystems.imports_disk' => 'imports',
        'filesystems.imports_path' => 'imports',
    ]);
});

function createMerchant(): int
{
    return DB::table('merchants')->insertGetId([
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<string>  $lines
 */
function putImportFile(string $file, array $lines): void
{
    Storage::disk('imports')->put('imports/'.$file, implode("\n", $lines));
}

it('imports every row of a file without a header row', function () {
    Storage::fake('imports');
    freezeTime();
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'T-Shirt,red,4006381333931,19.99,10',
        'Mug,blue,4006381333948,5.50,3',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseCount('products', 2);
    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'name' => 'T-Shirt',
        'color' => 'red',
        'ean' => '4006381333931',
        'price' => '19.99',
        'quantity' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'name' => 'Mug',
        'color' => 'blue',
        'ean' => '4006381333948',
        'price' => '5.50',
        'quantity' => 3,
    ]);
});

it('logs a summary of the finished import', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'T-Shirt,red,4006381333931,19.99,10',
        ',red,4006381333948,5.50,3',
    ]);
    Log::shouldReceive('info')
        ->once()
        ->with('Product import finished', [
            'merchant_id' => $merchantId,
            'imported' => 1,
            'skipped' => 1,
        ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();
});

it('trims values and stores an empty color as null', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        '  T-Shirt  ,   , 4006381333931 , 19.99 , 10 ',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'name' => 'T-Shirt',
        'color' => null,
        'ean' => '4006381333931',
        'price' => '19.99',
        'quantity' => 10,
    ]);
});

it('parses quoted fields that contain commas', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        '"Shirt, slim fit","dark, blue",4006381333931,19.99,10',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'name' => 'Shirt, slim fit',
        'color' => 'dark, blue',
    ]);
});

it('updates the existing product when the merchant imports the same ean again', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    DB::table('products')->insert([
        'merchant_id' => $merchantId,
        'name' => 'Old name',
        'color' => 'red',
        'ean' => '4006381333931',
        'price' => '10.00',
        'quantity' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    putImportFile('products.csv', [
        'New name,green,4006381333931,25.00,7',
    ]);
    freezeTime();

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseCount('products', 1);
    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'name' => 'New name',
        'color' => 'green',
        'ean' => '4006381333931',
        'price' => '25.00',
        'quantity' => 7,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => now(),
    ]);
});

it('does not modify products of another merchant with the same ean', function () {
    Storage::fake('imports');
    $importingMerchantId = createMerchant();
    $otherMerchantId = createMerchant();
    DB::table('products')->insert([
        'merchant_id' => $otherMerchantId,
        'name' => 'Other merchant product',
        'color' => 'red',
        'ean' => '4006381333931',
        'price' => '10.00',
        'quantity' => 1,
    ]);
    putImportFile('products.csv', [
        'T-Shirt,green,4006381333931,25.00,7',
    ]);

    artisan('product:import', ['merchant' => (string) $importingMerchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseCount('products', 2);
    assertDatabaseHas('products', [
        'merchant_id' => $otherMerchantId,
        'name' => 'Other merchant product',
        'price' => '10.00',
        'quantity' => 1,
    ]);
    assertDatabaseHas('products', [
        'merchant_id' => $importingMerchantId,
        'name' => 'T-Shirt',
    ]);
});

it('imports every row when the file is larger than one batch', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    $lines = array_map(
        static fn (int $index): string => sprintf('Product %d,red,%013d,1.00,1', $index, $index + 1000000000000),
        range(1, 1001),
    );
    putImportFile('products.csv', $lines);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseCount('products', 1001);
    assertDatabaseHas('products', ['merchant_id' => $merchantId, 'name' => 'Product 1001', 'ean' => '1000000001001']);
});

it('skips an invalid row and reports its line and reason', function (string $invalidLine, string $reason) {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'T-Shirt,red,4006381333931,19.99,10',
        $invalidLine,
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->expectsOutputToContain('Skipped rows: 1')
        ->expectsTable(['Line', 'Reason'], [[2, $reason]])
        ->assertSuccessful();

    assertDatabaseCount('products', 1);
})->with([
    'too few columns' => ['Mug,blue,4006381333948,5.50', 'expected 5 columns, got 4'],
    'too many columns' => ['Mug,blue,4006381333948,5.50,3,extra', 'expected 5 columns, got 6'],
    'empty name' => [' ,blue,4006381333948,5.50,3', 'name is empty'],
    'ean with letters' => ['Mug,blue,40063813339AB,5.50,3', "invalid ean '40063813339AB'"],
    'ean shorter than 8 digits' => ['Mug,blue,1234567,5.50,3', "invalid ean '1234567'"],
    'ean longer than 14 digits' => ['Mug,blue,123456789012345,5.50,3', "invalid ean '123456789012345'"],
    'empty ean' => ['Mug,blue,,5.50,3', "invalid ean ''"],
    'non numeric price' => ['Mug,blue,4006381333948,cheap,3', "invalid price 'cheap'"],
    'negative price' => ['Mug,blue,4006381333948,-1.00,3', "invalid price '-1.00'"],
    'negative quantity' => ['Mug,blue,4006381333948,5.50,-3', "invalid quantity '-3'"],
    'decimal quantity' => ['Mug,blue,4006381333948,5.50,1.5', "invalid quantity '1.5'"],
    'empty quantity' => ['Mug,blue,4006381333948,5.50,', "invalid quantity ''"],
]);

it('accepts ean lengths on the 8 and 14 digit boundaries', function (string $ean) {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', ["Mug,blue,{$ean},5.50,3"]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseHas('products', ['merchant_id' => $merchantId, 'ean' => $ean]);
})->with([
    '8 digits' => '12345678',
    '14 digits' => '12345678901234',
]);

it('accepts a zero price and a zero quantity', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', ['Free sample,blue,4006381333948,0,0']);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseHas('products', [
        'merchant_id' => $merchantId,
        'ean' => '4006381333948',
        'price' => '0.00',
        'quantity' => 0,
    ]);
});

it('skips blank lines without reporting them as errors', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'T-Shirt,red,4006381333931,19.99,10',
        '',
        'Mug,blue,4006381333948,5.50,3',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->doesntExpectOutputToContain('Skipped rows')
        ->assertSuccessful();

    assertDatabaseCount('products', 2);
});

it('counts blank lines in the reported line numbers', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'T-Shirt,red,4006381333931,19.99,10',
        '',
        ',blue,4006381333948,5.50,3',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->expectsTable(['Line', 'Reason'], [[3, 'name is empty']])
        ->assertSuccessful();
});

it('reports a header row as a skipped row', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        'name,color,ean,price,quantity',
        'T-Shirt,red,4006381333931,19.99,10',
    ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->expectsTable(['Line', 'Reason'], [[1, "invalid ean 'ean'"]])
        ->assertSuccessful();

    assertDatabaseCount('products', 1);
});

it('fails and logs an error when no row could be imported', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', [
        ',red,4006381333931,19.99,10',
    ]);
    Log::shouldReceive('error')
        ->once()
        ->with('Product import failed: nothing imported', [
            'merchant_id' => $merchantId,
            'imported' => 0,
            'skipped' => 1,
        ]);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->expectsOutputToContain('No rows were imported.')
        ->assertFailed();

    assertDatabaseCount('products', 0);
});

it('fails when the file is empty', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    putImportFile('products.csv', []);

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->expectsOutputToContain('No rows were imported.')
        ->assertFailed();

    assertDatabaseCount('products', 0);
});

it('rejects a merchant id that is not a positive integer', function (string $merchant) {
    Storage::fake('imports');
    putImportFile('products.csv', ['T-Shirt,red,4006381333931,19.99,10']);

    artisan('product:import', ['merchant' => $merchant, 'file' => 'products.csv'])
        ->expectsOutputToContain("Merchant Id must be an integer: {$merchant}")
        ->assertFailed();

    assertDatabaseCount('products', 0);
})->with([
    'letters' => 'abc',
    'negative number' => '-1',
    'decimal number' => '1.5',
    'sql fragment' => '1 OR 1=1',
]);

it('rejects a merchant that does not exist', function () {
    Storage::fake('imports');
    putImportFile('products.csv', ['T-Shirt,red,4006381333931,19.99,10']);

    artisan('product:import', ['merchant' => '999', 'file' => 'products.csv'])
        ->expectsOutputToContain('Merchant Id 999 does not exists')
        ->assertFailed();

    assertDatabaseCount('products', 0);
});

it('rejects a file that does not exist', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'missing.csv'])
        ->expectsOutputToContain('File missing.csv does not exists.')
        ->assertFailed();

    assertDatabaseCount('products', 0);
});

it('does not read files outside the imports directory', function () {
    Storage::fake('imports');
    $merchantId = createMerchant();
    Storage::disk('imports')->put('secret/products.csv', 'T-Shirt,red,4006381333931,19.99,10');

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => '../secret/products.csv'])
        ->expectsOutputToContain('File products.csv does not exists.')
        ->assertFailed();

    assertDatabaseCount('products', 0);
});

it('reads the file from the configured disk and path', function () {
    Storage::fake('merchant-uploads');
    config([
        'filesystems.imports_disk' => 'merchant-uploads',
        'filesystems.imports_path' => 'incoming/csv',
    ]);
    $merchantId = createMerchant();
    Storage::disk('merchant-uploads')->put('incoming/csv/products.csv', 'T-Shirt,red,4006381333931,19.99,10');

    artisan('product:import', ['merchant' => (string) $merchantId, 'file' => 'products.csv'])
        ->assertSuccessful();

    assertDatabaseHas('products', ['merchant_id' => $merchantId, 'ean' => '4006381333931']);
});

it('skips an isolated import while the same merchant is already importing', function () {
    Storage::fake('imports');
    $busyMerchantId = createMerchant();
    $freeMerchantId = createMerchant();
    putImportFile('products.csv', ['T-Shirt,red,4006381333931,19.99,10']);
    mock(CommandMutex::class)
        ->shouldReceive('create')
        ->andReturnUsing(fn (Command $command): bool => $command->isolatableId() !== (string) $busyMerchantId)
        ->shouldReceive('forget')
        ->andReturnTrue();

    artisan('product:import', ['merchant' => (string) $busyMerchantId, 'file' => 'products.csv', '--isolated' => true])
        ->expectsOutputToContain('already running');
    artisan('product:import', ['merchant' => (string) $freeMerchantId, 'file' => 'products.csv', '--isolated' => true])
        ->assertSuccessful();

    assertDatabaseCount('products', 1);
    assertDatabaseHas('products', ['merchant_id' => $freeMerchantId]);
});
