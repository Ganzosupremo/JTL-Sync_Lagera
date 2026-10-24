<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Support\Config;
use App\Views\View;

final class AutomationView extends View
{
    /** @param array<string, mixed>|null $state */
    public function render(?array $state): string
    {
        $enabled = (bool) Config::get('automation.enabled', true);
        $intervalMinutes = max(1, (int) Config::get('automation.interval_minutes', 360));
        $lastRun = $state['last_success_at'] ?? null;
        $nextRun = $this->automationNextRunAt(is_string($lastRun) ? $lastRun : null, $intervalMinutes, $enabled);
        $tokenConfigured = trim((string) Config::get('automation.token', '')) !== '';
        $tickUrl = $this->automationTickUrl();
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Automatizacion</h2>
                    <form action="<?= $this->e($this->url('/automation/manual')) ?>" method="post">
                        <button class="button" type="submit">Ejecutar ahora</button>
                    </form>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Estado</span>
                            <strong><span class="status <?= $enabled ? 'active' : 'inactive' ?>"><?= $enabled ? 'activa' : 'inactiva' ?></span></strong>
                        </div>
                        <div class="detail">
                            <span>Intervalo</span>
                            <strong><?= $this->e((string) $intervalMinutes) ?> min</strong>
                        </div>
                        <div class="detail">
                            <span>Ultima corrida</span>
                            <strong><?= $this->e(is_string($lastRun) && $lastRun !== '' ? $lastRun : '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Proxima corrida</span>
                            <strong><?= $this->e($nextRun) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Cron HTTP</span>
                            <strong><span class="status <?= $tokenConfigured ? 'configured' : 'missing_config' ?>"><?= $tokenConfigured ? 'configurado' : 'sin_token' ?></span></strong>
                        </div>
                    </div>

                    <?php if (is_string($state['last_message'] ?? null) && $state['last_message'] !== ''): ?>
                        <div class="field-hint">Ultimo resultado: <?= $this->e((string) $state['last_message']) ?></div>
                    <?php endif; ?>

                    <div class="field-hint">Configura un cron frecuente, por ejemplo cada 5 minutos. La ruta <code>/automation/tick</code> revisa el intervalo y solo ejecuta el flujo completo cuando ya toca.</div>

                    <pre class="code-block"><code>*/5 * * * * curl -fsS -H "X-Automation-Token: &lt;AUTOMATION_TOKEN&gt;" <?= $this->e($tickUrl) ?></code></pre>
                    <pre class="code-block"><code>*/5 * * * * php /ruta/al/proyecto/cron/automation.php</code></pre>

                    <?php if (!$tokenConfigured): ?>
                        <div class="notice">Configura AUTOMATION_TOKEN en Ajustes para usar el cron HTTP. Si usas cron CLI dentro del mismo servidor, el token HTTP no es necesario.</div>
                    <?php endif; ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    public function automationNextRunAt(?string $lastRun, int $intervalMinutes, bool $enabled): string
    {
        if (!$enabled) {
            return '-';
        }

        if ($lastRun === null || trim($lastRun) === '') {
            return 'proximo tick';
        }

        $timestamp = strtotime($lastRun);

        if ($timestamp === false) {
            return 'proximo tick';
        }

        $nextRun = $timestamp + ($intervalMinutes * 60);

        if ($nextRun <= time()) {
            return 'proximo tick';
        }

        return date('Y-m-d H:i:s', $nextRun);
    }

    private function automationTickUrl(): string
    {
        $configured = trim((string) Config::get('app.base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/') . $this->url('/automation/tick');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost';

        return $scheme . '://' . $host . $this->url('/automation/tick');
    }
}
