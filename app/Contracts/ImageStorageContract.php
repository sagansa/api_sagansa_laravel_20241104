<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface ImageStorageContract
{
    /**
     * Upload an image to storage.
     *
     * @param UploadedFile $file
     * @param string $directory
     * @return string|null The relative path of the uploaded file
     */
    public function upload(UploadedFile $file, string $directory = ''): ?string;

    /**
     * Delete an image from storage.
     *
     * @param string|null $path
     * @return void
     */
    public function delete(?string $path): void;
}
