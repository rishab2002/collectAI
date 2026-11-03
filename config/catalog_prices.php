<?php

/*
|--------------------------------------------------------------------------
| MSRP — list prices, the last-resort price
|--------------------------------------------------------------------------
|
| Used only when eBay has no sold (or, if enabled, asking) price for a car.
| Keyed by brand, then by main line (the names the site shows), with the price
| in the stated currency. A car whose line isn't listed gets no MSRP.
|
| These are the maker's suggested retail prices, which you know better than
| any store page: fill them in from the packaging or the maker's own price
| list. Entries are left out rather than guessed — a wrong MSRP is worse than
| none, because the site shows it as a fact.
|
| Example:
|
|   'Hot Wheels' => [
|       'Mainline' => ['price' => 1.25, 'currency' => 'USD'],
|       'Car Culture' => ['price' => 6.99, 'currency' => 'USD'],
|   ],
|
| After editing, the next daily price run picks the new values up for cars
| whose turn it is; to apply them to everything at once, run
| `php artisan catalog:update-prices --msrp-only`.
|
*/

return [

];
