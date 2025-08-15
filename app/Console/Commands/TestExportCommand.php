<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use Illuminate\Console\Command;

class TestExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:export {format=excel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test export functionality';

    /**
     * Execute the console command.
     */
    public function handle(ExportService $exportService)
    {
        $format = $this->argument('format');
        
        $this->info("Testing export with format: {$format}");
        
        $filters = [
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ];
        
        try {
            $result = $exportService->exportPresences($filters, $format);
            
            if ($result['status'] === 'completed') {
                $this->info("Export successful!");
                $this->info("File: {$result['file_name']}");
                $this->info("Size: {$result['file_size']} bytes");
                $this->info("Records: {$result['total_records']}");
                $this->info("URL: {$result['file_url']}");
            } else {
                $this->error("Export failed: {$result['error_message']}");
            }
            
        } catch (\Exception $e) {
            $this->error("Export error: " . $e->getMessage());
        }
    }
}
