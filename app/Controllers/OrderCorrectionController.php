<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderCorrectionService;
use App\Support\Auth;
use Throwable;

final class OrderCorrectionController
{
    public function start(): void
    {
        $this->postOnly();
        try {
            $job = (new OrderCorrectionService())->start(
                max(1, min(365, (int) ($_POST['days'] ?? 180))),
                (new Auth())->currentUserId()
            );
            $this->redirect(
                (string) $job['id'],
                sprintf(
                    'Analisis iniciado: %d ordenes revisadas y %d lineas JTL-LINE encontradas hasta ahora. Continua por lotes hasta completarlo.',
                    (int) ($job['scanned_orders'] ?? 0),
                    (int) ($job['detected_lines'] ?? 0)
                )
            );
        } catch (Throwable $exception) {
            $this->redirect('', $exception->getMessage());
        }
    }

    public function continue(): void
    {
        $this->postOnly();
        $jobId = $this->jobId();
        try {
            $job = (new OrderCorrectionService())->continue($jobId);
            $message = (string) ($job['status'] ?? '') === 'completed'
                ? sprintf(
                    'Analisis completado: %d ordenes revisadas y %d lineas JTL-LINE encontradas.',
                    (int) ($job['scanned_orders'] ?? 0),
                    (int) ($job['detected_lines'] ?? 0)
                )
                : sprintf(
                    'Lote analizado: %d ordenes revisadas y %d lineas JTL-LINE encontradas hasta ahora. Continua para terminar.',
                    (int) ($job['scanned_orders'] ?? 0),
                    (int) ($job['detected_lines'] ?? 0)
                );
            $this->redirect($jobId, $message);
        } catch (Throwable $exception) {
            $this->redirect($jobId, $exception->getMessage());
        }
    }

    public function assign(): void
    {
        $this->postOnly();
        $jobId = $this->jobId();
        try {
            $count = (new OrderCorrectionService())->assign(
                $jobId,
                $this->lineIds(),
                trim((string) ($_POST['product_id'] ?? '')),
                (string) ($_POST['scope'] ?? 'line')
            );
            $this->redirect($jobId, $count . ' linea(s) asignada(s).');
        } catch (Throwable $exception) {
            $this->redirect($jobId, $exception->getMessage());
        }
    }

    public function preview(): void
    {
        $this->postOnly();
        $jobId = $this->jobId();
        try {
            $result = (new OrderCorrectionService())->preview(
                $jobId,
                $this->lineIds(),
                (new Auth())->currentUserId()
            );
            $ready = count(array_filter($result['orders'], static fn (array $row): bool => $row['status'] === 'ready'));
            $this->redirect($jobId, 'Previsualizacion actualizada: ' . $ready . ' orden(es) listas.');
        } catch (Throwable $exception) {
            $this->redirect($jobId, $exception->getMessage());
        }
    }

    public function execute(): void
    {
        $this->postOnly();
        $jobId = $this->jobId();
        try {
            $summary = (new OrderCorrectionService())->execute(
                $jobId,
                $this->lineIds(),
                (new Auth())->currentUserId()
            );
            $this->redirect(
                $jobId,
                sprintf('Ejecucion: %d corregidas, %d omitidas, %d fallidas.', $summary['corrected'], $summary['skipped'], $summary['failed'])
            );
        } catch (Throwable $exception) {
            $this->redirect($jobId, $exception->getMessage());
        }
    }

    public function export(): void
    {
        $jobId = trim((string) ($_GET['job_id'] ?? ''));
        if ($jobId === '') {
            http_response_code(422);
            echo 'Falta job_id.';
            return;
        }
        $csv = (new OrderCorrectionService())->csv($jobId);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="correccion-packiyo-' . preg_replace('/[^a-z0-9-]/i', '', $jobId) . '.csv"');
        echo $csv;
    }

    /** @return array<int, int> */
    private function lineIds(): array
    {
        $input = $_POST['line_ids'] ?? [];
        if (!is_array($input)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $input), static fn (int $id): bool => $id > 0)));
    }

    private function jobId(): string
    {
        return trim((string) ($_POST['job_id'] ?? ''));
    }

    private function postOnly(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }

    private function redirect(string $jobId, string $notice): never
    {
        $query = ['tab' => 'order-corrections', 'notice' => $notice];
        if ($jobId !== '') {
            $query['correction_job'] = $jobId;
        }
        header('Location: ' . $this->url('/?') . http_build_query($query), true, 303);
        exit;
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        return ($scriptDir === '/' ? '' : rtrim($scriptDir, '/')) . '/' . ltrim($path, '/');
    }
}
