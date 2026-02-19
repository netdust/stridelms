<?php
/**
 * Test quotes loading for user
 */

$userId = 1;

$quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$quotes = $quoteService->getUserQuotes($userId);

echo "Quotes found for user {$userId}: " . count($quotes) . PHP_EOL;

foreach ($quotes as $quote) {
    echo "- ID: " . ($quote['id'] ?? '?');
    echo ", Number: " . ($quote['quote_number'] ?? '?');
    echo ", Status: " . ($quote['status'] ?? '?');
    echo PHP_EOL;
}
