<?php

namespace App\Services;

use App\Contracts\ImageStorageContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageUploadService implements ImageStorageContract
{
    /**
     * Upload an image to the image microservice (img.sagansa.id).
     * Falls back to local public storage if the upload fails.
     *
     * @param UploadedFile $file
     * @param string $directory
     * @return string|null The relative storage path
     */
    public function upload(UploadedFile $file, string $directory = ''): ?string
    {
        $token = config('services.image.api_token');
        $serviceUrl = config('services.image.service_url', 'https://img.sagansa.id');

        if ($token) {
            try {
                $response = Http::withToken($token)
                    ->timeout(30)
                    ->attach('image', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                    ->post(rtrim($serviceUrl, '/') . '/api/upload', array_filter([
                        'directory' => $directory,
                    ]));

                if ($response->successful() && isset($response->json()['path'])) {
                    return $response->json()['path'];
                }

                Log::error('ImageUploadService: upload failed on img service', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::error('ImageUploadService: upload exception', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Fallback to local storage
        Log::info('ImageUploadService: Falling back to local storage for ' . $file->getClientOriginalName());
        return $file->store($directory, 'public');
    }

    /**
     * Delete an image from the image microservice.
     * Falls back to local public disk deletion if path is local or token is missing.
     *
     * @param string|null $path
     * @return void
     */
    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $token = config('services.image.api_token');
        $serviceUrl = config('services.image.service_url', 'https://img.sagansa.id');

        // If the path contains http, it might be an external url, but we assume relative path
        if ($token && !str_starts_with($path, 'http')) {
            try {
                $response = Http::withToken($token)
                    ->timeout(15)
                    ->delete(rtrim($serviceUrl, '/') . '/api/images', [
                        'path' => $path,
                    ]);

                if ($response->successful()) {
                    return;
                }

                Log::warning('ImageUploadService: delete failed on img service', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } catch (\Throwable $e) {
                Log::error('ImageUploadService: delete exception', [
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Local storage fallback / local deletion
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
