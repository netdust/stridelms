<?php

/**
 * NTDST Data Layer - Minimal ORM
 * Fast, cached, secure, zero dependencies
 *
 * Version 2.1 - Enhanced security, error handling, and extensibility
 */

defined('ABSPATH') || exit;

// Load QueryCache dependency
require_once __DIR__ . '/QueryCache.php';

class NTDST_Data_Model
{
    protected string $post_type;
    protected array $schema;
    protected array $query_args = [];
    protected int $cache_time;
    protected string $cache_group;
    protected array $sanitizers = [];
    protected array $validators = [];
    protected string $meta_prefix = '';

    public function __construct(string $post_type, array $schema = [], int $cache_time = 3600, string $meta_prefix = '')
    {
        $this->post_type = $post_type;
        $this->schema = $schema;
        $this->cache_group = 'ntdst_' . $post_type;
        $this->meta_prefix = $meta_prefix;

        // Delegate cache time resolution to QueryCache
        $query_cache = NTDST_Query_Cache::instance();
        $this->cache_time = $query_cache->resolveCacheTime($cache_time);
        $query_cache->registerMetaPrefix($this->meta_prefix);

        $this->setupSanitizers();
        $this->setupValidators();
    }

    /**
     * Get the meta prefix for this model
     */
    public function getMetaPrefix(): string
    {
        return $this->meta_prefix;
    }

    /**
     * Get the schema configuration
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Setup default sanitizers based on schema types
     */
    protected function setupSanitizers(): void
    {
        foreach ($this->schema as $field => $type) {
            // Extract sanitizer if provided as array ['type' => 'string', 'sanitizer' => 'callback']
            if (is_array($type)) {
                $extracted_type = $type['type'] ?? 'string';
                $this->sanitizers[$field] = $type['sanitizer'] ?? $this->getDefaultSanitizer($extracted_type);
                // DON'T simplify schema - preserve full config for metadata access
                // $this->schema[$field] = $extracted_type;  // ← REMOVED: This destroyed field metadata
            } else {
                $this->sanitizers[$field] = $this->getDefaultSanitizer($type);
            }
        }
    }

    /**
     * Setup validators based on schema
     */
    protected function setupValidators(): void
    {
        foreach ($this->schema as $field => $type_config) {
            if (is_array($type_config)) {
                $this->validators[$field] = [
                    'required' => $type_config['required'] ?? false,
                    'min' => $type_config['min'] ?? null,
                    'max' => $type_config['max'] ?? null,
                    'validate' => $type_config['validate'] ?? null,
                ];
            }
        }
    }

    /**
     * Get default sanitizer for a field type
     */
    protected function getDefaultSanitizer(string $type): callable
    {
        return match ($type) {
            'int', 'integer' => 'absint',
            'float', 'double' => 'floatval',
            'bool', 'boolean' => fn($v) => $this->sanitizeBoolean($v),
            'email' => 'sanitize_email',
            'url' => 'esc_url_raw',
            'text' => 'sanitize_text_field',
            'textarea' => 'sanitize_textarea_field',
            'html', 'content' => fn($v) => wp_kses_post($v),
            'array' => fn($v) => is_array($v) ? $this->sanitizeNestedArray($v) : [],
            'json' => fn($v) => $this->sanitizeJson($v),
            'relation' => fn($v) => is_array($v) ? array_map('absint', array_filter($v)) : (!empty($v) ? [absint($v)] : []),
            'gallery' => fn($v) => is_array($v) ? array_map('absint', array_filter($v)) : [],
            'repeater' => fn($v) => is_array($v) ? $this->sanitizeRepeater($v) : [],
            default => 'sanitize_text_field',
        };
    }

