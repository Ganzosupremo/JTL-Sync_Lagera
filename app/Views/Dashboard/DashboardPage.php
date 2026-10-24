<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Support\Auth;
use App\Support\Config;
use App\Support\SecretBox;
use App\Support\Setting;
use App\Views\View;

/** The dashboard shell: header, tabs, notices and the tab view picked by the controller. */
final class DashboardPage extends View
{
    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $registration
     * @param array<int, array<string, mixed>> $orderSources
     * @param array<int, array<string, mixed>> $customerMappings
     * @param array{active: int, inactive: int, total: int} $customerCounts
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $inactiveCustomers
     * @param array<string, mixed>|null $customerSyncState
     * @param array<string, mixed>|null $fulfillmentState
     * @param array<string, mixed>|null $automationState
     * @param array<int, array<string, mixed>> $fulfillmentRows
     * @param array<int, array<string, mixed>> $jtlOrders
     * @param array<int, array<string, mixed>> $jtlWorkerSyncs
     * @param array<string, mixed>|null $jtlWorkerStatus
     * @param string $jtlOrderCustomerFilter
     * @param string $jtlOrderMappedCustomerFilter
     * @param string $fulfillmentCustomerId
     * @param array<int, array<string, mixed>> $productSkuAliasRows
     * @param array<int, array<string, mixed>> $productSkuAliasProducts
     * @param array<int, array<string, mixed>> $productRows
     * @param array<int, array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $logs
     */
    public function render(
        string $tab,
        array $summary,
        ?array $registration,
        array $orderSources,
        array $customerMappings,
        array $customerCounts,
        array $activeCustomers,
        array $inactiveCustomers,
        ?array $customerSyncState,
        ?array $fulfillmentState,
        ?array $automationState,
        array $fulfillmentRows,
        array $jtlOrders,
        ?string $jtlOrdersError,
        array $jtlWorkerSyncs,
        ?array $jtlWorkerStatus,
        ?string $jtlWorkerError,
        string $jtlOrderCustomerFilter,
        string $jtlOrderMappedCustomerFilter,
        ?array $orderDetail,
        ?string $orderDetailError,
        string $fulfillmentCustomerId,
        array $productSkuAliasRows,
        array $productSkuAliasProducts,
        ?string $productSkuAliasError,
        string $selectedSkuAliasCustomerId,
        string $selectedNameCustomerId,
        array $productNameMappings,
        array $productNameCatalog,
        ?string $productNameCatalogError,
        array $productRows,
        ?string $productImportError,
        string $selectedProductCustomerId,
        string $productImportCategoryId,
        string $productImportWarehouseId,
        array $mappings,
        array $logs,
        array $correctionData,
        ?string $correctionError,
        mixed $notice
    ): string {
        $automationEnabled = (bool) Config::get('automation.enabled', true);
        $automationIntervalMinutes = max(1, (int) Config::get('automation.interval_minutes', 360));
        $automationLastRun = $automationState['last_success_at'] ?? null;
        $automationNextRun = (new AutomationView())->automationNextRunAt(
            is_string($automationLastRun) ? $automationLastRun : null,
            $automationIntervalMinutes,
            $automationEnabled
        );
        $automationLastSummary = $this->automationLastSummary($automationState);
        $auth = new Auth();
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lagera JTL Sync</title>
    <style>
<?= $this->asset('dashboard.css') ?>
    </style>
</head>
<body>
    <main class="shell">
        <header>
            <div>
                <h1>Lagera JTL Sync</h1>
                <p class="subtitle">JTL -> Packiyo</p>
            </div>
            <div class="actions">
                <form action="<?= $this->e($this->url('/automation/manual')) ?>" method="post">
                    <button class="button" type="submit">Sincronizar ahora</button>
                </form>
                <?php if ($auth->required()): ?>
                    <form action="<?= $this->e($this->url('/logout')) ?>" method="post">
                        <button class="button secondary" type="submit">Cerrar sesion</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php if (is_string($notice) && $notice !== ''): ?>
            <div class="notice"><?= $this->e($notice) ?></div>
        <?php endif; ?>

        <?php foreach ($this->securityWarnings($auth) as $warning): ?>
            <div class="notice security-warning"><?= $this->e($warning) ?></div>
        <?php endforeach; ?>

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
            <div class="metric">
                <span>Proximo auto sync</span>
                <strong><?= $this->e($automationNextRun) ?></strong>
                <div class="metric-note"><?= $this->e($automationLastSummary) ?></div>
            </div>
        </div>

        <nav class="tabs" aria-label="Dashboard">
            <a class="tab <?= $tab === 'overview' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('overview')) ?>">Resumen</a>
            <a class="tab <?= $tab === 'jtl-orders' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('jtl-orders')) ?>">Ordenes JTL</a>
            <a class="tab <?= $tab === 'fulfillment' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('fulfillment')) ?>">Fulfillment</a>
            <a class="tab <?= $tab === 'order-corrections' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('order-corrections')) ?>">Correccion de ordenes</a>
            <a class="tab <?= $tab === 'packiyo-customers' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('packiyo-customers')) ?>">Clientes Packiyo</a>
            <a class="tab <?= $tab === 'customer-mappings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('customer-mappings')) ?>">Mapeos</a>
            <a class="tab <?= $tab === 'products' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('products')) ?>">Productos</a>
            <a class="tab <?= $tab === 'settings' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('settings')) ?>">Ajustes</a>
            <a class="tab <?= $tab === 'logs' ? 'active' : '' ?>" href="<?= $this->e($this->tabUrl('logs')) ?>">Logs</a>
        </nav>

        <div class="grid">
            <?php if ($tab === 'overview'): ?>
                <?= (new RegistrationView())->render($registration) ?>
                <?= (new AutomationView())->render($automationState) ?>
                <?= (new OrdersView())->render($mappings) ?>
            <?php endif; ?>

            <?php if ($tab === 'jtl-orders'): ?>
                <?= (new OrderDetailView())->render($orderDetail, $orderDetailError) ?>
                <?= (new JtlOrdersView())->render(
                    $jtlOrders,
                    $jtlOrdersError,
                    $jtlWorkerSyncs,
                    $jtlWorkerStatus,
                    $jtlWorkerError,
                    $activeCustomers,
                    $jtlOrderCustomerFilter,
                    $jtlOrderMappedCustomerFilter
                ) ?>
            <?php endif; ?>

            <?php if ($tab === 'fulfillment'): ?>
                <?= (new FulfillmentView())->render($fulfillmentRows, $fulfillmentState, $activeCustomers, $fulfillmentCustomerId) ?>
            <?php endif; ?>

            <?php if ($tab === 'order-corrections'): ?>
                <?= (new OrderCorrectionsView())->render($correctionData, $correctionError) ?>
            <?php endif; ?>

            <?php if ($tab === 'packiyo-customers'): ?>
                <?= (new PackiyoCustomersView())->render($customerCounts, $activeCustomers, $inactiveCustomers, $customerSyncState) ?>
            <?php endif; ?>

            <?php if ($tab === 'customer-mappings'): ?>
                <?= (new CustomerMappingsView())->render(
                    $orderSources,
                    $customerMappings,
                    $activeCustomers,
                    $productSkuAliasRows,
                    $productSkuAliasProducts,
                    $productSkuAliasError,
                    $selectedSkuAliasCustomerId,
                    $selectedNameCustomerId,
                    $productNameMappings,
                    $productNameCatalog,
                    $productNameCatalogError
                ) ?>
            <?php endif; ?>

            <?php if ($tab === 'products'): ?>
                <?= (new ProductsView())->render($activeCustomers, $productRows, $productImportError, $selectedProductCustomerId, $productImportCategoryId, $productImportWarehouseId) ?>
            <?php endif; ?>

            <?php if ($tab === 'settings'): ?>
                <?= (new SettingsView())->render() ?>
            <?php endif; ?>

            <?php if ($tab === 'logs'): ?>
                <?= (new LogsView())->render($logs) ?>
            <?php endif; ?>
        </div>
    </main>
    <script>
<?= $this->asset('dashboard.js') ?>
    </script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed>|null $state */
    private function automationLastSummary(?array $state): string
    {
        $message = $state['last_message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            return 'Sin corridas previas.';
        }

        $message = trim($message);

        return strlen($message) > 96 ? substr($message, 0, 93) . '...' : $message;
    }

    /** @return array<int, string> */
    private function securityWarnings(Auth $auth): array
    {
        $warnings = [];

        if (!$auth->enabled()) {
            $warnings[] = 'Login desactivado: cualquiera con la URL puede usar este panel, incluidos los ajustes y las API keys. Activa "Requerir login" en Ajustes.';
        } elseif (!$auth->configured()) {
            $warnings[] = 'Login sin usuarios: el panel esta abierto hasta que crees el primer usuario por invitacion en Ajustes.';
        }

        try {
            if (!SecretBox::enabled()) {
                $warnings[] = 'APP_KEY sin configurar: las API keys y tokens se guardan sin cifrar en MySQL. Genera una clave con "php scripts/generate_app_key.php" y ponla en el .env.';
            }
        } catch (\Throwable $exception) {
            $warnings[] = $exception->getMessage();
        }

        if (Setting::unreadableSecrets() !== []) {
            $warnings[] = 'No se pudieron descifrar estos ajustes (APP_KEY distinto al usado al guardarlos): '
                . implode(', ', Setting::unreadableSecrets()) . '. Vuelve a guardarlos en Ajustes.';
        }

        if (!(bool) Config::get('jtl.ssl_verify', false)) {
            $warnings[] = 'Verificacion SSL hacia JTL desactivada: activa "Verificar SSL" en Ajustes si JTL usa un certificado valido (por ejemplo, a traves del tunnel de Cloudflare).';
        }

        return $warnings;
    }
}
