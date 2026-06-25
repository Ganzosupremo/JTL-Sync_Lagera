<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PackiyoCustomerMapping;
use App\Support\Database;
use Throwable;

final class PackiyoCustomerMappingController
{
    public function store(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $matchType = (string) ($_POST['match_type'] ?? '');
            $matchValue = (string) ($_POST['match_value'] ?? '');

            if ($matchType === 'default' && trim($matchValue) === '') {
                $matchValue = 'default';
            }

            (new PackiyoCustomerMapping())->upsert([
                'match_type' => $matchType,
                'match_value' => $matchValue,
                'packiyo_customer_id' => $_POST['packiyo_customer_id'] ?? '',
                'packiyo_customer_name' => $_POST['packiyo_customer_name'] ?? '',
                'priority' => $_POST['priority'] ?? 100,
                'active' => true,
            ]);

            $this->redirect('Mapeo de cliente Packiyo guardado.');
        } catch (Throwable $exception) {
            $this->redirect($exception->getMessage());
        }
    }

    public function delete(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            (new PackiyoCustomerMapping())->delete((int) ($_POST['id'] ?? 0));
            $this->redirect('Mapeo eliminado.');
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
        header('Location: ' . $this->url('/') . '?tab=customer-mappings&notice=' . rawurlencode($message), true, 303);
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
