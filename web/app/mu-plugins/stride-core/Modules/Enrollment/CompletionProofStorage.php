<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use WP_Error;

/**
 * Protected storage for completion-proof uploads (audit M-2, threat-model M1+M3).
 *
 * Proofs may contain certificates/diplomas bearing national IDs, so they must
 * never sit at guessable public /uploads/YYYY/MM/ URLs. They live under
 * uploads/stride-proofs/ which is deny-ruled at the web server:
 *  - nginx (production, Ploi): deny rule documented in site.yml notes —
 *    `location ^~ /app/uploads/stride-proofs/ { deny all; }`
 *  - .htaccess written into the directory as defense-in-depth (Apache only;
 *    inert under nginx, which is why the nginx rule is the authoritative deny)
 *
 * Serving happens exclusively through the authenticated download filter
 * `ntdst/api_data/stride_download_proof` (CompletionTaskHandler) which
 * enforces owner-or-stride_manage (M2) and in-protected-dir resolution (M3).
 */
final class CompletionProofStorage
{
    public const DIR_NAME = 'stride-proofs';

    /** Links an attachment to the registration whose task it proves (M3 resolution anchor). */
    public const META_REGISTRATION = '_stride_proof_registration_id';

    /** Marks an attachment as protected-proof (M1). */
    public const META_PROTECTED = '_stride_protected_proof';

    /**
     * Version-gated one-off migration of pre-existing public proofs.
     * Pattern: RegistrationTable::SCHEMA_VERSION.
     *
     * v2 (panel SF-1 + NTH-3): re-stamp existing proofs to post_status
     * 'private' — bytes were already deny-ruled, but 'inherit' left the
     * attachment enumerable (anonymous /wp/v2/media listed source_url +
     * user-chosen filename; the attachment permalink served a 200 page).
     */
    public const VERSION = 2;

    private const VERSION_OPTION = 'stride_proof_storage_version';

    /**
     * Set after a failed run (drift D-2): while it lives, migrate() bails
     * before the table scan so a persistently failing file move does not
     * cost a full scan + log spam on every request. The version option
     * stays unstamped, so the retry semantics are unchanged once it lapses.
     */
    private const RETRY_TRANSIENT = 'stride_proof_migration_backoff';

