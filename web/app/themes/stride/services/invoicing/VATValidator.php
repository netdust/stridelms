<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

/**
 * VAT Validator
 *
 * Validates EU VAT numbers via the VIES (VAT Information Exchange System) API.
 * Returns company name and address when available.
 *
 * Fail-open behavior: If VIES API is unavailable, accepts VAT numbers that pass
 * basic format validation. This prevents enrollment blocking due to API downtime.
 *
 * @package stride\services\invoicing
 */
class VATValidator implements \NTDST_Service_Meta
{
    private const VIES_WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
    private const CACHE_GROUP = 'stride_vat';
    private const CACHE_TTL = DAY_IN_SECONDS;
    private const TRANSIENT_PREFIX = 'stride_vat_';

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'VAT Validator',
            'description' => 'EU VAT number validation via VIES API',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor - register Action Scheduler hook
     */
    public function __construct()
    {
        // Register async re-validation handler
        add_action('stride/vat/revalidate', [$this, 'revalidateAsync']);
    }

    /**
     * Validate VAT number and return company data
     *
     * Fails open: if VIES is unavailable, accepts valid format.
     *
     * @param string $vatNumber VAT number to validate (with or without country prefix)
     * @return array{
     *   valid: bool,
     *   vat_number?: string,
     *   country_code?: string,
     *   name?: string,
     *   address?: string,
     *   source: string,
     *   vies_error?: string
     * }
     */
    public function validate(string $vatNumber): array
    {
        $vatNumber = $this->normalize($vatNumber);

        // Basic format check first
        if (!$this->hasValidFormat($vatNumber)) {
            return [
                'valid' => false,
                'vat_number' => $vatNumber,
                'source' => 'format',
                'error' => 'Invalid VAT number format',
            ];
        }

        $countryCode = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        // Check cache (with transient fallback for non-persistent object cache)
        $cached = $this->getCachedResult($vatNumber);
        if ($cached !== null) {
            return $cached;
        }

        // Try VIES API
        try {
            $result = $this->checkVIES($countryCode, $number);

            // Cache successful result
            $this->cacheResult($vatNumber, $result, self::CACHE_TTL);

            return $result;

        } catch (\Exception $e) {
            // FAIL OPEN: Accept if format is valid but VIES unavailable
            $result = [
                'valid' => true,
                'vat_number' => $vatNumber,
                'country_code' => $countryCode,
                'source' => 'format_only',
                'vies_error' => $e->getMessage(),
            ];

            // Log fail-open for later review (security/compliance tracking)
            $this->logFailOpen($vatNumber, $e->getMessage());

            // Cache fail-open result for shorter period
            $this->cacheResult($vatNumber, $result, HOUR_IN_SECONDS);

            // Schedule async re-validation if Action Scheduler is available
            $this->scheduleRevalidation($vatNumber);

            return $result;
        }
    }

    /**
     * Log fail-open events for compliance tracking
     *
     * @param string $vatNumber The VAT number that was fail-opened
     * @param string $reason The reason for fail-open (error message)
     */
    private function logFailOpen(string $vatNumber, string $reason): void
    {
        error_log(sprintf(
            'Stride VAT fail-open: %s (reason: %s)',
            $vatNumber,
            $reason
        ));

        // Store in transient for admin review (keeps last 100 fail-opens)
        $failOpens = get_transient('stride_vat_fail_opens') ?: [];
        $failOpens[] = [
            'vat' => $vatNumber,
            'reason' => $reason,
            'time' => current_time('mysql'),
        ];

        // Keep only last 100 entries
        $failOpens = array_slice($failOpens, -100);
        set_transient('stride_vat_fail_opens', $failOpens, WEEK_IN_SECONDS);
    }

    /**
     * Schedule async re-validation via Action Scheduler
     *
     * @param string $vatNumber The VAT number to re-validate
     */
    private function scheduleRevalidation(string $vatNumber): void
    {
        // Only schedule if Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        // Prevent duplicate scheduling
        $pending = as_get_scheduled_actions([
            'hook' => 'stride/vat/revalidate',
            'args' => [$vatNumber],
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ], 'ids');

        if (empty($pending)) {
            // Schedule for 1 hour from now (give VIES time to recover)
            as_schedule_single_action(
                time() + HOUR_IN_SECONDS,
                'stride/vat/revalidate',
                [$vatNumber],
                'stride'
            );
        }
    }

    /**
     * Re-validate a previously fail-opened VAT number (called by Action Scheduler)
     *
     * @param string $vatNumber The VAT number to re-validate
     */
    public function revalidateAsync(string $vatNumber): void
    {
        $vatNumber = $this->normalize($vatNumber);

        if (!$this->hasValidFormat($vatNumber)) {
            return;
        }

        $countryCode = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        try {
            $result = $this->checkVIES($countryCode, $number);

            // Update cache with actual VIES result
            $this->cacheResult($vatNumber, $result, self::CACHE_TTL);

            // Log successful re-validation
            if (!$result['valid']) {
                error_log(sprintf(
                    'Stride VAT re-validation: %s is INVALID (was previously fail-opened)',
                    $vatNumber
                ));

                // Fire action for handling invalid VAT numbers
                do_action('stride/vat/invalid_discovered', $vatNumber, $result);
            }
        } catch (\Exception $e) {
            // Still failing, log and reschedule
            error_log(sprintf(
                'Stride VAT re-validation failed again for %s: %s',
                $vatNumber,
                $e->getMessage()
            ));
        }
    }

