# CCXT Hyperliquid rounding error 

When using CCXT 4.4.86 with Hyperliquid, occasionally one can receive the following error: "Price must be divisible by tick size." Since CCXT is implicitly rounding order prices according to exchange metadata, client code has very few possibilities to get this wrong, or fix it if CCXT gets it wrong.

This playground is using the Hyperliquid client to round prices of different orders of magnitude and different coins, and expose situations where CCXT goes against the [official specification](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/tick-and-lot-size).

## Running the playground

The playground is written in PHP. First run
```
$ composer install
```
to install dependencies (really just CCXT 4.4.86). Then the playground can be run with the command:
```
$ php src/playground.php
```

In the source code, the CCXT upstream client can be swapped for the patched client to verify it fixing the bug. Also the precise list of coins the test is running against can be adapted.

## How to interpret the output

Example output:

    Array
    (
        [id] => 12
        [symbol] => DOGE/USDC:USDC
        [type] => swap
        [precision.amount] => 1
        [szDecimals] => -0
        [SIGNIFICANT_DIGITS] => 5
        [MAX_DECIMALS] => 6
        [coin_max_decimals] => 6
    )
                     price  SF  DE    decimal_rounded  SF  DE  precision_rounded  SF  DE
            1234567.654321  13   6            1234600   7   0            1234568   7   0  (int)
            123456.7654321  13   7             123460   6   0             123457   6   0  (int)
            12345.67654321  13   8              12346   5   0              12346   5   0  (int)
            1234.567654321  13   9             1234.6   5   1             1234.6   5   1
            123.4567654321  13  10             123.46   5   2             123.46   5   2
            12.34567654321  13  11             12.346   5   3             12.346   5   3
    *       1.234567654321  13  12            1.23457   6   5            1.23457   6   5  << VIOLATES: 'Prices can have up to 5 significant figures'
           0.1234567654321  13  13            0.12346   5   5            0.12346   5   5
          0.01234567654321  13  14           0.012346   5   6           0.012346   5   6
         0.001234567654321  13  15          0.0012346   5   7           0.001235   4   6
        0.0001234567654321  13  16         0.00012346   5   8           0.000123   3   6
       0.00001234567654321  13  17        0.000012346   5   9           0.000012   2   6
      0.000001234567654321  13  18       0.0000012346   5  10           0.000001   1   6

This output shows certain metadata relevant to rounding according to Hyperliquid documentation. Then it shows a table of numbers and the results of rounding them. **SF** stands for significant digits and **DE** stands for decimal digits. Lines ending in **(int)** show numbers that have to be interpreted as integers when rounding (e.g. the integer part of the number uses up all significant digits, so rounding would zero out the decimals) - they are treated differently in the specification.

The column named **precision_rounded** is showing the output of `\ccxt\hyperliquid::price_to_precision`, which is what `\ccxt\hyperliquid::create_order` is using (several function calls down the line) to round prices according to exchange rules.

The column named **decimal_rounded** is the output of the key line of code in `\ccxt\hyperliquid::price_to_precision`: `$result = $this->decimal_to_precision($price, ROUND, $significantDigits, SIGNIFICANT_DIGITS, $this->paddingMode);` This is the line carrying the faulty computation all the way into `\ccxt\hyperliquid::create_order`.

The line in the middle flagged with the message "VIOLATES: 'Prices can have up to 5 significant figures'" shows a number with 6 significant digits after rounding, despite the specification allowing only for 5. If the playground is running for all coins, the error seems to always occur for prices >=1 && < 10.

## Possible underlying reason for the rounding bug, and a proposal for a fix

It seems that the faulty behaviour originates in `\ccxt\Exchange::decimal_to_precision`. There in the [line 1814](https://github.com/ccxt/ccxt/blob/v4.4.86/php/Exchange.php#L1814) the significant position is computed using the expression:
```
$significantPosition = ((int) log( abs($x), 10)) % 10;
if ($significantPosition > 0) {
    ++$significantPosition;
}
```

This looks wrong. Here is a table that compares the expected significant position and the actually computed one:

| `$x` interval | expected | `((int) log( abs($x), 10)) % 10` | `$sPtn > 0 ? ++$sPtn` |
|---------------|----------|----------------------------------|-----------------------|
| [0.001, 0.01[ | -2       | -2                               | -2                    |
| [0.01, 0.1[   | -1       | -1                               | -1                    |
| [0.1, 1[      | 0        | 0                                | 0                     |
| [1, 10[       | 1        | 0                                | 0                     |
| [10, 100[     | 2        | 1                                | 2                     |
| [100, 1000[   | 3        | 2                                | 3                     |

The original expression returns a result 1 too small, for numbers >= 1. It would be reasonable to guess that the conditional increase is intending to fix this by adding 1. As the last column shows, it doesn't fix the interval [1, 10[. Instead, it likely should be fixed as follows:
```
$significantPosition = ((int) log( abs($x), 10)) % 10;
if ($x >= 1) {
    ++$significantPosition;
}
```

🎉 When running the playground for all coins with this fix, it shows no more violations of rounding rules.

## How the patched client is fixing it

The playground contains a patched Hyperliquid client, `hyperliquid_patched.php`, that can be substituted into the code and should not violate any coin's rounding parameters. A singular line is added: `if (1 <= $price && $price < 10) { $subtractedValue = min($subtractedValue, 4); } // << total hack!`. It's difficult to explain why this is fixing the computation, `$result` is wrong and adjusting `$subtractedValue` compensates for the error. A proper fix would have to address the underlying reason, found in `\ccxt\Exchange::decimal_to_precision`. This fix is only good because it can be applied in client code without touching upstream. This is what our project is currently doing.
