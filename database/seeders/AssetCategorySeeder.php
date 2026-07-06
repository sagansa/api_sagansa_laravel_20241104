<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;

/**
 * Seeded kategori aset baku sebagai titik awal. Admin tetap dapat
 * menambah/mengubah kategori via UI manajemen aset setelah ini.
 */
class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'name' => 'IT / Elektronik',
                'description' => 'Komputer, printer, monitor, perangkat jaringan, dll.',
                'frequency_days' => 30,
                'checklist_definition' => [
                    ['label' => 'Fisik perangkat utuh'],
                    ['label' => 'Fungsi normal'],
                    ['label' => 'Kabel & koneksi aman'],
                    ['label' => 'Bersih dari debu'],
                ],
            ],
            [
                'name' => 'Kendaraan',
                'description' => 'Motor operasional, mobil, kendaraan operasional store.',
                'frequency_days' => 30,
                'checklist_definition' => [
                    ['label' => 'Mesin menyala normal'],
                    ['label' => 'Ban & tekanan angin'],
                    ['label' => 'Rem berfungsi'],
                    ['label' => 'Body & lampu'],
                    ['label' => 'Kelengkapan surat'],
                ],
            ],
            [
                'name' => 'AC',
                'description' => 'Unit pendingin ruangan.',
                'frequency_days' => 90,
                'checklist_definition' => [
                    ['label' => 'Dingin normal'],
                    ['label' => 'Filter bersih'],
                    ['label' => 'Tidak bocor'],
                    ['label' => 'Suara mesin normal'],
                ],
            ],
            [
                'name' => 'Furnitur',
                'description' => 'Meja, kursi, lemari, rak.',
                'frequency_days' => 180,
                'checklist_definition' => [
                    ['label' => 'Fisik kokoh'],
                    ['label' => 'Tidak patah/retak'],
                    ['label' => 'Bersih'],
                ],
            ],
            [
                'name' => "Alat Toko",
                'description' => 'Mesin kasir, scanner, timbangan, dll.',
                'frequency_days' => 30,
                'checklist_definition' => [
                    ['label' => 'Nyala & berfungsi'],
                    ['label' => 'Kalibrasi benar'],
                    ['label' => 'Fisik bersih'],
                ],
            ],
        ];

        foreach ($defaults as $data) {
            AssetCategory::firstOrCreate(
                ['name' => $data['name']],
                array_merge($data, ['is_active' => true])
            );
        }
    }
}