    public static function getProtectedDir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit((string) $uploads['basedir']) . self::DIR_NAME;
    }

    /**
     * Create the protected dir with its .htaccess deny (defense-in-depth).
     */
    public static function ensureProtectedDir(): true|WP_Error
    {
        $dir = self::getProtectedDir();

        if (!wp_mkdir_p($dir)) {
            return new WP_Error('proof_dir_failed', __('Upload mislukt. Probeer opnieuw.', 'stride'));
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "# Stride completion proofs — served only via the authenticated\n"
                . "# stride_download_proof handler. Do not remove.\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";

            if (file_put_contents($htaccess, $rules) === false) {
                return new WP_Error('proof_dir_failed', __('Upload mislukt. Probeer opnieuw.', 'stride'));
            }
        }

        // Block directory-listing fallbacks.
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }

        return true;
    }

    /**
     * `upload_dir` filter: route the current upload into the protected dir.
     * Add around the media_handle_upload() call only — never leave mounted.
     *
     * @param array<string, mixed> $dirs
     * @return array<string, mixed>
     */
    public static function uploadDirFilter(array $dirs): array
    {
        $dirs['subdir'] = '/' . self::DIR_NAME;
        $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];

        return $dirs;
    }

    /**
     * M3: a resolved file may only be served when it physically lives inside
     * the protected dir. realpath() on both sides defeats `..` segments and
     * symlink games; callers must never concatenate user strings into paths.
     */
    public static function isProtectedPath(string $path): bool
    {
        $real = realpath($path);
        $dir = realpath(self::getProtectedDir());

        if ($real === false || $dir === false) {
            return false;
        }

        return str_starts_with($real, $dir . DIRECTORY_SEPARATOR);
    }

    /**
     * Stamp the meta that marks an attachment as a protected proof and links
     * it to its registration (the download handler's authorization anchor).
     *
     * Also sets post_status 'private' (SF-1/NTH-3): bytes are deny-ruled at
     * the web server, but 'inherit' leaves the proof's EXISTENCE enumerable —
     * anonymous /wp/v2/media listed source_url + the user-chosen filename
     * (which can carry PII, e.g. "diploma-jan-jansens.pdf"), and the
     * attachment permalink rendered a 200 page. 'private' hides it from
     * anonymous REST, the permalink and sitemaps in one move; the download
     * handler, get_attached_file() and the admin modal documents query are
     * post_status-independent.
     */
    public static function markProtected(int $attachmentId, int $registrationId): void
    {
        update_post_meta($attachmentId, self::META_REGISTRATION, $registrationId);
        update_post_meta($attachmentId, self::META_PROTECTED, 1);

        if (get_post_status($attachmentId) !== 'private') {
            $updated = wp_update_post([
                'ID' => $attachmentId,
                'post_status' => 'private',
            ], true);

            if (is_wp_error($updated)) {
                ntdst_log('enrollment')->warning('proof attachment could not be set private', [
                    'attachment_id' => $attachmentId,
                    'registration_id' => $registrationId,
                    'error' => $updated->get_error_message(),
                ]);
            }
        }
    }

    /**
     * One-off, version-gated, idempotent migration: move every proof
     * attachment referenced by completion_tasks files into the protected dir
     * and stamp the registration meta.
     *
     * Pattern per RegistrationTable::migrate(): result-checked, logged via
     * ntdst_log, version stamped ONLY on full success (failed moves leave the
     * option unstamped so the next init request retries — steps are idempotent).
     */
    public static function migrate(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
            return;
        }

        if (get_transient(self::RETRY_TRANSIENT) !== false) {
            // A recent run failed — back off until the transient lapses (D-2).
            return;
        }

        if (!RegistrationTable::exists()) {
            // Fresh install: nothing to migrate, all future uploads are protected.
            update_option(self::VERSION_OPTION, self::VERSION);

            return;
        }

        $dirReady = self::ensureProtectedDir();
        if (is_wp_error($dirReady)) {
            ntdst_log('enrollment')->error('proof storage migration failed', [
                'step' => 'ensure_protected_dir',
                'error' => $dirReady->get_error_message(),
            ]);
            set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

            return;
        }

        // INV-3 (CR-D3): the registrations table is repository-owned — the
        // table-wide scan reads through RegistrationRepository, never raw SQL.
        $rows = ntdst_get(RegistrationRepository::class)->idsWithCompletionTasks();

        $found = 0;
        $moved = 0;
        $failures = 0;

        foreach ($rows as $row) {
            $tasks = $row->completion_tasks;
            if (!is_array($tasks)) {
                continue;
            }

            foreach (['documents', 'post_documents'] as $taskKey) {
                $files = $tasks[$taskKey]['data']['files'] ?? null;
                if (!is_array($files)) {
                    continue;
                }

                foreach ($files as $fileId) {
                    $attachmentId = (int) $fileId;
                    if ($attachmentId <= 0) {
                        continue;
                    }

                    $found++;
                    $result = self::protectAttachment($attachmentId, (int) $row->id);

                    if (is_wp_error($result)) {
                        $failures++;
                        ntdst_log('enrollment')->error('proof storage migration: move failed', [
                            'attachment_id' => $attachmentId,
                            'registration_id' => (int) $row->id,
                            'error' => $result->get_error_message(),
                        ]);
                    } elseif ($result === true) {
                        $moved++;
                    }
                }
            }
        }

        if ($failures > 0) {
            // Don't stamp — a later request retries the failed moves once
            // the backoff transient lapses (D-2).
            set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

            return;
        }

        ntdst_log('enrollment')->info('proof storage migration complete', [
            'found' => $found,
            'moved' => $moved,
        ]);
        update_option(self::VERSION_OPTION, self::VERSION);
    }

    /**
     * Move one attachment's file into the protected dir + stamp meta.
     * Idempotent: already-protected and gone-from-disk attachments are skipped.
     *
     * @return bool|WP_Error true = moved, false = skipped (already protected,
     *                       deleted attachment, or file gone), WP_Error = move failure
     */
    public static function protectAttachment(int $attachmentId, int $registrationId): bool|WP_Error
    {
        if (get_post_type($attachmentId) !== 'attachment') {
            // Stale reference (attachment deleted) — nothing left to protect.
            return false;
        }

        $path = (string) get_attached_file($attachmentId);

        if ($path !== '' && self::isProtectedPath($path)) {
            // Already protected (e.g. re-run after a partial failure) — ensure meta.
            self::markProtected($attachmentId, $registrationId);

            return false;
        }

        if ($path === '' || !file_exists($path)) {
            // Gone from disk: nothing public remains; stamp meta and move on.
            self::markProtected($attachmentId, $registrationId);
            ntdst_log('enrollment')->warning('proof storage migration: file missing on disk', [
                'attachment_id' => $attachmentId,
                'registration_id' => $registrationId,
                'path' => $path,
            ]);

            return false;
        }

        $dir = self::getProtectedDir();
        $filename = wp_unique_filename($dir, basename($path));
        $target = trailingslashit($dir) . $filename;

        if (!@rename($path, $target)) {
            return new WP_Error('proof_move_failed', sprintf('rename(%s, %s) failed', $path, $target));
        }

        update_attached_file($attachmentId, $target);

        // Remove derivative image sizes: thumbnails of a diploma are as
        // sensitive as the original and nothing renders them.
        $oldDir = dirname($path);
        // The stub's array shape omits keys real WP adds (original_image on
        // big-image-scaled uploads) — widen so unset() below typechecks.
        /** @var array<string, mixed>|false $meta */
        $meta = wp_get_attachment_metadata($attachmentId);
        if (is_array($meta)) {
            foreach (($meta['sizes'] ?? []) as $size) {
                if (!empty($size['file'])) {
                    @unlink($oldDir . '/' . $size['file']);
                }
            }

            // Big-image scaling (CR-D2): for JPG/PNG > 2560px the attached
            // file is the `-scaled` copy while the FULL-RESOLUTION original
            // stays behind as `original_image` — the most sensitive copy.
            if (!empty($meta['original_image'])) {
                @unlink($oldDir . '/' . $meta['original_image']);
                unset($meta['original_image']);
            }

            $meta['sizes'] = [];
            $meta['file'] = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
            wp_update_attachment_metadata($attachmentId, $meta);
        }

        // Image-edit backups are the same class (CR-D2): full-size copies
        // left in the public dir by the media editor.
        $backupSizes = get_post_meta($attachmentId, '_wp_attachment_backup_sizes', true);
        if (is_array($backupSizes)) {
            foreach ($backupSizes as $size) {
                if (is_array($size) && !empty($size['file'])) {
                    @unlink($oldDir . '/' . $size['file']);
                }
            }
            delete_post_meta($attachmentId, '_wp_attachment_backup_sizes');
        }

        self::markProtected($attachmentId, $registrationId);

        return true;
    }
}