    /**
     * Sanitize boolean values without treating non-empty strings like "false" as true.
     */
    protected function sanitizeBoolean($value): bool
    {
        if (function_exists('wp_validate_boolean')) {
            return wp_validate_boolean($value);
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    /**
     * Sanitize JSON-like values to an array and reject invalid JSON strings.
     */
    protected function sanitizeJson($value): array
    {
        if (is_array($value)) {
            return $this->sanitizeNestedArray($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $this->sanitizeNestedArray($decoded) : [];
    }

    /**
     * Recursively sanitize scalar values in a nested array while preserving structure.
     */
    protected function sanitizeNestedArray(array $value): array
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized_key = is_string($key) ? sanitize_key($key) : $key;
            if (is_array($item)) {
                $sanitized[$sanitized_key] = $this->sanitizeNestedArray($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $sanitized[$sanitized_key] = $item;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field($item);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize field value based on schema
     */
    protected function sanitizeField(string $field, $value)
    {
        if (!isset($this->sanitizers[$field])) {
            return sanitize_text_field($value);
        }

        $sanitizer = $this->sanitizers[$field];

        if (is_callable($sanitizer)) {
            return $sanitizer($value);
        }

        return $value;
    }

    /**
     * Sanitize repeater field data
     *
     * @param array $rows Array of repeater rows
     * @return array Sanitized repeater data
     */
    protected function sanitizeRepeater(array $rows): array
    {
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        $sanitized_rows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sanitized_row = [];
            foreach ($row as $sub_field => $value) {
                // Sanitize each sub-field value
                $sanitized_row[$sub_field] = sanitize_text_field($value);
            }

            // Only add row if it has data
            if (!empty(array_filter($sanitized_row))) {
                $sanitized_rows[] = $sanitized_row;
            }
        }

        return $sanitized_rows;
    }

    /**
     * Format repeater field data on load
     *
     * @param mixed $value Raw repeater data from database
     * @return array Formatted repeater data
     */
    protected function formatRepeaterField($value): array
    {
        // Handle null/empty
        if (empty($value)) {
            return [];
        }

        // If it's a JSON string, decode it
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                // Not valid JSON, try unserialize (legacy)
                $unserialized = @unserialize($value);
                $value = is_array($unserialized) ? $unserialized : [];
            }
        }

        // Ensure it's an array
        if (!is_array($value)) {
            return [];
        }

        // Ensure each row is an array
        $formatted_rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $formatted_rows[] = $row;
            }
        }

        return $formatted_rows;
    }

    /**
     * Sanitize all data based on schema
     */
    protected function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Handle core WordPress fields
            // Note: Use 'post_status' not 'status' to avoid conflict with custom meta fields named 'status'
            if (in_array($key, ['title', 'content', 'excerpt', 'post_status'])) {
                $sanitized[$key] = match ($key) {
                    'title' => sanitize_text_field($value),
                    'content' => wp_kses_post($value),
                    'excerpt' => sanitize_textarea_field($value),
                    'post_status' => in_array($value, ['publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'future']) ? $value : 'draft',
                    default => sanitize_text_field($value),
                };
            } else {
                // Custom field - use schema sanitizer
                $sanitized[$key] = $this->sanitizeField($key, $value);
            }
        }

        return $sanitized;
    }

    /**
     * Validate data based on schema rules
     *
     * @param array $data Data to validate
     * @param bool $isUpdate Whether this is an update operation (skips required validation for missing fields)
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    protected function validateData(array $data, bool $isUpdate = false)
    {
        $errors = [];

        foreach ($this->validators as $field => $rules) {
            $has_value = array_key_exists($field, $data);
            $value = $has_value ? $data[$field] : null;
            $is_empty = $value === null || $value === '' || (is_array($value) && $value === []);

            // Check required - only for create operations. For updates, missing fields keep existing values.
            if ($rules['required'] && !$isUpdate && (!$has_value || $is_empty)) {
                $errors[$field][] = sprintf('%s is required', $field);
                continue;
            }

            // Skip validations for fields that are not part of a partial update.
            if (!$has_value) {
                continue;
            }

            // Optional empty values are allowed, but explicit false/0/'0' still validate below.
            if ($is_empty && !$rules['required']) {
                continue;
            }

            // Check min (for strings, numbers, arrays)
            if ($rules['min'] !== null) {
                if (is_string($value) && strlen($value) < $rules['min']) {
                    $errors[$field][] = sprintf('%s must be at least %d characters', $field, $rules['min']);
                } elseif (is_numeric($value) && $value < $rules['min']) {
                    $errors[$field][] = sprintf('%s must be at least %s', $field, $rules['min']);
                } elseif (is_array($value) && count($value) < $rules['min']) {
                    $errors[$field][] = sprintf('%s must have at least %d items', $field, $rules['min']);
                }
            }

            // Check max (for strings, numbers, arrays)
            if ($rules['max'] !== null) {
                if (is_string($value) && strlen($value) > $rules['max']) {
                    $errors[$field][] = sprintf('%s must be no more than %d characters', $field, $rules['max']);
                } elseif (is_numeric($value) && $value > $rules['max']) {
                    $errors[$field][] = sprintf('%s must be no more than %s', $field, $rules['max']);
                } elseif (is_array($value) && count($value) > $rules['max']) {
                    $errors[$field][] = sprintf('%s must have no more than %d items', $field, $rules['max']);
                }
            }

            // Custom validation callback
            if ($rules['validate'] && is_callable($rules['validate'])) {
                $result = call_user_func($rules['validate'], $value);
                if ($result !== true) {
                    $errors[$field][] = is_string($result) ? $result : sprintf('%s validation failed', $field);
                }
            }
        }

        if (!empty($errors)) {
            $error_messages = [];
            foreach ($errors as $field => $messages) {
                $error_messages[] = implode(', ', $messages);
            }
            return new WP_Error('validation_failed', implode('; ', $error_messages), ['errors' => $errors]);
        }

        return true;
    }

    /**
     * Create a new post
     *
     * @param array $data Post and meta data
     * @return object|WP_Error Post object or error
     */
    public function create(array $data)
    {
        // Validate input
        $validation = $this->validateData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Sanitize input
        $data = $this->sanitizeData($data);

        // Fire before hook
        do_action('ntdst_model_create_before', $this->post_type, $data);

        $post_id = wp_insert_post(array_merge(
            $this->extractPostData($data),
            ['post_type' => $this->post_type, 'post_status' => $data['post_status'] ?? 'publish'],
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Save meta data. Roll back the post if any meta write genuinely fails.
        foreach ($this->extractMetaData($data) as $key => $value) {
            if (!$this->updateMetaValue($post_id, $key, $value)) {
                wp_delete_post($post_id, true);
                $this->clearCache($post_id);
                return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $key), ['status' => 500]);
            }
        }

        $this->clearCache($post_id);

        // Fire after hook
        do_action('ntdst_model_create_after', $this->post_type, $post_id, $data);

        // Return the newly created post with meta/fields
        return $this->find($post_id, true);
    }

    /**
     * Update an existing post
     *
     * @param int $id Post ID
     * @param array $data Data to update
     * @return object|WP_Error Post object or error
     */
    public function update(int $id, array $data)
    {
        // Check if post exists
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Validate input - isUpdate=true skips required field validation for missing fields
        $validation = $this->validateData($data, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Sanitize input
        $data = $this->sanitizeData($data);

        // Fire before hook
        do_action('ntdst_model_update_before', $this->post_type, $id, $data);

        $post_data = $this->extractPostData($data);
        $previous_post_data = [];
        foreach (array_keys($post_data) as $post_field) {
            $previous_post_data[$post_field] = $existing->{$post_field} ?? null;
        }

        // Update post data
        if ($post_data) {
            $result = wp_update_post($post_data + ['ID' => $id], true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $meta_data = $this->extractMetaData($data);
        $previous_meta = [];
        foreach ($meta_data as $key => $value) {
            $previous_meta[$key] = [
                'exists' => metadata_exists('post', $id, $key),
                'value' => get_post_meta($id, $key, true),
            ];

            if (!$this->updateMetaValue($id, $key, $value)) {
                $this->restorePostData($id, $previous_post_data);
                $this->restoreMetaData($id, $previous_meta);
                $this->clearCache($id);
                return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $key), ['status' => 500]);
            }
        }

        $this->clearCache($id);

        // Fire after hook
        do_action('ntdst_model_update_after', $this->post_type, $id, $data);

        // Return fresh data (skip cache since we just mutated)
        return $this->find($id, true);
    }

    /**
     * Find a post by ID
     *
     * @param int $id Post ID
     * @param bool $skipCache Skip cache (use after mutations)
     * @return object|WP_Error Post object or error
     */
    public function find(int $id, bool $skipCache = false)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            NTDST_Data_Manager::clearCache($id);
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        $cache_time = $skipCache ? 0 : $this->cache_time;
        $post->meta = NTDST_Data_Manager::getPostMetaFromCache($id, $cache_time);
        $post->fields = $this->formatMeta($post->meta);

        return $post;
    }

    /**
     * Get meta value(s) for a post - convenience method with automatic error handling
     *
     * @param int $id Post ID
     * @param string|null $key Meta key (null = return all meta)
     * @param mixed $default Default value if not found or error
     * @return mixed Meta value, all meta array, or default
     */
    public function getMeta(int $id, ?string $key = null, $default = null)
    {
        $post = $this->find($id);

        // Return default on error
        if (is_wp_error($post)) {
            return $default;
        }

        $meta = $post->fields ?? [];

        // Return all meta if no key specified
        if ($key === null) {
            return $meta;
        }

        // Return specific key with default fallback
        return $meta[$key] ?? $default;
    }

    /**
     * Update a prefixed meta key and verify the write. WordPress returns false for both
     * failures and unchanged values, so confirm the stored value before treating false as an error.
     */
    protected function updateMetaValue(int $id, string $metaKey, $value): bool
    {
        $result = update_post_meta($id, $metaKey, $value);

        if ($result !== false) {
            return true;
        }

        return $this->valuesMatch(get_post_meta($id, $metaKey, true), $value);
    }

    /**
     * Restore post-table fields after a partial update failure.
     */
    protected function restorePostData(int $id, array $previousPostData): void
    {
        if (empty($previousPostData)) {
            return;
        }

        wp_update_post($previousPostData + ['ID' => $id], true);
    }

    /**
     * Restore meta fields after a partial update failure.
     */
    protected function restoreMetaData(int $id, array $previousMeta): void
    {
        foreach ($previousMeta as $key => $snapshot) {
            $exists = is_array($snapshot) ? ($snapshot['exists'] ?? true) : true;
            $value = is_array($snapshot) && array_key_exists('value', $snapshot) ? $snapshot['value'] : $snapshot;

            if ($exists) {
                update_post_meta($id, $key, $value);
            } else {
                delete_post_meta($id, $key);
            }
        }
    }

    /**
     * Compare stored and intended values after WordPress maybe_unserialize handling.
     */
    protected function valuesMatch($stored, $expected): bool
    {
        if ($stored === $expected) {
            return true;
        }

        return maybe_serialize($stored) === maybe_serialize($expected);
    }

    /**
     * Update meta value for a post - convenience method with automatic error handling
     *
     * @param int $id Post ID
     * @param string $key Meta key (unprefixed - prefix applied automatically)
     * @param mixed $value Meta value
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function updateMeta(int $id, string $key, $value)
    {
        // Verify post exists
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Sanitize value based on this model's field schema.
        $fieldSchema = $this->schema[$key] ?? null;
        if ($fieldSchema && is_array($fieldSchema)) {
            $fieldType = $fieldSchema['type'] ?? 'text';
            $sanitizer = $this->getDefaultSanitizer($fieldType);
            if (is_callable($sanitizer)) {
                $value = $sanitizer($value);
            }
        }

        $metaKey = $this->prefixMetaKey($key);
        if (!$this->updateMetaValue($id, $metaKey, $value)) {
            return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $metaKey), ['status' => 500]);
        }

        // Clear cache for this post
        $this->clearCache($id);

        return true;
    }

    /**
     * Update multiple meta values for a post at once.
     *
     * This is the batch version of updateMeta() - use this when updating
     * multiple fields to avoid repeated cache clearing and validation.
     *
     * @param int $id Post ID
     * @param array<string, mixed> $data Associative array of key => value pairs (unprefixed)
     * @return bool True if all updates succeeded, false if any failed
     */
    public function updateMetaBatch(int $id, array $data): bool
    {
        // Verify post exists once
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return false;
        }

        $previousMeta = [];

        foreach ($data as $key => $value) {
            // Get field schema for sanitization (schema IS the fields array)
            $fieldSchema = $this->schema[$key] ?? null;
            if ($fieldSchema && is_array($fieldSchema)) {
                $fieldType = $fieldSchema['type'] ?? 'text';
                $sanitizer = $this->getDefaultSanitizer($fieldType);
                if (is_callable($sanitizer)) {
                    $value = $sanitizer($value);
                }
            }

            $metaKey = $this->prefixMetaKey($key);
            $previousMeta[$metaKey] = [
                'exists' => metadata_exists('post', $id, $metaKey),
                'value' => get_post_meta($id, $metaKey, true),
            ];

            if (!$this->updateMetaValue($id, $metaKey, $value)) {
                $this->restoreMetaData($id, $previousMeta);
                $this->clearCache($id);
                return false;
            }
        }

        // Clear cache once after all updates
        $this->clearCache($id);

        return true;
    }

