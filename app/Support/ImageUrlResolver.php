<?php

namespace App\Support;

/**
 * Membangun URL gambar/file dari relative path menuju image service (img.sagansa.id).
 *
 * Read gambar bersifat publik (tanpa token). Full URL yang sudah absolute
 * diteruskan apa adanya.
 */
class ImageUrlResolver
{
    /**
     * Resolve relative path ke URL image service.
     *
     * @param  string|null  $path  Relative path (mis. "images/Product/abc.webp") atau full URL.
     * @return string|null
     */
    public static function resolve(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = trim($path);

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if ($path === '') {
            return null;
        }

        $imgBaseUrl = rtrim((string) env('IMG_SERVICE_URL', 'https://img.sagansa.id'), '/');

        return "{$imgBaseUrl}/storage/{$path}";
    }
}
