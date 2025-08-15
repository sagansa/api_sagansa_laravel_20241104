<?php

namespace App\Exports;

use App\Models\Presence;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PresenceExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithColumnWidths, WithTitle
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = Presence::with(['createdBy', 'store', 'shiftStore'])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            });

        // Apply filters
        if (!empty($this->filters['date_from'])) {
            $query->whereDate('check_in', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('check_in', '<=', $this->filters['date_to']);
        }

        if (!empty($this->filters['user_id'])) {
            $query->where('created_by_id', $this->filters['user_id']);
        }

        if (!empty($this->filters['store_id'])) {
            $query->where('store_id', $this->filters['store_id']);
        }

        if (!empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->whereHas('createdBy', function ($q) use ($search) {
                $q->role('staff')
                  ->where(function ($subQ) use ($search) {
                      $subQ->where('name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('check_in', 'desc');
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Karyawan',
            'Email',
            'Toko',
            'Shift',
            'Tanggal',
            'Jam Masuk',
            'Jam Keluar',
            'Status Masuk',
            'Status Keluar',
            'Terlambat (Menit)',
            'Latitude Masuk',
            'Longitude Masuk',
            'Latitude Keluar',
            'Longitude Keluar',
            'Status',
            'Dibuat Pada',
        ];
    }

    public function map($presence): array
    {
        static $counter = 0;
        $counter++;

        return [
            $counter,
            $presence->createdBy->name ?? '-',
            $presence->createdBy->email ?? '-',
            $presence->store->nickname ?? '-',
            $presence->shiftStore->name ?? '-',
            $presence->check_in ? $presence->check_in->format('Y-m-d') : '-',
            $presence->check_in ? $presence->check_in->format('H:i:s') : '-',
            $presence->check_out ? $presence->check_out->format('H:i:s') : 'Belum Checkout',
            $this->getCheckInStatus($presence),
            $this->getCheckOutStatus($presence),
            $this->getLateMinutes($presence),
            $presence->latitude_in ?? '-',
            $presence->longitude_in ?? '-',
            $presence->latitude_out ?? '-',
            $presence->longitude_out ?? '-',
            $this->getStatusText($presence->status),
            $presence->created_at ? $presence->created_at->format('Y-m-d H:i:s') : '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the header row
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ],
            // Style all data rows
            'A:Q' => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,   // No
            'B' => 20,  // Nama Karyawan
            'C' => 25,  // Email
            'D' => 15,  // Toko
            'E' => 15,  // Shift
            'F' => 12,  // Tanggal
            'G' => 12,  // Jam Masuk
            'H' => 12,  // Jam Keluar
            'I' => 15,  // Status Masuk
            'J' => 15,  // Status Keluar
            'K' => 12,  // Terlambat
            'L' => 12,  // Lat Masuk
            'M' => 12,  // Lng Masuk
            'N' => 12,  // Lat Keluar
            'O' => 12,  // Lng Keluar
            'P' => 10,  // Status
            'Q' => 18,  // Dibuat Pada
        ];
    }

    public function title(): string
    {
        $title = 'Data Presence';
        
        if (!empty($this->filters['date_from']) || !empty($this->filters['date_to'])) {
            $dateFrom = $this->filters['date_from'] ?? 'Start';
            $dateTo = $this->filters['date_to'] ?? 'End';
            $title .= " ({$dateFrom} - {$dateTo})";
        }

        return $title;
    }

    private function getCheckInStatus($presence): string
    {
        if (!$presence->check_in || !$presence->shiftStore) {
            return '-';
        }

        $checkInTime = $presence->check_in->format('H:i:s');
        $shiftStartTime = $presence->shiftStore->shift_start_time;

        if ($checkInTime <= $shiftStartTime) {
            return 'Tepat Waktu';
        } else {
            return 'Terlambat';
        }
    }

    private function getCheckOutStatus($presence): string
    {
        if (!$presence->check_out) {
            return 'Belum Checkout';
        }

        if (!$presence->shiftStore) {
            return '-';
        }

        $checkOutTime = $presence->check_out->format('H:i:s');
        $shiftEndTime = $presence->shiftStore->shift_end_time;

        if ($checkOutTime >= $shiftEndTime) {
            return 'Tepat Waktu';
        } else {
            return 'Pulang Cepat';
        }
    }

    private function getLateMinutes($presence): string
    {
        if (!$presence->check_in || !$presence->shiftStore) {
            return '-';
        }

        $checkInTime = $presence->check_in;
        $shiftStartTime = $presence->check_in->copy()->setTimeFromTimeString($presence->shiftStore->shift_start_time);

        if ($checkInTime <= $shiftStartTime) {
            return '0';
        }

        $lateMinutes = $checkInTime->diffInMinutes($shiftStartTime);
        return (string) $lateMinutes;
    }

    private function getStatusText($status): string
    {
        switch ($status) {
            case 1:
                return 'Aktif';
            case 0:
                return 'Tidak Aktif';
            default:
                return 'Unknown';
        }
    }
}