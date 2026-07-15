<?php

namespace App\Console\Commands;

use App\Models\MonthlySalary;
use App\Services\SalaryService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RegenerateMonthlySalaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'salary:regenerate
        {--execute : Terapkan perubahan. Tanpa flag ini, hanya menampilkan rencana (dry-run).}
        {--include-paid : Sertakan slip berstatus PAID (default: dilewati).}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate ulang slip gaji bulanan agar mencerminkan perhitungan terbaru (mis. filter status daily salary 2/3). Default: slip yang sudah PAID dilewati.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $includePaid = (bool) $this->option('include-paid');

        if ($execute) {
            $this->info('Mode: EXECUTE');
        } else {
            $this->warn('Mode: DRY-RUN (tambah --execute untuk menerapkan)');
        }
        if ($includePaid) {
            $this->warn('Perhatian: --include-paid aktif. Slip yang sudah PAID akan ikut di-regenerate.');
        }
        $this->newLine();

        $query = MonthlySalary::orderBy('id');
        if (!$includePaid) {
            $query->where('status', '!=', MonthlySalary::STATUS_PAID);
        }
        $slips = $query->get(['id', 'user_id', 'period_start', 'period_end', 'daily_salary_total', 'total_salary', 'status']);

        if ($slips->isEmpty()) {
            $this->info('Tidak ada slip gaji yang perlu di-regenerate.');
            return self::SUCCESS;
        }

        $statusLabel = [
            MonthlySalary::STATUS_DRAFT => 'DRAFT',
            MonthlySalary::STATUS_APPROVED => 'APPROVED',
            MonthlySalary::STATUS_PAID => 'PAID',
        ];

        $salaryService = app(SalaryService::class);
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($slips as $slip) {
            // Tentukan month/year dari period_end (tanggal akhir periode gaji).
            $periodEnd = Carbon::parse($slip->period_end);
            $oldDailyTotal = (float) $slip->daily_salary_total;
            $oldFinal = (float) $slip->total_salary;
            $label = $statusLabel[$slip->status] ?? ('?' . $slip->status);

            if (!$execute) {
                $this->line(sprintf(
                    '  MS#%-4d user=%-6d %s → %s | daily: %s | final: %s | [%s]',
                    $slip->id,
                    $slip->user_id,
                    $slip->period_start,
                    $slip->period_end,
                    number_format($oldDailyTotal, 0, ',', '.'),
                    number_format($oldFinal, 0, ',', '.'),
                    $label
                ));
                continue;
            }

            try {
                $result = $salaryService->generateMonthlySalary($slip->user_id, $periodEnd->year, $periodEnd->month);
                $newDailyTotal = (float) $result->daily_salary_total;
                $newFinal = (float) $result->total_salary;

                $changed = ($newDailyTotal != $oldDailyTotal) || ($newFinal != $oldFinal);

                $this->line(sprintf(
                    '  %s MS#%-4d user=%-6d | daily %s→%s | final %s→%s',
                    $changed ? '✓' : '=',
                    $slip->id,
                    $slip->user_id,
                    number_format($oldDailyTotal, 0, ',', '.'),
                    number_format($newDailyTotal, 0, ',', '.'),
                    number_format($oldFinal, 0, ',', '.'),
                    number_format($newFinal, 0, ',', '.')
                ));
                $success++;
            } catch (\Exception $e) {
                $this->error(sprintf('  ✗ MS#%d user=%d ERROR: %s', $slip->id, $slip->user_id, $e->getMessage()));
                $failed++;
            }
        }

        $this->newLine();
        if ($execute) {
            $this->info("Selesai. Sukses: {$success}, Gagal: {$failed}." . ($includePaid ? ' (termasuk PAID)' : ''));
        } else {
            $this->warn(sprintf('Dry-run: %d slip akan di-regenerate. Jalankan dengan --execute.', $slips->count()));
        }

        return self::SUCCESS;
    }
}
