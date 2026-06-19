<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\OrderMapping;
use App\Models\SyncLog;
use App\Support\Database;

final class DashboardController
{
    public function index(): void
    {
        Database::migrate();

        $mappings = new OrderMapping();
        $logs = new SyncLog();
        $jtl = new JtlClient();
        $packiyo = new PackiyoClient();

        $summary = [
            'last_sync' => $mappings->lastSyncedAt() ?? '-',
            'synced_today' => $mappings->countSyncedToday(),
            'errors_today' => $logs->countErrorsToday(),
            'jtl_status' => $jtl->status(),
            'packiyo_status' => $packiyo->status(),
        ];

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render($summary, $mappings->recent(50), $logs->recent(100), $_GET['sync'] ?? null);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $logs
     */
    private function render(array $summary, array $mappings, array $logs, mixed $notice): string
    {
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lagera JTL Sync</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #1b1f24;
            --muted: #667085;
            --line: #d9dee7;
            --accent: #2563eb;
            --ok: #16803c;
            --warn: #a16207;
            --bad: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.45;
        }

        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 48px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }

        h1, h2 {
            margin: 0;
            letter-spacing: 0;
        }

        h1 {
            font-size: 24px;
        }

        h2 {
            font-size: 16px;
            margin-bottom: 12px;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
        }

        .button {
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            min-height: 40px;
            padding: 0 16px;
        }

        .notice {
            border: 1px solid #b9d3ff;
            background: #edf5ff;
            border-radius: 6px;
            margin-bottom: 16px;
            padding: 10px 12px;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .metric, section {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .metric {
            min-height: 86px;
            padding: 14px;
        }

        .metric span {
            color: var(--muted);
            display: block;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .metric strong {
            display: block;
            font-size: 18px;
            overflow-wrap: anywhere;
        }

        .status {
            border-radius: 999px;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
        }

        .status.configured {
            background: #e7f6ec;
            color: var(--ok);
        }

        .status.missing_config {
            background: #fff4df;
            color: var(--warn);
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 18px;
        }

        section {
            overflow: hidden;
        }

        .section-head {
            border-bottom: 1px solid var(--line);
            padding: 14px 16px 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th, td {
            border-bottom: 1px solid var(--line);
            padding: 10px 16px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #fbfcfe;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .empty {
            color: var(--muted);
            padding: 16px;
        }

        .level-error {
            color: var(--bad);
            font-weight: 700;
        }

        .level-warning {
            color: var(--warn);
            font-weight: 700;
        }

        .level-info {
            color: var(--ok);
            font-weight: 700;
        }

        @media (max-width: 900px) {
            .summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            header {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 620px) {
            .shell {
                width: min(100% - 20px, 1180px);
                padding-top: 18px;
            }

            .summary {
                grid-template-columns: 1fr;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header>
            <div>
                <h1>Lagera JTL Sync</h1>
                <p class="subtitle">JTL -> Packiyo</p>
            </div>
            <form action="<?= $this->e($this->url('/sync')) ?>" method="post">
                <button class="button" type="submit">Sincronizar ahora</button>
            </form>
        </header>

        <?php if (is_string($notice) && $notice !== ''): ?>
            <div class="notice"><?= $this->e($notice) ?></div>
        <?php endif; ?>

        <div class="summary">
            <div class="metric">
                <span>Ultima sincronizacion</span>
                <strong><?= $this->e($summary['last_sync']) ?></strong>
            </div>
            <div class="metric">
                <span>Pedidos hoy</span>
                <strong><?= $this->e($summary['synced_today']) ?></strong>
            </div>
            <div class="metric">
                <span>Errores hoy</span>
                <strong><?= $this->e($summary['errors_today']) ?></strong>
            </div>
            <div class="metric">
                <span>API JTL</span>
                <strong><span class="status <?= $this->e($summary['jtl_status']) ?>"><?= $this->e($summary['jtl_status']) ?></span></strong>
            </div>
            <div class="metric">
                <span>API Packiyo</span>
                <strong><span class="status <?= $this->e($summary['packiyo_status']) ?>"><?= $this->e($summary['packiyo_status']) ?></span></strong>
            </div>
        </div>

        <div class="grid">
            <section>
                <div class="section-head">
                    <h2>Pedidos</h2>
                </div>
                <?php if ($mappings === []): ?>
                    <div class="empty">Sin pedidos sincronizados.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>JTL Order</th>
                                <th>Packiyo Order</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr>
                                    <td><?= $this->e($mapping['jtl_order_number'] ?: $mapping['jtl_order_id']) ?></td>
                                    <td><?= $this->e($mapping['packiyo_order_number'] ?: $mapping['packiyo_order_id']) ?></td>
                                    <td><?= $this->e($mapping['synced_at']) ?></td>
                                    <td>synced</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section>
                <div class="section-head">
                    <h2>Logs</h2>
                </div>
                <?php if ($logs === []): ?>
                    <div class="empty">Sin logs.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Nivel</th>
                                <th>Mensaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= $this->e($log['created_at']) ?></td>
                                    <td class="level-<?= $this->e($log['level']) ?>"><?= $this->e($log['level']) ?></td>
                                    <td><?= $this->e($log['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function url(string $path): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');

        return $base . '/' . ltrim($path, '/');
    }
}
