<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PackiyoCustomer;
use App\Services\PackiyoCustomerSyncService;
use App\Support\Database;
use Throwable;

final class PackiyoCustomerController
{
    public function sync(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $summary = (new PackiyoCustomerSyncService())->sync();
            $this->redirect(
                sprintf(
                    'Clientes Packiyo actualizados: %d recibidos, %d guardados.',
                    $summary['fetched'],
                    $summary['saved']
                )
            );
        } catch (Throwable $exception) {
            $this->redirect($exception->getMessage());
        }
    }

    public function activate(): void
    {
        $this->setActive(true);
    }

    public function deactivate(): void
    {
        $this->setActive(false);
    }

    private function setActive(bool $active): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $customerId = trim((string) ($_POST['customer_id'] ?? ''));

            if ($customerId === '') {
                throw new \RuntimeException('Customer ID requerido.');
            }

            (new PackiyoCustomer())->setActive($customerId, $active);
            $this->redirect($active ? 'Cliente activado.' : 'Cliente desactivado.');
        } catch (Throwable $exception) {
            $this->redirect($exception->getMessage());
        }
    }

    private function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    private function methodNotAllowed(): void
    {
        http_response_code(405);
        echo 'Method Not Allowed';
    }

    private function redirect(string $message): void
    {
        header('Location: ' . $this->url('/') . '?tab=packiyo-customers&notice=' . rawurlencode($message), true, 303);
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
