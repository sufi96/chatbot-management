<?php

/*
 * Seeds the Kedai Aina demo through the portal's own code, so a test of it
 * exercises what an operator's setup would: schema discovery, the table
 * allowlist, source records and indexing through the engine.
 *
 * Run from anywhere:  php demo/kedai-aina/seed.php
 * Safe to run again: every record has a fixed id and is updated in place, and
 * the shop database is rebuilt from shop.sql.
 *
 * It creates its own bot, "Kedai Aina Assistant", so no existing bot changes.
 */

use App\Models\BotProfile;
use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\KbCollection;
use App\Models\KbSource;
use App\Services\EngineClient;
use App\Services\Schema\SchemaIntrospector;
use App\Services\Schema\SchemaSync;

$portal = realpath(__DIR__ . '/../../admin-laravel');
require $portal . '/vendor/autoload.php';
$app = require $portal . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const SYSTEM_ID = 'sys_default_01';
const PROVIDER_ID = 'aip_yRaQTLrrYOsX';   // Laptop vLLM - 8GB VRAM
const MODEL = 'qwen3.5-4b';

// The shop's own database, rebuilt so its rows match what the evaluation set expects.
$shopPath = __DIR__ . DIRECTORY_SEPARATOR . 'shop.sqlite';
if (file_exists($shopPath)) {
    unlink($shopPath);
}
$pdo = new PDO('sqlite:' . $shopPath);
$pdo->exec(file_get_contents(__DIR__ . '/shop.sql'));
$pdo = null;
$shopPath = realpath($shopPath);

$bot = BotProfile::updateOrCreate(['id' => 'bot_kedai_demo'], [
    'system_id' => SYSTEM_ID,
    'name' => 'Kedai Aina Assistant',
    'provider_id' => PROVIDER_ID,
    'model_name' => MODEL,
    'system_prompt' => "You are the customer service assistant for Kedai Aina, a Malaysian home appliance shop. "
        . "Answer using the information you are given about the shop's policies, products and orders. "
        . "If that information does not answer the question, say you do not have that information and suggest contacting customer service. "
        . "Reply in the language the customer writes in. Keep answers short.",
    'temperature' => 0.3,
    'max_tokens' => 1024,
    'thinking_level' => 'off',
    'is_active' => true,
    'retrieval_enabled' => true,
    'db_query_enabled' => true,
    'web_search_enabled' => false,
    'source_order' => 'documents,database,web',
    'retrieval_fallback' => 'say_unknown',
    'intent_enabled' => false,
    'guard_enabled' => false,
    'widget_title' => 'Kedai Aina',
]);

$connection = DbConnection::updateOrCreate(['id' => 'dbc_kedai_demo'], [
    'system_id' => SYSTEM_ID,
    'name' => 'Kedai Aina shop database',
    'driver' => 'sqlite',
    'database' => $shopPath,
    'is_enabled' => true,
]);

$test = SchemaIntrospector::test($connection);
$counts = SchemaSync::apply($connection, SchemaIntrospector::discover($connection));
$connection->update(['status' => 'ok', 'error_message' => null]);

