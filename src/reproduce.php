<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$client = new \ccxt\hyperliquid(); // upstream client
//$client = new \App\hyperliquid_patched(); // patched client
load_markets_with_cache($client);

$client->walletAddress = "...";
$client->privateKey = "...";

$client->verbose = true;
$client->create_order("XRP/USDC:USDC", "market", "buy", 34.34343434, 2.345);

// ------------------------------------------------------------------

function load_markets_with_cache(\ccxt\Exchange $exchange): void
{
    $exchange_id = $exchange->describe()["id"];
    $cache_location = "./cached_{$exchange_id}_data.json";
    if (file_exists($cache_location)) {
        $loaded_cache = json_decode(file_get_contents($cache_location), true);
        $exchange->set_markets($loaded_cache["markets"], $loaded_cache["currencies"]);
    } else {
        $markets = $exchange->load_markets();
        file_put_contents($cache_location, json_encode(["markets" => $markets, "currencies" => $exchange->currencies]));
    }
}