    /**
     * Delete meta value for a post - convenience method
     *
     * @param int $id Post ID
     * @param string $key Meta key (unprefixed - prefix applied automatically)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function deleteMeta(int $id, string $key)
    {
        // Verify post exists
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Delete meta with prefix
        $metaKey = $this->prefixMetaKey($key);
        $result = delete_post_meta($id, $metaKey);

        // Clear cache for this post
        $this->clearCache($id);

        return $result;
    }

    /**
     * Delete a post
     *
     * @param int $id Post ID
     * @param bool $force Force delete (bypass trash)
     * @return bool|WP_Error Success or error
     */
    public function delete(int $id, bool $force = false)
    {
        // Check if post exists
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Fire before hook
        do_action('ntdst_model_delete_before', $this->post_type, $id);

        $result = $force ? wp_delete_post($id, true) : wp_trash_post($id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }

        $this->clearCache($id);

        // Fire after hook
        do_action('ntdst_model_delete_after', $this->post_type, $id);

        return true;
    }

    /**
     * Query builder - where clause
     */
    public function where(string $field, $value): self
    {
        // List of WordPress core post table fields that should be queried directly
        $core_fields = [
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_modified', 'menu_order', 'comment_status',
            'ping_status', 'post_password', 'post_name', 'post_mime_type',
        ];

        if (in_array($field, $core_fields)) {
            // Core WordPress field - add directly to query_args
            // Map post_name to 'name' for WP_Query compatibility.
            $queryKey = ($field === 'post_name') ? 'name' : $field;
            $this->query_args[$queryKey] = $value;
        } else {
            // Custom meta field - use meta_query with prefix
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $metaKey = $this->prefixMetaKey($field);
            $this->query_args['meta_query'][] = is_array($value) && count($value) === 2
                ? ['key' => $metaKey, 'value' => $value[1], 'compare' => $value[0]]
                : ['key' => $metaKey, 'value' => $value];
        }

        return $this;
    }