// What an operator writes on the schema screen. The model reads these instead
// of the rows, so they are the difference between a query and a guess.
$descriptions = [
    'customers' => ['People who have bought from Kedai Aina, one row per customer.', [
        'full_name' => "The customer's name as given at checkout",
        'email' => "The customer's email address",
        'state' => 'The Malaysian state the customer lives in',
        'joined_on' => 'The date the account was created, as YYYY-MM-DD',
    ]],
    'products' => ['The items the shop sells, with current price and stock.', [
        'sku' => 'Product code, such as AF-X200',
        'name' => 'Product name shown to customers, such as X200 Air Fryer',
        'category' => 'Kitchen, Cleaning, Laundry or Cooling',
        'price_rm' => 'Current selling price in Malaysian ringgit',
        'stock_qty' => 'Units in the warehouse now; 0 means out of stock',
        'warranty_months' => 'Length of the warranty in months',
    ]],
    'orders' => ['One row per customer order.', [
        'order_no' => 'The order number customers quote, such as KA-1003',
        'customer_id' => 'The customer who placed the order',
        'order_date' => 'The date the order was placed, as YYYY-MM-DD',
        'status' => 'One of pending, paid, shipped, delivered, cancelled or refunded',
        'shipping_state' => 'The Malaysian state the order is delivered to',
        'total_rm' => 'The order total in ringgit',
    ]],
    'order_items' => ['The products within each order.', [
        'order_id' => 'The order this line belongs to',
        'product_id' => 'The product on this line',
        'quantity' => 'Units of the product in the order',
        'unit_price_rm' => 'Price per unit when the order was placed',
    ]],
];

$tables = [];
foreach ($descriptions as $tableName => [$tableDescription, $columns]) {
    $table = DbTable::where('connection_id', $connection->id)
        ->where('table_name', $tableName)->firstOrFail();
    $table->forceFill(['description' => $tableDescription, 'is_enabled' => true])->save();

    foreach ($columns as $columnName => $text) {
        DbColumn::where('table_id', $table->id)->where('column_name', $columnName)
            ->update(['description' => $text]);
    }

    $tables[] = $table->qualifiedName();
}

$bot->dbConnections()->sync([$connection->id]);

$collection = KbCollection::updateOrCreate(['id' => 'kbc_kedai_demo'], [
    'system_id' => SYSTEM_ID,
    'name' => 'Kedai Aina customer policies',
    'description' => 'Warranty, returns, shipping, showrooms and common questions.',
]);

$sources = [
    ['kbs_kedai_warranty', 'text', 'Warranty and Returns Policy',
        'Warranty periods by product line, returns and refunds',
        file_get_contents(__DIR__ . '/policies/warranty-and-returns.md')],
    ['kbs_kedai_shipping', 'text', 'Shipping and Delivery',
        'Delivery times and fees by destination, same-day delivery and tracking',
        file_get_contents(__DIR__ . '/policies/shipping-and-delivery.md')],
    ['kbs_kedai_stores', 'text', 'Stores and Contact',
        'Showroom addresses, opening hours and customer service contacts',
        file_get_contents(__DIR__ . '/policies/stores-and-contact.md')],
    ['kbs_kedai_cancel', 'qa', 'Can I cancel my order?', 'Cancelling an order',
        'Yes, as long as it has not shipped. Cancel it under My Orders on the website, or message us on WhatsApp with the order number. '
        . 'Once an order has shipped it cannot be cancelled, but it can be returned under the returns policy.'],
    ['kbs_kedai_ansuran', 'qa', 'Adakah Kedai Aina menawarkan bayaran ansuran?', 'Bayaran ansuran',
        'Ya. Pesanan melebihi RM 500 boleh dibayar secara ansuran 0% selama 6 atau 12 bulan menggunakan kad kredit Maybank, CIMB atau Public Bank, atau melalui Atome.'],
];

foreach ($sources as [$id, $type, $title, $description, $body]) {
    KbSource::updateOrCreate(['id' => $id], [
        'collection_id' => $collection->id,
        'type' => $type,
        'title' => $title,
        'description' => $description,
        'body' => $body,
        'status' => 'pending',
        'error_message' => null,
    ]);
}

$bot->collections()->sync([$collection->id]);

$indexing = [];
foreach ($sources as [$id]) {
    $indexing[$id] = EngineClient::indexSource($id) ? 'accepted' : 'refused';
}

echo json_encode([
    'bot' => $bot->id,
    'shop_database' => $shopPath,
    'connection_test' => $test,
    'discovery' => $counts,
    'enabled_tables' => $tables,
    'collection' => $collection->id,
    'indexing' => $indexing,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
