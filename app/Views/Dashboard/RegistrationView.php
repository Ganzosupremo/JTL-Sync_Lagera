<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class RegistrationView extends View
{
    /** @param array<string, mixed>|null $registration */
    public function render(?array $registration): string
    {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Registro JTL-Wawi</h2>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Estado</span>
                            <strong><span class="status <?= $this->e($this->registrationStatus($registration)) ?>"><?= $this->e($this->registrationStatus($registration)) ?></span></strong>
                        </div>
                        <div class="detail">
                            <span>Request ID</span>
                            <strong><?= $this->e($registration['registration_request_id'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Endpoint</span>
                            <strong><?= $this->e($registration['authentication_endpoint'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>API version</span>
                            <strong><?= $this->e($registration['api_version'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Solicitado</span>
                            <strong><?= $this->e($registration['requested_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Aprobado</span>
                            <strong><?= $this->e($registration['approved_at'] ?? '-') ?></strong>
                        </div>
                    </div>

                    <div class="actions">
                        <?php if ($this->registrationStatus($registration) !== 'registration_pending'): ?>
                            <form action="<?= $this->e($this->url('/jtl/register')) ?>" method="post">
                                <button class="button" type="submit"><?= $this->e($this->registrationActionLabel($registration)) ?></button>
                            </form>
                        <?php endif; ?>

                        <?php if ($this->registrationStatus($registration) === 'registration_pending'): ?>
                            <form action="<?= $this->e($this->url('/jtl/register/complete')) ?>" method="post">
                                <button class="button" type="submit">Obtener API token</button>
                            </form>
                            <form action="<?= $this->e($this->url('/jtl/register/reset')) ?>" method="post">
                                <button class="button secondary" type="submit">Descartar pendiente local</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($this->registrationStatus($registration) === 'registration_pending'): ?>
                        <div class="field-hint">Si cancelaste la solicitud en JTL-Wawi o necesitas cambiar scopes, descarta la pendiente local y luego registra la app de nuevo.</div>
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed>|null $registration */
    private function registrationStatus(?array $registration): string
    {
        if ($registration === null) {
            return 'missing_config';
        }

        if (($registration['status'] ?? '') === 'approved' && $this->hasUsableApiKey($registration)) {
            return 'configured';
        }

        if (($registration['status'] ?? '') === 'pending') {
            return 'registration_pending';
        }

        if (($registration['status'] ?? '') === 'cancelled') {
            return 'registration_cancelled';
        }

        return 'missing_config';
    }

    /** @param array<string, mixed>|null $registration */
    private function registrationActionLabel(?array $registration): string
    {
        return $this->registrationStatus($registration) === 'configured'
            ? 'Registrar de nuevo'
            : 'Registrar app en JTL';
    }

    /** @param array<string, mixed>|null $registration */
    private function hasUsableApiKey(?array $registration): bool
    {
        $apiKey = $registration['api_key'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '' && $apiKey !== 'Array';
    }
}
