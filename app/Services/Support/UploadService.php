<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Core\Config;
use App\Exceptions\BusinessRuleException;

/**
 * Secure image uploads.
 * - checks the upload error code and size limit
 * - detects the real MIME type from file contents (finfo), not the browser-supplied type
 * - verifies the file decodes as an image
 * - stores it OUTSIDE the web root under a random name; it is served via a controller
 */
final class UploadService
{
    public function storeImage(array $file, string $folder): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $folder)) {
            throw new \InvalidArgumentException('Invalid upload folder.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new BusinessRuleException('The image could not be uploaded. Please try again.');
        }
        $max = (int) Config::get('security.uploads.max_bytes', 2097152);
        if ((int) $file['size'] > $max) {
            throw new BusinessRuleException('The image is too large (maximum ' . round($max / 1048576, 1) . ' MB).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = (array) Config::get('security.uploads.image_mimes', []);
        if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw new BusinessRuleException('Only JPG, PNG or WebP images are allowed.');
        }
        $dir = BASE_PATH . '/storage/uploads/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        return $folder . '/' . $name;
    }

    /** Absolute path of a stored file, or null if missing/invalid. */
    public function path(?string $relative): ?string
    {
        if (!$relative || !preg_match('#^[a-z0-9_-]+/[a-f0-9]{32}\.(jpg|png|webp)$#', $relative)) {
            return null;
        }
        $path = BASE_PATH . '/storage/uploads/' . $relative;
        return is_file($path) ? $path : null;
    }

    public function delete(?string $relative): void
    {
        $path = $this->path($relative);
        if ($path) {
            @unlink($path);
        }
    }
}
