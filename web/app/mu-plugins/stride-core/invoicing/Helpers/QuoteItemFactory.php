<?php

namespace ntdst\Stride\invoicing\Helpers;

defined('ABSPATH') || exit;

/**
 * Quote Item Factory
 *
 * Creates and validates standardized item arrays for quotes.
 * Pure helper class with no WordPress hooks or dependencies.
 *
 * Item structure:
 * - type: string (course, product, service, discount)
 * - id: int (item ID, 0 for discounts)
 * - title: string
 * - unit_price: float
 * - quantity: int
 * - total: float (calculated)
 * - meta: array (optional extra data)
 *
 * @package stride\services\invoicing\Helpers
 */
class QuoteItemFactory
{
    // Standard item types
    public const TYPE_COURSE = 'course';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_SERVICE = 'service';
    public const TYPE_DISCOUNT = 'discount';

    /**
     * Create a quote item
     *
     * @param string $type Item type (course, product, service, discount)
     * @param int $id Item ID (0 for discounts/manual items)
     * @param string $title Item title/description
     * @param float $unitPrice Unit price
     * @param int $quantity Quantity (default 1)
     * @param array $meta Optional metadata
     * @return array Item array
     */
    public static function create(
        string $type,
        int $id,
        string $title,
        float $unitPrice,
        int $quantity = 1,
        array $meta = []
    ): array {
        $quantity = max(1, $quantity);
        $total = $quantity * $unitPrice;

        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'total' => $total,
            'meta' => $meta,
        ];
    }

    /**
     * Create a discount item
     *
     * @param float $amount Discount amount (positive value)
     * @param string $label Discount label/reason
     * @param array $meta Optional metadata (voucher_code, etc)
     * @return array Discount item array
     */
    public static function createDiscount(float $amount, string $label, array $meta = []): array
    {
        return [
            'type' => self::TYPE_DISCOUNT,
            'id' => 0,
            'title' => $label,
            'unit_price' => -abs($amount),
            'quantity' => 1,
            'total' => -abs($amount),
            'meta' => $meta,
        ];
    }

    /**
     * Create a course item
     *
     * @param int $courseId Course ID
     * @param string $title Course title
     * @param float $price Course price
     * @param array $meta Optional metadata
     * @return array Course item array
     */
    public static function createCourse(int $courseId, string $title, float $price, array $meta = []): array
    {
        return self::create(self::TYPE_COURSE, $courseId, $title, $price, 1, $meta);
    }

    /**
     * Create a product item
     *
     * @param int $productId Product ID
     * @param string $title Product title
     * @param float $price Product price
     * @param int $quantity Quantity
     * @param array $meta Optional metadata
     * @return array Product item array
     */
    public static function createProduct(
        int $productId,
        string $title,
        float $price,
        int $quantity = 1,
        array $meta = []
    ): array {
        return self::create(self::TYPE_PRODUCT, $productId, $title, $price, $quantity, $meta);
    }

    /**
     * Create a service item
     *
     * @param int $serviceId Service ID
     * @param string $title Service title
     * @param float $price Service price
     * @param int $quantity Quantity
     * @param array $meta Optional metadata
     * @return array Service item array
     */
    public static function createService(
        int $serviceId,
        string $title,
        float $price,
        int $quantity = 1,
        array $meta = []
    ): array {
        return self::create(self::TYPE_SERVICE, $serviceId, $title, $price, $quantity, $meta);
    }

    /**
     * Create item from filter resolution
     *
     * Uses the stride/quote/resolve_item filter to resolve item details.
     *
     * @param string $type Item type
     * @param int $id Item ID
     * @param int $quantity Quantity
     * @param array $meta Optional metadata
     * @return array|null Item array or null if not resolvable
     */
    public static function createFromType(string $type, int $id, int $quantity = 1, array $meta = []): ?array
    {
        $resolved = apply_filters('stride/quote/resolve_item', null, $type, $id);

        if ($resolved === null || !isset($resolved['title']) || !isset($resolved['price'])) {
            return null;
        }

        // Check if item is valid for quoting
        if (isset($resolved['valid']) && !$resolved['valid']) {
            return null;
        }

        return self::create(
            $type,
            $id,
            $resolved['title'],
            (float) $resolved['price'],
            $quantity,
            array_merge($meta, $resolved['meta'] ?? [])
        );
    }

    /**
     * Validate an item array
     *
     * @param array $item Item to validate
     * @return bool True if valid
     */
    public static function validate(array $item): bool
    {
        // Required fields
        if (!isset($item['type'], $item['title'])) {
            return false;
        }

        // Type must be a non-empty string
        if (!is_string($item['type']) || empty($item['type'])) {
            return false;
        }

        // Title must be a non-empty string
        if (!is_string($item['title']) || empty($item['title'])) {
            return false;
        }

        // ID must be integer (can be 0 for discounts)
        if (isset($item['id']) && !is_int($item['id'])) {
            return false;
        }

        // Price fields must be numeric
        if (isset($item['unit_price']) && !is_numeric($item['unit_price'])) {
            return false;
        }

        if (isset($item['total']) && !is_numeric($item['total'])) {
            return false;
        }

        // Quantity must be positive integer (except for discounts which are always 1)
        if (isset($item['quantity'])) {
            if (!is_int($item['quantity']) || $item['quantity'] < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a collection of items
     *
     * @param array $items Items array
     * @return bool True if all items are valid
     */
    public static function validateAll(array $items): bool
    {
        foreach ($items as $item) {
            if (!self::validate($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalize an item array (add missing fields with defaults)
     *
     * @param array $item Item to normalize
     * @return array Normalized item
     */
    public static function normalize(array $item): array
    {
        $defaults = [
            'type' => self::TYPE_SERVICE,
            'id' => 0,
            'title' => '',
            'unit_price' => 0.0,
            'quantity' => 1,
            'total' => 0.0,
            'meta' => [],
        ];

        $item = array_merge($defaults, $item);

        // Ensure correct types
        $item['type'] = (string) $item['type'];
        $item['id'] = (int) $item['id'];
        $item['title'] = (string) $item['title'];
        $item['unit_price'] = (float) $item['unit_price'];
        $item['quantity'] = max(1, (int) $item['quantity']);
        $item['meta'] = is_array($item['meta']) ? $item['meta'] : [];

        // Recalculate total
        $item['total'] = $item['quantity'] * $item['unit_price'];

        return $item;
    }

    /**
     * Filter items to only those of a specific type
     *
     * @param array $items Items array
     * @param string $type Type to filter
     * @return array Filtered items
     */
    public static function filterByType(array $items, string $type): array
    {
        return array_values(array_filter(
            $items,
            fn($item) => ($item['type'] ?? '') === $type
        ));
    }

    /**
     * Remove discount items from array
     *
     * @param array $items Items array
     * @return array Items without discounts
     */
    public static function removeDiscounts(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn($item) => ($item['type'] ?? '') !== self::TYPE_DISCOUNT
        ));
    }

    /**
     * Get total of all discount items
     *
     * @param array $items Items array
     * @return float Total discount (positive value)
     */
    public static function getTotalDiscount(array $items): float
    {
        $discounts = self::filterByType($items, self::TYPE_DISCOUNT);

        return array_sum(array_map(
            fn($item) => abs($item['total'] ?? 0),
            $discounts
        ));
    }

    /**
     * Get subtotal (sum of non-discount items)
     *
     * @param array $items Items array
     * @return float Subtotal
     */
    public static function getSubtotal(array $items): float
    {
        $nonDiscounts = self::removeDiscounts($items);

        return array_sum(array_map(
            fn($item) => (float) ($item['total'] ?? 0),
            $nonDiscounts
        ));
    }
}