    /**
     * Check VAT number against VIES SOAP API
     *
     * @param string $countryCode Two-letter country code
     * @param string $number VAT number without country prefix
     * @return array Validation result
     * @throws \Exception If SOAP request fails
     */
    private function checkVIES(string $countryCode, string $number): array
    {
        $client = new \SoapClient(self::VIES_WSDL, [
            'exceptions' => true,
            'connection_timeout' => 5,
            'default_socket_timeout' => 10,
            'cache_wsdl' => WSDL_CACHE_BOTH,
        ]);

        $response = $client->checkVat([
            'countryCode' => $countryCode,
            'vatNumber' => $number,
        ]);

        return [
            'valid' => (bool) $response->valid,
            'vat_number' => $countryCode . $number,
            'country_code' => $countryCode,
            'name' => $this->cleanVIESValue($response->name ?? null),
            'address' => $this->cleanVIESValue($response->address ?? null),
            'source' => 'vies',
        ];
    }

    /**
     * Clean VIES response value
     *
     * VIES sometimes returns "---" for unavailable data
     *
     * @param string|null $value Raw value from VIES
     * @return string|null Cleaned value
     */
    private function cleanVIESValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || trim($value) === '---') {
            return null;
        }
        return trim($value);
    }

    /**
     * Normalize VAT number
     *
     * Removes spaces, dots, dashes and converts to uppercase.
     *
     * @param string $vatNumber Raw VAT number
     * @return string Normalized VAT number
     */
    public function normalize(string $vatNumber): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber));
    }

    /**
     * Check if VAT number has valid format
     *
     * Basic format validation for common EU countries.
     * Allows through for VIES to do final validation.
     *
     * @param string $vatNumber Normalized VAT number
     * @return bool True if format appears valid
     */
    private function hasValidFormat(string $vatNumber): bool
    {
        // Minimum length check
        if (strlen($vatNumber) < 4) {
            return false;
        }

        $countryCode = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        // Must start with valid letters
        if (!ctype_alpha($countryCode)) {
            return false;
        }

        // Country-specific patterns for common EU countries
        $patterns = [
            // Belgium: BE + 10 digits (first digit 0 or 1)
            'BE' => '/^[01][0-9]{9}$/',

            // Netherlands: 9 digits + B + 2 digits
            'NL' => '/^[0-9]{9}B[0-9]{2}$/',

            // Germany: 9 digits
            'DE' => '/^[0-9]{9}$/',

            // France: 2 chars (letters or digits) + 9 digits
            'FR' => '/^[A-Z0-9]{2}[0-9]{9}$/',

            // Luxembourg: 8 digits
            'LU' => '/^[0-9]{8}$/',

            // Austria: U + 8 digits
            'AT' => '/^U[0-9]{8}$/',
        ];

        // If we have a specific pattern, validate against it
        if (isset($patterns[$countryCode])) {
            return (bool) preg_match($patterns[$countryCode], $number);
        }

        // For other EU countries, accept if it has alphanumeric content
        // VIES will do the final validation
        return strlen($number) >= 2 && preg_match('/^[A-Z0-9]+$/', $number);
    }

    /**
     * Get list of EU country codes
     *
     * @return array EU country codes
     */
    public static function getEUCountryCodes(): array
    {
        return [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
            'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
            // Note: 'XI' for Northern Ireland post-Brexit
            'XI',
        ];
    }

    /**
     * Check if country is in EU
     *
     * @param string $countryCode Two-letter country code
     * @return bool True if EU member
     */
    public static function isEUCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::getEUCountryCodes(), true);
    }

    /**
     * Get cached validation result
     *
     * Uses transients as fallback when object cache is not persistent.
     *
     * @param string $vatNumber Normalized VAT number
     * @return array|null Cached result or null if not found
     */
    private function getCachedResult(string $vatNumber): ?array
    {
        // Try object cache first
        $cached = wp_cache_get($vatNumber, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Fallback to transients for persistence
        $transientKey = self::TRANSIENT_PREFIX . md5($vatNumber);
        $cached = get_transient($transientKey);

        if ($cached !== false) {
            // Also populate object cache for faster subsequent access
            wp_cache_set($vatNumber, $cached, self::CACHE_GROUP, self::CACHE_TTL);
            return $cached;
        }

        return null;
    }

    /**
     * Cache validation result
     *
     * Uses both object cache and transients for reliability.
     *
     * @param string $vatNumber Normalized VAT number
     * @param array $result Validation result
     * @param int $ttl Cache TTL in seconds
     */
    private function cacheResult(string $vatNumber, array $result, int $ttl): void
    {
        // Object cache for fast access
        wp_cache_set($vatNumber, $result, self::CACHE_GROUP, $ttl);

        // Transient for persistence (in case object cache is non-persistent)
        $transientKey = self::TRANSIENT_PREFIX . md5($vatNumber);
        set_transient($transientKey, $result, $ttl);
    }

    /**
     * Get fail-open events for admin review
     *
     * @return array Array of fail-open events
     */
    public static function getFailOpenEvents(): array
    {
        return get_transient('stride_vat_fail_opens') ?: [];
    }

    /**
     * Clear fail-open events log
     */
    public static function clearFailOpenEvents(): void
    {
        delete_transient('stride_vat_fail_opens');
    }
}