    /**
     * Query builder - where NOT clause
     *
     * For core fields: uses post__not_in for IDs, or excludes via post_status array.
     * For meta fields: uses meta_query with != compare.
     */
    public function whereNot(string $field, $value): self
    {
        // List of WordPress core post table fields
        $core_fields = [
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_modified', 'menu_order', 'comment_status',
            'ping_status', 'post_password', 'post_name', 'post_mime_type',
        ];

        if (in_array($field, $core_fields)) {
            // Handle core field negation
            if ($field === 'post_status') {
                // For post_status, we need to explicitly include all OTHER statuses
                $all_statuses = ['publish', 'draft', 'pending', 'private', 'future', 'inherit'];
                $exclude = is_array($value) ? $value : [$value];
                $this->query_args['post_status'] = array_diff($all_statuses, $exclude);
            } elseif ($field === 'post_author') {
                // For author, use author__not_in
                $this->query_args['author__not_in'] = is_array($value) ? $value : [$value];
            } elseif ($field === 'post_parent') {
                // For parent, use post_parent__not_in
                $this->query_args['post_parent__not_in'] = is_array($value) ? $value : [$value];
            } else {
                // Other core fields - WP_Query doesn't support != for these
                // Throw exception to fail loudly rather than silently returning wrong results
                $this->query_args = [];
                throw new \InvalidArgumentException(
                    "whereNot() does not support negation for core field '{$field}'. " .
                    "Supported fields: post_status, post_author, post_parent. " .
                    "For other fields, use a custom meta field or filter results in PHP."
                );
            }
        } else {
            // Custom meta field - use meta_query with != compare
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $this->query_args['meta_query'][] = [
                'key' => $this->prefixMetaKey($field),
                'value' => $value,
                'compare' => '!='
            ];
        }

        return $this;
    }

    /**
     * Query builder - where IN clause (for post IDs)
     *
     * @param string $field Field name ('ID' for post IDs)
     * @param array $values Array of values
     * @return self
     *
     * Example:
     * $model->whereIn('ID', [1, 2, 3])->get();
     */
    public function whereIn(string $field, array $values): self
    {
        if ($field === 'ID') {
            $this->query_args['post__in'] = array_map('intval', $values);
        } else {
            // For meta fields, use meta_query with IN comparison and prefix
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $this->query_args['meta_query'][] = [
                'key' => $this->prefixMetaKey($field),
                'value' => $values,
                'compare' => 'IN'
            ];
        }

        return $this;
    }

    /**
     * Query builder - limit
     */
    public function limit(int $limit): self
    {
        $this->query_args['posts_per_page'] = $limit;
        return $this;
    }

    /**
     * Query builder - order by
     *
     * Supports both core WP fields (date, title, menu_order, etc.)
     * and custom meta fields (which will be prefixed automatically).
     *
     * @param string $field Field to order by
     * @param string $dir Direction: ASC or DESC
     * @param bool $numeric Use numeric ordering for meta values (meta_value_num)
     * @return self
     */
    public function orderBy(string $field, string $dir = 'DESC', bool $numeric = false): self
    {
        // Common WordPress column aliases → WP_Query orderby values
        // These map actual database column names to WP_Query's expected values
        $columnAliases = [
            'post_date' => 'date',
            'post_modified' => 'modified',
            'post_title' => 'title',
            'post_name' => 'name',
            'post_author' => 'author',
            'post_parent' => 'parent',
            'post_type' => 'type',
        ];

        // Core WordPress orderby values that don't need meta handling
        $coreOrderBy = [
            'none', 'ID', 'author', 'title', 'name', 'type', 'date',
            'modified', 'parent', 'rand', 'comment_count', 'relevance',
            'menu_order', 'meta_value', 'meta_value_num', 'post__in',
            'post_name__in', 'post_parent__in',
        ];

        // Apply alias mapping if applicable
        $orderByField = $columnAliases[$field] ?? $field;

        if (in_array($orderByField, $coreOrderBy, true)) {
            $this->query_args['orderby'] = $orderByField;
        } else {
            // Custom meta field - set up meta ordering with prefix
            $this->query_args['meta_key'] = $this->prefixMetaKey($field);
            $this->query_args['orderby'] = $numeric ? 'meta_value_num' : 'meta_value';
        }

        $this->query_args['order'] = strtoupper($dir);
        return $this;
    }

    /**
     * Query builder - taxonomy where clause
     *
     * @param string $taxonomy Taxonomy name
     * @param string|int|array $terms Term slug, ID, or array of slugs/IDs
     * @param string $field Field to match (term_id, slug, name) - default: slug
     * @param string $operator Operator (IN, NOT IN, AND) - default: IN
     * @return $this
     *
     * Example:
     * $model->whereTax('category', 'web-design')->get();
     * $model->whereTax('category', ['web-design', 'mobile'], 'slug', 'AND')->get();
     */
    public function whereTax(string $taxonomy, $terms, string $field = 'slug', string $operator = 'IN'): self
    {
        if (!isset($this->query_args['tax_query'])) {
            $this->query_args['tax_query'] = [];
        }

        $this->query_args['tax_query'][] = [
            'taxonomy' => $taxonomy,
            'field' => $field,
            'terms' => is_array($terms) ? $terms : [$terms],
            'operator' => strtoupper($operator),
        ];

        return $this;
    }

    /**
     * Query builder - date where clause
     *
     * @param string $column Date column (post_date, post_modified, etc.)
     * @param string $compare Comparison operator (=, !=, >, >=, <, <=, BETWEEN, NOT BETWEEN)
     * @param string|array $value Date value or array of dates for BETWEEN
     * @return $this
     *
     * Example:
     * $model->whereDate('post_date', '>=', '2024-01-01')->get();
     * $model->whereDate('post_date', 'BETWEEN', ['2024-01-01', '2024-12-31'])->get();
     */
    public function whereDate(string $column = 'post_date', string $compare = '=', $value = null): self
    {
        if (!isset($this->query_args['date_query'])) {
            $this->query_args['date_query'] = [];
        }

        if ($compare === 'BETWEEN' || $compare === 'NOT BETWEEN') {
            $dates = is_array($value) ? $value : [$value, $value];
            $this->query_args['date_query'][] = [
                'column' => $column,
                'after' => $dates[0],
                'before' => $dates[1] ?? $dates[0],
                'inclusive' => true,
            ];
        } else {
            $this->query_args['date_query'][] = [
                'column' => $column,
                'compare' => $compare,
                'value' => $value,
            ];
        }

        return $this;
    }

