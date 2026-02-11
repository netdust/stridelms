<?php

/**
 * NTDST Logger - PSR-3 Compatible Logging System
 *
 * Features:
 * - Multiple log levels (debug, info, warning, error, critical)
 * - Multiple channels (file, database, error_log)
 * - Context data support
 * - Automatic error tracking
 * - Production-ready
 *
 * Architecture:
 * - Uses Data.php for database logging (consistent with system architecture)
 * - No raw SQL - everything goes through ORM
 * - Logs stored as custom post type with metadata
 *
 * Usage:
 *   ntdst_log()->info('User logged in', ['user_id' => 123]);
 *   ntdst_log()->error('Payment failed', ['order_id' => 456, 'error' => $e]);
 *   ntdst_log('debug')->debug('API response', ['data' => $response]);
 */

defined('ABSPATH') || exit;

class NTDST_Logger
{
    protected string $channel = 'app';
    protected array $handlers = [];
    protected int $min_level = 0; // 0=debug, 1=info, 2=warning, 3=error, 4=critical
    protected static bool $model_registered = false;

    /**
     * PERFORMANCE: Batched log entries to write on shutdown
     * Reduces I/O by collecting logs and writing once
     */
    protected static array $batchedLogs = [];
    protected static bool $shutdownRegistered = false;
    protected static bool $batchingEnabled = true;

    public const DEBUG = 0;
    public const INFO = 1;
    public const WARNING = 2;
    public const ERROR = 3;
    public const CRITICAL = 4;

