<?php

namespace App\Console\Commands;

use App\Contracts\ImageStorageContract;
use App\Models\HygieneOfRoom;
use App\Models\Readiness;
use Illuminate\Console\Command;

/**
 * Hapus file foto kesiapan diri yang sudah tidak diperlukan, tetapi tetap
 * mempertahankan record kesiapan untuk kebutuhan histori/admin.
 */
class PruneReadinessImages extends Command
{
    protected $signature = 'readiness:prune-images
        {--dry-run : Tampilkan data yang akan dibersihkan tanpa menghapus file/data path.}';

    protected $description = 'Hapus foto kesiapan diri dan kebersihan toko yang lebih lama dari dua bulan';

    public function handle(ImageStorageContract $storage): int
    {
        $cutoff = now()->subMonths(2);
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $images = 0;

        Readiness::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($query) {
                $query->whereNotNull('image_selfie')
                    ->orWhereNotNull('left_hand')
                    ->orWhereNotNull('right_hand');
            })
            ->orderBy('id')
            ->chunkById(200, function ($readinesses) use ($storage, $dryRun, &$processed, &$images) {
                foreach ($readinesses as $readiness) {
                    $paths = array_filter([
                        $readiness->image_selfie,
                        $readiness->left_hand,
                        $readiness->right_hand,
                    ]);
                    $images += count($paths);
                    $processed++;

                    if ($dryRun) {
                        $this->line("Readiness #{$readiness->id}: " . count($paths) . ' foto');
                        continue;
                    }

                    foreach ($paths as $path) {
                        $storage->delete($path);
                    }

                    // Record tetap ada, hanya referensi file yang dikosongkan.
                    $readiness->forceFill([
                        'image_selfie' => null,
                        'left_hand' => null,
                        'right_hand' => null,
                    ])->saveQuietly();
                }
            });

        HygieneOfRoom::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('image')
            ->orderBy('id')
            ->chunkById(200, function ($rooms) use ($storage, $dryRun, &$processed, &$images) {
                foreach ($rooms as $room) {
                    $paths = is_array($room->image) ? array_filter($room->image) : array_filter([$room->image]);
                    $images += count($paths);
                    $processed++;

                    if ($dryRun) {
                        $this->line("HygieneOfRoom #{$room->id}: " . count($paths) . ' foto');
                        continue;
                    }

                    foreach ($paths as $path) {
                        $storage->delete($path);
                    }

                    // Detail inspeksi tetap disimpan, hanya foto yang dibuang.
                    $room->forceFill(['image' => null])->saveQuietly();
                }
            });

        $mode = $dryRun ? 'DRY-RUN' : 'SELESAI';
        $this->info("{$mode}: {$images} foto pada {$processed} record kesiapan/kebersihan (sebelum {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