    /**
     * Query builder - OR where clause (starts a new OR relation)
     *
     * @return $this
     *
     * Note: this creates one flat root-level OR meta_query. It cannot express
     * nested groups like A AND (B OR C); use a custom meta_query for those cases.
     *
     * Example:
     * $model->where('featured', true)
     * ->orWhere('price', ['<', 100])
     * ->get();
     */
    public function orWhere(string $field, $value): self
    {
        if (!isset($this->query_args['meta_query'])) {
            $this->query_args['meta_query'] = ['relation' => 'OR'];
        } elseif (!isset($this->query_args['meta_query']['relation'])) {
            // Convert existing queries to OR relation
            $this->query_args['meta_query']['relation'] = 'OR';
        }

        $metaKey = $this->prefixMetaKey($field);
        $this->query_args['meta_query'][] = is_array($value) && count($value) === 2
            ? ['key' => $metaKey, 'value' => $value[1], 'compare' => $value[0]]
            : ['key' => $metaKey, 'value' => $value];

        return $this;
    }

    /**
     * Attach taxonomy terms to a post
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs
     * @param bool $append Append to existing terms (true) or replace (false)
     * @return bool|WP_Error
     *
     * Example:
     * $model->attachTerms(123, 'category', [1, 2, 3]);
     */
    public function attachTerms(int $post_id, string $taxonomy, array $term_ids, bool $append = true)
    {
        $result = wp_set_post_terms($post_id, $term_ids, $taxonomy, $append);

        if (is_wp_error($result)) {
            return $result;
        }

        $this->clearCache($post_id);
        NTDST_Data_Manager::clearCache($post_id);
        return true;
    }

    /**
     * Sync taxonomy terms (replace all existing terms)
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs
     * @return bool|WP_Error
     *
     * Example:
     * $model->syncTerms(123, 'category', [1, 2, 3]);
     */
    public function syncTerms(int $post_id, string $taxonomy, array $term_ids)
    {
        return $this->attachTerms($post_id, $taxonomy, $term_ids, false);
    }