    protected static array $levels = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
        self::CRITICAL => 'CRITICAL',
    ];

    public function __construct(string $channel = 'app')
    {
        $this->channel = $channel;

        // Set minimum level based on environment
        $this->min_level = defined('WP_DEBUG') && WP_DEBUG ? self::DEBUG : self::WARNING;

        // PERFORMANCE: Register shutdown handler once for batched writes
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'flushBatchedLogs']);
            self::$shutdownRegistered = true;
        }

        // Register log model (once)
        $this->ensureModelRegistered();

        // Register default handlers
        $this->registerDefaultHandlers();
    }

    /**
     * Register log_entry model with Data.php
     */
    protected function ensureModelRegistered(): void
    {
        if (self::$model_registered) {
            return;
        }

        // Register log_entry custom post type via Data.php
        ntdst_data()->register('log_entry', [
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'has_archive' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'supports' => ['title', 'author'],
            'fields' => [
                'channel' => 'text',
                'level' => 'text',
                'context' => 'json',
                'ip_address' => 'text',
                'url' => 'url',
            ],
        ]);

        self::$model_registered = true;
    }

    /**
     * Register default log handlers
     */
    protected function registerDefaultHandlers(): void
    {
        // File handler (logs directory)
        // PERFORMANCE: Uses batching to collect entries and write once on shutdown
        $this->handlers['file'] = function ($level, $message, $context) {
            // Format log entry
            $timestamp = current_time('Y-m-d H:i:s');
            $level_name = self::$levels[$level] ?? 'UNKNOWN';
            $context_str = !empty($context) ? ' ' . json_encode($context) : '';
            $log_entry = "[{$timestamp}] {$this->channel}.{$level_name}: {$message}{$context_str}\n";

            // PERFORMANCE: Batch file writes instead of writing immediately
            if (self::$batchingEnabled) {
                $log_file = $this->channel . '-' . date('Y-m-d') . '.log';
                if (!isset(self::$batchedLogs[$log_file])) {
                    self::$batchedLogs[$log_file] = [];
                }
                self::$batchedLogs[$log_file][] = $log_entry;
            } else {
                // Immediate write (for critical errors or when batching disabled)
                $this->writeToLogFile($log_entry);
            }
        };

        // Error log handler (PHP error_log)
        $this->handlers['error_log'] = function ($level, $message, $context) {
            if ($level >= self::ERROR) {
                $level_name = self::$levels[$level] ?? 'UNKNOWN';
                $context_str = !empty($context) ? ' ' . json_encode($context) : '';
                error_log("[{$this->channel}] {$level_name}: {$message}{$context_str}");
            }
        };

        // Database handler (for critical errors)
        // Uses Data.php for consistency with system architecture
        $this->handlers['database'] = function ($level, $message, $context) {
            if ($level >= self::ERROR) {
                try {
                    $model = ntdst_data()->get('log_entry');

                    // Create log entry via Data.php ORM
                    $model->create([
                        'title' => $message,                        // post_title
                        'author' => get_current_user_id() ?: 0,     // post_author
                        'channel' => $this->channel,                // meta
                        'level' => self::$levels[$level] ?? 'UNKNOWN', // meta
                        'context' => $context,                      // meta (auto JSON encoded)
                        'ip_address' => $this->getClientIp(),       // meta
                        'url' => $_SERVER['REQUEST_URI'] ?? '',     // meta
                    ]);
                } catch (Exception $e) {
                    // Fail silently to prevent logging errors from breaking the app
                    error_log('Logger database handler failed: ' . $e->getMessage());
                }
            }
        };
    }

    /**
     * Get client IP address (secure implementation)
     * Only trusts X-Forwarded-For from known proxies
     */
    protected function getClientIp(): string
    {
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Define trusted proxies
        $trusted_proxies = apply_filters('netdust_trusted_proxies', ['127.0.0.1', '::1']);

        // Only trust forwarded headers from trusted proxies
        if (in_array($remote_ip, $trusted_proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $client_ip = $forwarded_ips[0];
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        return filter_var($remote_ip, FILTER_VALIDATE_IP) ? $remote_ip : 'unknown';
    }

    /**
     * Write log entry to file immediately
     */
    protected function writeToLogFile(string $log_entry): void
    {
        $log_dir = WP_CONTENT_DIR . '/logs';

        // Create logs directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Protect logs directory
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // Write to daily log file
        $log_file = $log_dir . '/' . $this->channel . '-' . date('Y-m-d') . '.log';
        error_log($log_entry, 3, $log_file);
    }

    /**
     * PERFORMANCE: Flush all batched logs to files
     * Called on shutdown to write all collected logs at once
     */
    public static function flushBatchedLogs(): void
    {
        if (empty(self::$batchedLogs)) {
            return;
        }

        $log_dir = WP_CONTENT_DIR . '/logs';

        // Create logs directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Protect logs directory
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // Write all batched entries to their respective files
        foreach (self::$batchedLogs as $filename => $entries) {
            $log_file = $log_dir . '/' . $filename;
            $content = implode('', $entries);

            // Use file_put_contents with LOCK_EX and FILE_APPEND for safe concurrent writes
            file_put_contents($log_file, $content, FILE_APPEND | LOCK_EX);
        }

        // Clear the batch
        self::$batchedLogs = [];
    }

    /**
     * Enable or disable batching
     * Useful for CLI scripts or tests that need immediate output
     */
    public static function setBatchingEnabled(bool $enabled): void
    {
        self::$batchingEnabled = $enabled;

        // If disabling, flush any pending logs
        if (!$enabled) {
            self::flushBatchedLogs();
        }
    }

    /**
     * Force immediate flush of batched logs
     * Use before long-running operations or when immediate logging is needed
     */
    public function flush(): void
    {
        self::flushBatchedLogs();
    }

    /**
     * Log a message
     */
    protected function log(int $level, string $message, array $context = []): void
    {
        // Skip if below minimum level
        if ($level < $this->min_level) {
            return;
        }

        // PERFORMANCE: Critical/Error level logs bypass batching for immediate visibility
        $was_batching = self::$batchingEnabled;
        if ($level >= self::ERROR) {
            self::$batchingEnabled = false;
        }

        // Replace placeholders in message
        $message = $this->interpolate($message, $context);

        // Fire action hook for extensibility
        do_action('ntdst_log', $level, $message, $context, $this->channel);
        do_action('ntdst_log_' . $this->channel, $level, $message, $context);

        // Execute handlers
        foreach ($this->handlers as $handler) {
            try {
                $handler($level, $message, $context);
            } catch (Exception $e) {
                // Fail silently to prevent logging errors from breaking the app
                error_log("Logger handler failed: " . $e->getMessage());
            }
        }

        // Restore batching state
        self::$batchingEnabled = $was_batching;
    }

    /**
     * Interpolate context values into message placeholders
     */
    protected function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            } elseif (is_object($val)) {
                $replace['{' . $key . '}'] = get_class($val);
            } elseif (is_array($val)) {
                $replace['{' . $key . '}'] = json_encode($val);
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Add custom handler
     */
    public function addHandler(string $name, callable $handler): self
    {
        $this->handlers[$name] = $handler;
        return $this;
    }

    /**
     * Remove handler
     */
    public function removeHandler(string $name): self
    {
        unset($this->handlers[$name]);
        return $this;
    }

    /**
     * Set minimum log level
     */
    public function setMinLevel(int $level): self
    {
        $this->min_level = $level;
        return $this;
    }

    // PSR-3 Interface Methods

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Get recent logs from database
     * Uses Data.php query builder for consistency
     */
    public function recent(int $limit = 50, ?int $min_level = null): array
    {
        try {
            $model = ntdst_data()->get('log_entry');

            // Build query
            $query = $model->where('channel', $this->channel)
                           ->orderBy('date', 'DESC')
                           ->limit($limit);

            // Filter by level if specified
            if ($min_level !== null) {
                $level_name = self::$levels[$min_level] ?? null;
                if ($level_name) {
                    $query->where('level', $level_name);
                }
            }

            $logs = $query->get();

            // Format for consistency with old format
            return array_map(function ($log) {
                return [
                    'id' => $log->ID,
                    'channel' => $log->fields['channel'] ?? '',
                    'level' => $log->fields['level'] ?? '',
                    'message' => $log->post_title,
                    'context' => $log->fields['context'] ?? null,
                    'created_at' => $log->post_date,
                    'user_id' => $log->post_author,
                    'ip_address' => $log->fields['ip_address'] ?? '',
                    'url' => $log->fields['url'] ?? '',
                ];
            }, $logs);
        } catch (Exception $e) {
            error_log('Logger recent() failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear old logs
     * Uses Data.php for deletion
     */
    public function clearOld(int $days = 30): int
    {
        try {
            $model = ntdst_data()->get('log_entry');

            // Calculate cutoff date
            $cutoff_date = date('Y-m-d', strtotime("-{$days} days"));

            // Query old logs for this channel
            $old_logs = $model->where('channel', $this->channel)
                              ->whereDate('post_date', '<', $cutoff_date)
                              ->get();

            // Delete each log
            $deleted = 0;
            foreach ($old_logs as $log) {
                $result = $model->delete($log->ID);
                if (!is_wp_error($result)) {
                    $deleted++;
                }
            }

            return $deleted;
        } catch (Exception $e) {
            error_log('Logger clearOld() failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Global helper - get logger instance
 */
function ntdst_log(string $channel = 'app'): NTDST_Logger
{
    static $loggers = [];

    if (!isset($loggers[$channel])) {
        $loggers[$channel] = new NTDST_Logger($channel);
    }

    return $loggers[$channel];
}

/**
 * Quick log helpers
 */
function ntdst_log_debug(string $message, array $context = []): void
{
    ntdst_log()->debug($message, $context);
}

function ntdst_log_info(string $message, array $context = []): void
{
    ntdst_log()->info($message, $context);
}

function ntdst_log_error(string $message, array $context = []): void
{
    ntdst_log()->error($message, $context);
}
