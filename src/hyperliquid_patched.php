<?php

namespace App;

use const ccxt\DECIMAL_PLACES;
use const ccxt\ROUND;
use const ccxt\SIGNIFICANT_DIGITS;

class hyperliquid_patched extends \ccxt\hyperliquid
{
    public function price_to_precision(string $symbol, $price): string
    {
        $market = $this->market($symbol);
        $priceStr = $this->number_to_string($price);
        $integerPart = explode('.', $priceStr)[0];
        $significantDigits = max (5, strlen($integerPart));
        $result = $this->decimal_to_precision($price, ROUND, $significantDigits, SIGNIFICANT_DIGITS, $this->paddingMode);
        $maxDecimals = $market['spot'] ? 8 : 6;
        $subtractedValue = $maxDecimals - $this->precision_from_string($this->safe_string($market['precision'], 'amount'));
        if (1 <= $price && $price < 10) { $subtractedValue = min($subtractedValue, 4); } // << total hack!
        return $this->decimal_to_precision($result, ROUND, $subtractedValue, DECIMAL_PLACES, $this->paddingMode);
    }
}
