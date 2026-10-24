<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\JtlRegistrationService;
use App\Support\Database;
use Throwable;

final class JtlRegistrationController extends Controller
{
    public function start(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $requestId = (new JtlRegistrationService())->start();
            $this->respond([
                'registration_request_id' => $requestId,
                'message' => 'Solicitud enviada a JTL-Wawi. Aprueba permisos y luego obten el API token.',
            ]);
        } catch (Throwable $exception) {
            $this->respondError($exception);
        }
    }

    public function complete(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $result = (new JtlRegistrationService())->complete();
            $this->respond([
                'registration_request_id' => $result['registration_request_id'],
                'granted_scopes' => $result['granted_scopes'],
                'message' => 'API token de JTL guardado correctamente.',
            ]);
        } catch (Throwable $exception) {
            $this->respondError($exception);
        }
    }

    public function reset(): void
    {
        if (!$this->isPost()) {
            $this->methodNotAllowed();
            return;
        }

        Database::migrate();

        try {
            $cancelled = (new JtlRegistrationService())->resetPending();
            $this->respond([
                'message' => $cancelled
                    ? 'Solicitud pendiente descartada localmente. Ya puedes registrar la app de nuevo.'
                    : 'No habia solicitud pendiente para descartar.',
            ]);
        } catch (Throwable $exception) {
            $this->respondError($exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($payload, JSON_THROW_ON_ERROR);
            return;
        }

        header('Location: ' . $this->url('/') . '?notice=' . rawurlencode((string) $payload['message']), true, 303);
    }

    private function respondError(Throwable $exception): void
    {
        if ($this->wantsJson()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
            return;
        }

        header('Location: ' . $this->url('/') . '?notice=' . rawurlencode($exception->getMessage()), true, 303);
    }
}
