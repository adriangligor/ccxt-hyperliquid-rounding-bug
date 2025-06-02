<?php
declare(strict_types = 1);

require __DIR__ . '/../vendor/autoload.php';

$client = new \ccxt\hyperliquid(); // upstream client
//$client = new \App\hyperliquid_patched(); // patched client
load_markets_with_cache($client);

$significant_digits = 5;
$markets = find_markets($client); // all coins!
//$markets = find_markets($client, ["SUI", "XRP", "ONDO", "DOGE", "SOL", "BTC", "s:BUDDY"]);
$prices = [
    "1234567.654321", "123456.7654321", "12345.67654321", "1234.567654321", "123.4567654321", "12.34567654321", "1.234567654321",
    "0.1234567654321", "0.01234567654321", "0.001234567654321", "0.0001234567654321", "0.00001234567654321", "0.000001234567654321",
];

foreach ($markets as $market) {
    $max_decimals = $market["type"] == "swap" ? 6 : 8;
    $sz_decimals = -1 * log($market["precision"]["amount"], 10);
    $coin_max_decimals = $max_decimals - $sz_decimals;

    print_r([
        "id" => $market["id"],
        "symbol" => $market["symbol"],
        "type" => $market["type"],
        "precision.amount" => $market["precision"]["amount"],
        "szDecimals" => $sz_decimals,
        "SIGNIFICANT_DIGITS" => $significant_digits,
        "MAX_DECIMALS" => $max_decimals,
        "coin_max_decimals" => $coin_max_decimals,
    ]);

    printf("                 price  SF  DE    decimal_rounded  SF  DE  precision_rounded  SF  DE\n");
    //printf("%1s %20s %3d %3d %18s %3d %3d %18s %3d %3d\n",
    //    "*",
    //    $prices[count($prices) - 1], count_significant_digits($prices[count($prices) - 1]), count_decimal_digits($prices[count($prices) - 1]),
    //    $prices[count($prices) - 1], count_significant_digits($prices[count($prices) - 1]), count_decimal_digits($prices[count($prices) - 1]),
    //    $prices[count($prices) - 1], count_significant_digits($prices[count($prices) - 1]), count_decimal_digits($prices[count($prices) - 1]),
    //);
    foreach ($prices as $price) {
        $decimal_rounded = $client->decimal_to_precision($price, \ccxt\ROUND, $significant_digits, \ccxt\SIGNIFICANT_DIGITS, \ccxt\NO_PADDING);
        $precision_rounded = $client->price_to_precision($market["symbol"], $price);

        $has_decimal = (strpos($precision_rounded, ".") !== false);
        $error = "";
        if (!$has_decimal) {
            $error = "(int)";
        }
        if ($has_decimal && count_significant_digits($precision_rounded) > $significant_digits) {
            $error = "<< VIOLATES: 'Prices can have up to {$significant_digits} significant figures'";
        }
        if ($has_decimal && count_decimal_digits($precision_rounded) > $coin_max_decimals) {
            $error = "<< VIOLATES: 'no more than MAX_DECIMALS - szDecimals decimal places'";
        }

        printf("%1s %20s %3d %3d %18s %3d %3d %18s %3d %3d",
            (1 <= $price && $price < 10 ? "*" : " "),
            $price, count_significant_digits($price), count_decimal_digits($price),
            $decimal_rounded, count_significant_digits($decimal_rounded), count_decimal_digits($decimal_rounded),
            $precision_rounded, count_significant_digits($precision_rounded), count_decimal_digits($precision_rounded),
        );
        printf("%s\n", $error ? "  {$error}" : "");
    }
}

return 0;

// ------------------------------------------------------------------

function find_markets(\ccxt\Exchange $exchange, array $coins = []): array
{
    if (empty($coins)) {
        return $exchange->markets;
    }

    $markets = [];
    foreach ($coins as $coin) {
        // support "s:COIN"-syntax to indicate spot coins
        $market = (str_starts_with($coin, "s:")) ? $exchange->market(ltrim($coin, "s:") . "/USDC") : $exchange->market("{$coin}/USDC:USDC");
        $markets[] = $market;
    }

    return $markets;
}

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

function count_decimal_digits(int|float|string $number): int
{
    // Convert to string to handle the number as text
    $numStr = (string) $number;

    // Check if the number has a decimal point
    $hasDecimal = (strpos($numStr, ".") !== false);

    if ($hasDecimal) {
        // Split into integer and fractional parts
        list($intPart, $fracPart) = explode(".", $numStr);

        // Remove trailing zeros from fraction part (not significant)
        $fracPart = rtrim($fracPart, "0");

        return strlen($fracPart);
    } else {
        return 0;
    }
}

function count_significant_digits(int|float|string $number): int
{
    // Convert to string to handle the number as text
    $numStr = (string) $number;

    // Check if the number has a decimal point
    $hasDecimal = (strpos($numStr, ".") !== false);

    if ($hasDecimal) {
        // Split into integer and fractional parts
        list($intPart, $fracPart) = explode(".", $numStr);

        // Remove trailing zeros from fraction part (not significant)
        $fracPart = rtrim($fracPart, "0");

        // Different handling based on integer part
        if ($intPart === "0" || $intPart === "00" || $intPart === "000" || ltrim($intPart, "0") === "") {
            // Integer part is zero (e.g., 0.0012345)
            // Remove leading zeros from fractional part (not significant when integer part is 0)
            $fracPart = ltrim($fracPart, "0");
            return strlen($fracPart);
        } else {
            // Integer part is non-zero (e.g., 12.000345, 12345.60)
            // Remove leading zeros from integer part (never significant)
            $intPart = ltrim($intPart, "0");

            // All digits in the integer part and all digits in the fraction part are significant
            // Except trailing zeros in the fraction part
            return strlen($intPart) + strlen($fracPart);
        }
    } else {
        // For integers without decimal point (e.g., 1234500, 0012345)
        // Remove leading zeros (not significant)
        $numStr = ltrim($numStr, "0");

        // All remaining digits are significant
        return strlen($numStr);
    }
}