    /**
     * Detach taxonomy terms from a post
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs to remove (empty array removes all)
     * @return bool|WP_Error
     *
     * Example:
     * $model->detachTerms(123, 'category', [1, 2]);
     * $model->detachTerms(123, 'category', []); // Remove all
     */
    public function detachTerms(int $post_id, string $taxonomy, array $term_ids = [])
    {
        if (empty($term_ids)) {
            $result = wp_set_post_terms($post_id, [], $taxonomy, false);
        } else {
            $existing = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
            $remaining = array_diff($existing, $term_ids);
            $result = wp_set_post_terms($post_id, $remaining, $taxonomy, false);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $this->clearCache($post_id);
        NTDST_Data_Manager::clearCache($post_id);
        return true;
    }

    /**
     * Include post meta in results
     *
     * @return self
     *
     * Example:
     * $posts = $model->withMeta()->get();
     */
    public function withMeta(): self
    {
        $this->query_args['include_meta'] = true;
        return $this;
    }

    /**
     * Include taxonomy terms in results
     *
     * @return self
     *
     * Example:
     * $posts = $model->withTerms()->get();
     */
    public function withTerms(): self
    {
        $this->query_args['include_terms'] = true;
        return $this;
    }

    /**
     * Set custom cache time for this query
     *
     * @param int $seconds Cache duration in seconds (0 = no cache)
     * @return self
     *
     * Example:
     * $posts = $model->cache(7200)->get(); // 2 hours
     * $posts = $model->cache(0)->get(); // No cache
     */
    public function cache(int $seconds): self
    {
        $this->query_args['cache_time'] = $seconds;
        return $this;
    }

    /**
     * Execute query and get results
     */
    public function get(): array
    {
        try {
            // Use our super-fast query system
            return NTDST_Data_Manager::getPostsFast(array_merge([
                'post_type' => $this->post_type,
                'cache_time' => $this->cache_time,
            ], $this->query_args));
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Get first result as a WP_Post with model meta/fields attached, matching find().
     */
    public function first()
    {
        $cache_time = $this->query_args['cache_time'] ?? $this->cache_time;
        $results = $this->limit(1)->get();
        if (!$results) {
            return null;
        }

        return $this->hydratePostFromResult($results[0], $cache_time);
    }

    /**
     * Get all results
     */
    public function all(int $limit = -1): array
    {
        return $this->limit($limit)->get();
    }

    /**
     * Count results
     */
    public function count(): int
    {
        try {
            return $this->cachedCount($this->query_args);
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Paginate results
     *
     * @param int $page Current page (1-indexed)
     * @param int $per_page Items per page
     * @return array ['data' => [], 'pagination' => [...]]
     */
    public function paginate(int $page = 1, int $per_page = 10): array
    {
        $page = max(1, $page);
        $per_page = max(1, $per_page);
        $offset = ($page - 1) * $per_page;

        try {
            // Get total count first
            $total = $this->cachedCount($this->query_args);
            $total_pages = (int) ceil($total / $per_page);

            // Get paginated results
            $this->query_args['posts_per_page'] = $per_page;
            $this->query_args['offset'] = $offset;

            $posts = NTDST_Data_Manager::getPostsFast(array_merge([
                'post_type' => $this->post_type,
                'cache_time' => $this->cache_time,
            ], $this->query_args));

            return [
                'data' => $posts,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $per_page, $total),
                ],
            ];
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Count matching posts with the same versioned QueryCache used for data queries.
     */
    protected function cachedCount(array $query_args): int
    {
        $queryCache = NTDST_Query_Cache::instance();
        $cache_time = $queryCache->resolveCacheTime($query_args['cache_time'] ?? $this->cache_time);
        unset($query_args['cache_time'], $query_args['include_meta'], $query_args['include_terms']);

        $count_args = array_merge([
            'post_type' => $this->post_type,
        ], $query_args, [
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'cache_count' => true,
        ]);

        $cache_key = $queryCache->generateKey($count_args, $this->post_type);
        $cache_group = $queryCache->getGroup($this->post_type);

        if ($cache_time > 0) {
            $cached = $queryCache->get($cache_key, $cache_group);
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        $wp_query_args = $count_args;
        unset($wp_query_args['cache_count']);

        $query = new WP_Query($wp_query_args);
        $total = (int) $query->found_posts;

        if ($cache_time > 0) {
            $queryCache->set($cache_key, $cache_group, $total, $cache_time);
        }

        return $total;
    }

    /**
     * Hydrate a fast-query result into the same WP_Post shape returned by find().
     */
    protected function hydratePostFromResult(array $item, ?int $cache_time = null)
    {
        $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            NTDST_Data_Manager::clearCache($id);
            return null;
        }

        $post->meta = $item['meta'] ?? NTDST_Data_Manager::getPostMetaFromCache($id, $cache_time ?? $this->cache_time);
        $post->fields = $this->formatMeta($post->meta);
        if (isset($item['terms'])) {
            $post->terms = $item['terms'];
        }

        return $post;
    }

    /**
     * Clear cache for a specific post or all posts of this type
     *
     * For single post: clears item cache and invalidates query caches via version bump
     * Without ID: invalidates all query caches for this post type
     */
    protected function clearCache(?int $id = null): void
    {
        $cache = NTDST_Query_Cache::instance();

        if ($id !== null) {
            // Clear specific item cache
            wp_cache_delete('item_' . $id, $this->cache_group);
            // Clear manager caches for this post
            NTDST_Data_Manager::clearCache($id);
        }

        // Invalidate all query caches for this post type via version bump
        $cache->invalidatePostType($this->post_type);
    }

    /**
     * Extract WordPress post data from input
     */
    protected function extractPostData(array $data): array
    {
        $post = [];
        // Note: Use 'post_status' not 'status' - 'status' is commonly used as meta field name
        foreach (['title', 'content', 'excerpt', 'post_status'] as $field) {
            if (isset($data[$field])) {
                // post_status already has prefix, others need it
                $key = ($field === 'post_status') ? $field : 'post_' . $field;
                $post[$key] = $data[$field];
            }
        }
        return $post;
    }

    /**
     * Extract custom meta data from input
     *
     * Filters out WordPress post fields, keeping only meta fields.
     * Applies meta_prefix if configured.
     * Note: Uses 'post_status' not 'status' since 'status' is commonly
     * used as a meta field name (e.g., order fulfillment status).
     */
    protected function extractMetaData(array $data): array
    {
        $meta = array_diff_key($data, array_flip(['title', 'content', 'excerpt', 'post_status']));

        // Apply prefix if configured
        if ($this->meta_prefix !== '') {
            $prefixed = [];
            foreach ($meta as $key => $value) {
                $prefixed[$this->meta_prefix . $key] = $value;
            }
            return $prefixed;
        }

        return $meta;
    }

    /**
     * Add meta prefix to a key
     */
    protected function prefixMetaKey(string $key): string
    {
        return $this->meta_prefix . $key;
    }

    /**
     * Strip meta prefix from a key
     */
    protected function stripMetaPrefix(string $key): string
    {
        if ($this->meta_prefix !== '' && str_starts_with($key, $this->meta_prefix)) {
            return substr($key, strlen($this->meta_prefix));
        }
        return $key;
    }

    /**
     * Decode array-like stored values without returning null on invalid JSON.
     */
    protected function decodeArrayField($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Format meta according to schema with sanitization
     *
     * Handles meta_prefix: looks up prefixed keys in raw meta,
     * returns unprefixed keys in formatted result.
     */
    protected function formatMeta(array $meta): array
    {
        if (empty($this->schema)) {
            // No schema - strip prefixes from all keys if prefix is set
            if ($this->meta_prefix !== '') {
                $unprefixed = [];
                foreach ($meta as $key => $value) {
                    $unprefixed[$this->stripMetaPrefix($key)] = $value;
                }
                return $unprefixed;
            }
            return $meta;
        }

        $formatted = [];
        foreach ($this->schema as $field => $type_config) {
            // Look up the prefixed key in meta, return unprefixed field name
            $metaKey = $this->meta_prefix . $field;
            $value = $meta[$metaKey] ?? $meta[$field] ?? null;

            // Extract type string from config array if needed
            $type = is_array($type_config) ? ($type_config['type'] ?? 'text') : $type_config;

            // Type cast (with null safety for json_decode in PHP 8.1+)
            $formatted[$field] = match ($type) {
                'int', 'integer' => (int) $value,
                'float', 'double' => (float) $value,
                'bool', 'boolean' => $this->sanitizeBoolean($value),
                'array' => is_array($value) ? $value : [],
                'json' => $this->decodeArrayField($value),
                'relation' => array_map('intval', $this->decodeArrayField($value)),
                'gallery' => array_map('intval', $this->decodeArrayField($value)),
                'repeater' => $this->formatRepeaterField($value),
                default => is_array($value) ? json_encode($value) : (string) ($value ?? ''),
            };

            // Additional sanitization for simple arrays only (not JSON/nested structures)
            if ($type === 'array' && is_array($formatted[$field])) {
                $formatted[$field] = $this->sanitizeNestedArray($formatted[$field]);
            }
            // JSON fields are already sanitized when saved, don't re-sanitize on output
        }

        return $formatted;
    }
}

/**
 * Data Manager - Registry for data models
 */
class NTDST_Data_Manager
{
    protected static array $models = [];

    /**
     * Register a new data model
     *
     * @param string $name Post type name
     * @param array $config Configuration
     * @return NTDST_Data_Model
     */
    public function register(string $name, array $config = []): NTDST_Data_Model
    {
        // Fire hook so services can add their filters (e.g., PriceableFieldsService)
        do_action('ntdst/model/registering', $name, $config);

        // Apply field and field_group filters (allows services to inject fields)
        $config['fields'] = apply_filters("ntdst/{$name}/fields", $config['fields'] ?? []);
        $config['field_groups'] = apply_filters("ntdst/{$name}/field_groups", $config['field_groups'] ?? []);

        if (isset($config['label'])) {
            register_post_type($name, array_merge([
                'public' => true,
                'has_archive' => true,
                'supports' => ['title', 'editor', 'thumbnail'],
            ], array_diff_key($config, array_flip(['fields', 'cache_time', 'field_groups', 'meta_prefix', 'auto_metabox']))));
        }

        $model = new NTDST_Data_Model(
            $name,
            $config['fields'] ?? [],
            $config['cache_time'] ?? 3600,
            $config['meta_prefix'] ?? '',
        );

        self::$models[$name] = $model;

        // Auto-register metabox if this model has fields and is registered as a post type
        if (!empty($config['fields']) && isset($config['label']) && ($config['auto_metabox'] ?? true)) {
            // Assumes ntdst_metabox() returns a valid metabox manager object
            if (function_exists('ntdst_metabox')) {
                ntdst_metabox()->register($name, $config);
            }
        }

        // Fire hook after registration complete
        do_action('ntdst/model/registered', $name, $config);

        return $model;
    }

    /**
     * Get a registered data model
     *
     * @param string $name Model name
     * @return NTDST_Data_Model
     */
    public function get(string $name): NTDST_Data_Model
    {
        return self::$models[$name] ?? $this->register($name);
    }

    /**
     * Get post meta from WordPress cache (after update_postmeta_cache primed it)
     *
     * This is the optimized version that reads from primed cache.
     * Falls back to getCachedPostMeta() if cache is not primed.
     *
     * @param int $post_id Post ID
     * @param int $cache_time Cache duration in seconds
     * @return array Post meta data
     */
    public static function getPostMetaFromCache(int $post_id, int $cache_time = 3600): array
    {
        $key = "post_meta_{$post_id}";
        $cache_group = 'ntdst_posts';

        // Only check ntdst cache if caching is enabled (cache_time > 0)
        if ($cache_time > 0) {
            $cached = wp_cache_get($key, $cache_group);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Try WordPress's primed meta cache (from update_postmeta_cache)
        $wp_cached = wp_cache_get($post_id, 'post_meta');
        if ($wp_cached !== false && is_array($wp_cached)) {
            $meta = [];
            foreach ($wp_cached as $meta_key => $values) {
                // WordPress stores meta as array of values, we want single value
                $meta[$meta_key] = maybe_unserialize($values[0] ?? $values);
            }

            // Store in our cache for consistency
            if ($cache_time > 0) {
                wp_cache_set($key, $meta, $cache_group, $cache_time);
            }

            return $meta;
        }

        // Fallback to the original method (raw SQL) if WP cache not available
        return self::getCachedPostMeta($post_id, $cache_time);
    }

    /**
     * Get post terms from WordPress cache (after update_object_term_cache primed it)
     *
     * This is the optimized version that reads from primed cache.
     * Falls back to getCachedPostTerms() if cache is not primed.
     *
     * @param int $post_id Post ID
     * @param string $post_type Post type for taxonomy lookup
     * @param int $cache_time Cache duration in seconds
     * @return array Post terms grouped by taxonomy
     */
    public static function getPostTermsFromCache(int $post_id, string $post_type, int $cache_time = 3600): array
    {
        $key = "post_terms_{$post_id}";
        $cache_group = 'ntdst_posts';

        // Only check ntdst cache if caching is enabled (cache_time > 0)
        if ($cache_time > 0) {
            $cached = wp_cache_get($key, $cache_group);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies($post_type);
        $terms = [];

        foreach ($taxonomies as $taxonomy) {
            // Try WordPress's primed term cache (from update_object_term_cache)
            $wp_cached = wp_cache_get($post_id, "{$taxonomy}_relationships");
            if ($wp_cached !== false && is_array($wp_cached)) {
                foreach ($wp_cached as $term) {
                    if (!is_object($term)) {
                        $term = get_term((int) $term, $taxonomy);
                    }

                    if (is_object($term) && !is_wp_error($term)) {
                        $terms[$taxonomy][] = [
                            'id'   => (int) $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        ];
                    }
                }
            }
        }

        // If we found terms from WP cache, store in our cache
        if (!empty($terms)) {
            if ($cache_time > 0) {
                wp_cache_set($key, $terms, $cache_group, $cache_time);
            }
            return $terms;
        }

        // Fallback to the original method (raw SQL) if WP cache not available
        return self::getCachedPostTerms($post_id, $cache_time);
    }

    /**
     * Get cached post meta (fallback with raw SQL - used when cache not primed)
     *
     * @param int $post_id Post ID
     * @param int $cache_time Cache duration in seconds
     * @return array Post meta data
     */
    public static function getCachedPostMeta(int $post_id, int $cache_time = 3600): array
    {
        $key = "post_meta_{$post_id}";
        $cache_group = 'ntdst_posts';
        $cached = wp_cache_get($key, $cache_group);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
            $post_id
        ));

        $meta = [];
        foreach ($results as $row) {
            $meta[$row->meta_key] = maybe_unserialize($row->meta_value);
        }

        if ($cache_time > 0) {
            wp_cache_set($key, $meta, $cache_group, $cache_time);
        }

        return $meta;
    }

    /**
     * Get cached post terms (fallback with raw SQL - used when cache not primed)
     *
     * @param int $post_id Post ID
     * @param int $cache_time Cache duration in seconds
     * @return array Post terms grouped by taxonomy
     */
    public static function getCachedPostTerms(int $post_id, int $cache_time = 3600): array
    {
        $key = "post_terms_{$post_id}";
        $cache_group = 'ntdst_posts';
        $cached = wp_cache_get($key, $cache_group);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.taxonomy
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE tr.object_id = %d",
            $post_id
        ));

        $terms = [];
        foreach ($results as $term) {
            $terms[$term->taxonomy][] = [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        if ($cache_time > 0) {
            wp_cache_set($key, $terms, $cache_group, $cache_time);
        }

        return $terms;
    }

    /**
     * Super-fast post query with aggressive caching
     *
     * Optimized WP_Query with intelligent caching at multiple levels.
     *
     * @param array $args Query arguments (WP_Query compatible)
     * @return array Array of post data
     */
    public static function getPostsFast(array $args = []): array
    {
        global $wpdb;

        // Extract custom args
        $include_meta = (bool) ($args['include_meta'] ?? false);
        $include_terms = (bool) ($args['include_terms'] ?? false);
        $cache_time = (int) ($args['cache_time'] ?? 3600);

        // Delegate cache time resolution to QueryCache
        $queryCache = NTDST_Query_Cache::instance();
        $cache_time = $queryCache->resolveCacheTime($cache_time);

        // Remove custom args so WP_Query doesn't get confused
        unset($args['include_meta'], $args['include_terms'], $args['cache_time']);

        // Set defaults
        $defaults = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true, // Performance: skip SQL_CALC_FOUND_ROWS
            'update_post_term_cache' => false, // Skip term cache updates
            'update_post_meta_cache' => false, // Skip meta cache updates
            'suppress_filters' => true, // CRITICAL: Skip all filters!
            'ignore_sticky_posts' => true, // Skip sticky posts logic
            'fields' => '', // Get all fields (important!)
        ];

        $args = wp_parse_args($args, $defaults);

        // CRITICAL FIX: Convert 'p' parameter to 'post__in' for non-public post types
        // WP_Query with 'p' parameter has issues with non-public/non-publicly-queryable post types
        if (isset($args['p']) && $args['p']) {
            $args['post__in'] = [(int) $args['p']];
            $args['posts_per_page'] = 1;
            unset($args['p']);
        }

        // Generate cache key using QueryCache (includes version for invalidation)
        $post_type = $args['post_type'] ?? 'post';
        $cache_args = $args;
        $cache_args['include_meta'] = $include_meta;
        $cache_args['include_terms'] = $include_terms;
        $cache_key = $queryCache->generateKey($cache_args, $post_type);
        $cache_group = $queryCache->getGroup($post_type);

        // Try to get from cache
        if ($cache_time > 0) {
            $cached = $queryCache->get($cache_key, $cache_group);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Use WP_Query to get posts
        // We still use WP_Query but with optimizations to skip unnecessary operations
        $query = new WP_Query($args);
        $raw_posts = $query->posts;

        if (empty($raw_posts)) {
            // Cache empty results too
            if ($cache_time > 0) {
                $queryCache->set($cache_key, $cache_group, [], $cache_time);
            }
            return [];
        }

        // PERFORMANCE: Batch load all post IDs for cache priming
        $post_ids = wp_list_pluck($raw_posts, 'ID');
        $post_type = $args['post_type'] ?? 'post';

        // Prime thumbnail cache (single query for all posts)
        update_post_thumbnail_cache($query);

        // Prime meta cache if needed (single query for all posts)
        if ($include_meta) {
            update_postmeta_cache($post_ids);
        }

        // Prime term cache if needed (single query for all posts)
        if ($include_terms) {
            // Get all taxonomies for this post type
            $taxonomies = get_object_taxonomies($post_type);
            if (!empty($taxonomies)) {
                update_object_term_cache($post_ids, $post_type);
            }
        }

        // Prime author cache (single query for all unique authors)
        $author_ids = array_unique(wp_list_pluck($raw_posts, 'post_author'));
        if (!empty($author_ids)) {
            // This primes the user cache for all authors at once
            $users = get_users(['include' => $author_ids, 'fields' => ['ID', 'display_name']]);
            $author_names = [];
            foreach ($users as $user) {
                $author_names[$user->ID] = $user->display_name;
            }
        }

        // Format results (minimal processing - caches are already primed)
        $posts = [];
        foreach ($raw_posts as $post) {
            $post_data = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                // Fallback excerpt generation
                'excerpt' => $post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 55),
                'content' => $post->post_content,
                'permalink' => get_permalink($post->ID),
                'slug' => $post->post_name,
                // ISO 8601 date format for consistency
                'date' => mysql2date('c', $post->post_date),
                'modified' => mysql2date('c', $post->post_modified),
                'author' => [
                    'id' => (int) $post->post_author,
                    'name' => $author_names[$post->post_author] ?? get_the_author_meta('display_name', $post->post_author),
                ],
            ];

            // Get thumbnail (now served from primed cache - no additional query)
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                $post_data['thumbnail'] = [
                    'id' => $thumbnail_id,
                    'url' => wp_get_attachment_image_url($thumbnail_id, 'medium'),
                    'full' => wp_get_attachment_image_url($thumbnail_id, 'full'),
                ];
            } else {
                $post_data['thumbnail'] = null;
            }

            // Include post meta if requested (now served from primed cache)
            if ($include_meta) {
                $post_data['meta'] = self::getPostMetaFromCache($post->ID, $cache_time);
            }

            // Include taxonomy terms if requested (now served from primed cache)
            if ($include_terms) {
                $post_data['terms'] = self::getPostTermsFromCache($post->ID, $post_type, $cache_time);
            }

            $posts[] = $post_data;
        }

        // Cache results using QueryCache
        if ($cache_time > 0) {
            $queryCache->set($cache_key, $cache_group, $posts, $cache_time);
        }

        return $posts;
    }

    /**
     * Clear posts cache
     *
     * If $post_id is provided, clears specific meta/terms caches.
     * If null, attempts to clear all general query caches (if environment supports it).
     *
     * @param int|null $post_id Specific post ID to clear, or null for all
     * @return void
     */
    public static function clearCache(int $post_id = null): void
    {
        $cache_group = 'ntdst_posts';

        if ($post_id !== null) {
            // Clear specific item meta and terms caches used by getPostsFast
            wp_cache_delete("post_meta_{$post_id}", $cache_group);
            wp_cache_delete("post_terms_{$post_id}", $cache_group);
        } else {
            // Clear all query caches by flushing the group (if supported by the backend)
            // This is the only reliable way to clear all query results.
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group($cache_group);
            }
        }
    }
}

/**
 * Global helper - get data manager instance
 */
function ntdst_data(): NTDST_Data_Manager
{
    static $manager = null;
    return $manager ??= new NTDST_Data_Manager();
}

/**
 * Global helper - fast post query (backward compatibility)
 *
 * @param array $args Query arguments
 * @return array Array of post data
 */
function ntdst_get_posts_fast(array $args = []): array
{
    return NTDST_Data_Manager::getPostsFast($args);
}

/**
 * Global helper - clear posts cache (backward compatibility)
 *
 * @param int|null $post_id Post ID to clear, or null for all
 * @return void
 */
function ntdst_clear_posts_cache(?int $post_id = null): void
{
    NTDST_Data_Manager::clearCache($post_id);
}

/**
 * Global helper - invalidate all cached queries for a post type
 *
 * Use this when you've made bulk changes outside the normal CRUD flow.
 *
 * @param string $post_type Post type to invalidate
 * @return void
 */
function ntdst_invalidate_post_type(string $post_type): void
{
    NTDST_Query_Cache::instance()->invalidatePostType($post_type);
}

/**
 * Global helper - get query cache instance
 *
 * @return NTDST_Query_Cache
 */
function ntdst_query_cache(): NTDST_Query_Cache
{
    return NTDST_Query_Cache::instance();
}
