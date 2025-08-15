<?php

namespace App\Services;

use App\Exports\PresenceExport;
use App\Models\Presence;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportService
{
    /**
     * Export presence data to specified format
     */
    public function exportPresences(array $filters, string $format): array
    {
        $jobId = Str::uuid()->toString();
        $timestamp = now()->format('Y-m-d_H-i-s');
        
        // Generate filename based on format and filters
        $filename = $this->generateFilename($filters, $format, $timestamp);
        
        try {
            switch ($format) {
                case 'excel':
                    $filePath = $this->exportToExcel($filters, $filename);
                    break;
                case 'pdf':
                    $filePath = $this->exportToPdf($filters, $filename);
                    break;
                case 'csv':
                    $filePath = $this->exportToCsv($filters, $filename);
                    break;
                default:
                    throw new \InvalidArgumentException("Unsupported export format: {$format}");
            }

            // Get file info
            $fileSize = Storage::disk('public')->size($filePath);
            $recordCount = $this->getRecordCount($filters);

            return [
                'id' => $jobId,
                'status' => 'completed',
                'progress' => 100,
                'file_url' => Storage::disk('public')->url($filePath),
                'file_name' => basename($filePath),
                'file_size' => $fileSize,
                'total_records' => $recordCount,
                'format' => $format,
                'created_at' => now()->toISOString(),
                'completed_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            return [
                'id' => $jobId,
                'status' => 'failed',
                'progress' => 0,
                'error_message' => $e->getMessage(),
                'created_at' => now()->toISOString(),
            ];
        }
    }

    /**
     * Export to Excel format
     */
    private function exportToExcel(array $filters, string $filename): string
    {
        $filePath = "exports/{$filename}";
        
        Excel::store(new PresenceExport($filters), $filePath, 'public');
        
        return $filePath;
    }

    /**
     * Export to PDF format
     */
    private function exportToPdf(array $filters, string $filename): string
    {
        $presences = $this->getPresenceData($filters);
        
        $pdf = Pdf::loadView('exports.presence-pdf', [
            'presences' => $presences,
            'filters' => $filters,
            'title' => 'Data Presence Report',
            'generated_at' => now()->format('d/m/Y H:i:s'),
        ]);

        $pdf->setPaper('a4', 'landscape');
        
        $filePath = "exports/{$filename}";
        Storage::disk('public')->put($filePath, $pdf->output());
        
        return $filePath;
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $filters, string $filename): string
    {
        $filePath = "exports/{$filename}";
        
        Excel::store(new PresenceExport($filters), $filePath, 'public', \Maatwebsite\Excel\Excel::CSV);
        
        return $filePath;
    }

    /**
     * Get presence data for export
     */
    private function getPresenceData(array $filters)
    {
        $query = Presence::with(['createdBy', 'store', 'shiftStore'])
            ->whereHas('createdBy', function ($q) {
                $q->role('staff');
            });

        // Apply filters
        if (!empty($filters['date_from'])) {
            $query->whereDate('check_in', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('check_in', '<=', $filters['date_to']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('created_by_id', $filters['user_id']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('createdBy', function ($q) use ($search) {
                $q->role('staff')
                  ->where(function ($subQ) use ($search) {
                      $subQ->where('name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('check_in', 'desc')->get();
    }

    /**
     * Get record count for filters
     */
    private function getRecordCount(array $filters): int
    {
        $query = Presence::whereHas('createdBy', function ($q) {
            $q->role('staff');
        });

        // Apply same filters as export
        if (!empty($filters['date_from'])) {
            $query->whereDate('check_in', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('check_in', '<=', $filters['date_to']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('created_by_id', $filters['user_id']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('createdBy', function ($q) use ($search) {
                $q->role('staff')
                  ->where(function ($subQ) use ($search) {
                      $subQ->where('name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->count();
    }

    /**
     * Generate filename based on filters and format
     */
    private function generateFilename(array $filters, string $format, string $timestamp): string
    {
        $name = 'presence_data';
        
        // Add date range to filename if specified
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $dateFrom = $filters['date_from'] ?? 'start';
            $dateTo = $filters['date_to'] ?? 'end';
            $name .= "_{$dateFrom}_to_{$dateTo}";
        }

        // Add employee name if single employee selected
        if (!empty($filters['user_id'])) {
            $user = \App\Models\User::find($filters['user_id']);
            if ($user) {
                $name .= '_' . Str::slug($user->name);
            }
        }

        $extension = $this->getFileExtension($format);
        
        return "{$name}_{$timestamp}.{$extension}";
    }

    /**
     * Get file extension for format
     */
    private function getFileExtension(string $format): string
    {
        switch ($format) {
            case 'excel':
                return 'xlsx';
            case 'pdf':
                return 'pdf';
            case 'csv':
                return 'csv';
            default:
                return 'txt';
        }
    }

    /**
     * Validate export request
     */
    public function validateExportRequest(array $data): array
    {
        $errors = [];

        if (empty($data['format'])) {
            $errors['format'] = ['Export format is required'];
        } elseif (!in_array($data['format'], ['excel', 'pdf', 'csv'])) {
            $errors['format'] = ['Invalid export format'];
        }

        if (!empty($data['date_from']) && !empty($data['date_to'])) {
            $dateFrom = \Carbon\Carbon::parse($data['date_from']);
            $dateTo = \Carbon\Carbon::parse($data['date_to']);
            
            if ($dateFrom->gt($dateTo)) {
                $errors['date_to'] = ['End date must be after start date'];
            }

            // Check if date range is too large (more than 1 year)
            if ($dateFrom->diffInDays($dateTo) > 365) {
                $errors['date_range'] = ['Date range cannot exceed 1 year'];
            }
        }

        if (!empty($data['user_id'])) {
            $user = \App\Models\User::find($data['user_id']);
            if (!$user) {
                $errors['user_id'] = ['Selected user not found'];
            }
        }

        if (!empty($data['store_id'])) {
            $store = \App\Models\Store::find($data['store_id']);
            if (!$store) {
                $errors['store_id'] = ['Selected store not found'];
            }
        }

        return $errors;
    }

    /**
     * Get estimated export time based on record count
     */
    public function getEstimatedTime(array $filters): string
    {
        $recordCount = $this->getRecordCount($filters);
        
        if ($recordCount < 1000) {
            return '< 1 minute';
        } elseif ($recordCount < 10000) {
            return '1-3 minutes';
        } elseif ($recordCount < 50000) {
            return '3-10 minutes';
        } else {
            return '10+ minutes';
        }
    }
}