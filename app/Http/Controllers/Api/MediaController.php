<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * MediaController
 *
 * Menyajikan file dari disk `public` (storage/app/public) melalui endpoint
 * Laravel agar response membawa header CORS. Hal ini diperlukan karena:
 *  - Frontend (Flutter web / apps web) berjalan di origin yang berbeda dengan
 *    server file statis (mis. www.sagansa.id/storage) yang langsung di-serve
 *    nginx tanpa header CORS.
 *  - Browser memblokir request cross-origin tanpa `Access-Control-Allow-Origin`.
 *
 * Header CORS ditambahkan secara eksplisit di sini agar selalu hadir pada
 * response biner (BinaryFileResponse), terlepas dari konfigurasi middleware.
 */
class MediaController extends Controller
{
    /**
     * Daftar origin yang diizinkan mengakses media secara cross-origin.
     * Pattern ini selaras dengan config/cors.php (allowed_origins_patterns).
     */
    private function isAllowedOrigin(string $origin): bool
    {
        // localhost / 127.0.0.1 dengan port apa pun
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
            return true;
        }

        // seluruh subdomain sagansa.id (api, www, admin, ops, dll)
        if (preg_match('#^https?://([a-z0-9-]+\.)?sagansa\.id$#', $origin)) {
            return true;
        }

        return false;
    }

    /**
     * Serve a file from the public disk.
     *
     * GET /media/{path}  dimana {path} bisa berisi segment (images/Online/...).
     */
    public function show(Request $request, string $path = '')
    {
        // Bersihkan path agar tidak ada traversal keluar dari disk public.
        $path = ltrim($path, '/');

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'Path file tidak boleh kosong.',
            ], 400);
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            $fallbackUrls = [
                'https://sagansa.id/storage/' . $path,
                'https://www.sagansa.id/storage/' . $path,
                'https://admin.sagansa.id/storage/' . $path,
            ];

            $downloaded = false;
            foreach ($fallbackUrls as $url) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_exec($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($statusCode === 200) {
                    $content = @file_get_contents($url);
                    if ($content !== false) {
                        $disk->put($path, $content);
                        $downloaded = true;
                        break;
                    }
                }
            }

            if (!$downloaded) {
                return response()->json([
                    'success' => false,
                    'message' => 'File tidak ditemukan.',
                ], 404);
            }
        }

        $absolutePath = $disk->path($path);

        // BinaryFileResponse efisien dan mendukung HTTP range requests.
        $response = new BinaryFileResponse($absolutePath, 200, [
            'Cache-Control' => 'public, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);

        $response->setAutoLastModified();
        $response->setAutoEtag();

        // Tambahkan header CORS secara eksplisit.
        // Karena supports_credentials = true di config/cors.php, kita echo
        // Origin spesifik (tidak boleh '*' saat credentials aktif).
        $origin = $request->headers->get('Origin');

        if ($origin && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}