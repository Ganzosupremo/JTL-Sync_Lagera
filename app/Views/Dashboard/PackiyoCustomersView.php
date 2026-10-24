<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class PackiyoCustomersView extends View
{
    /**
     * @param array{active: int, inactive: int, total: int} $customerCounts
     * @param array<int, array<string, mixed>> $activeCustomers
     * @param array<int, array<string, mixed>> $inactiveCustomers
     * @param array<string, mixed>|null $customerSyncState
     */
    public function render(
        array $customerCounts,
        array $activeCustomers,
        array $inactiveCustomers,
        ?array $customerSyncState
    ): string {
        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Clientes Packiyo</h2>
                    <form action="<?= $this->e($this->url('/packiyo/customers/sync')) ?>" method="post">
                        <button class="button" type="submit">Actualizar desde Packiyo</button>
                    </form>
                </div>
                <div class="section-body">
                    <div class="details">
                        <div class="detail">
                            <span>Total cacheado</span>
                            <strong><?= $this->e($customerCounts['total']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Activos</span>
                            <strong><?= $this->e($customerCounts['active']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Inactivos</span>
                            <strong><?= $this->e($customerCounts['inactive']) ?></strong>
                        </div>
                        <div class="detail">
                            <span>Ultimo cambio leido</span>
                            <strong><?= $this->e($customerSyncState['last_synced_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Ultima corrida</span>
                            <strong><?= $this->e($customerSyncState['last_success_at'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Estado</span>
                            <strong><?= $this->e($customerSyncState['last_message'] ?? '-') ?></strong>
                        </div>
                    </div>

                    <h3>Clientes activos</h3>
                    <?= $this->renderCustomerTable($activeCustomers, true) ?>

                    <h3>Clientes inactivos</h3>
                    <?= $this->renderCustomerTable($inactiveCustomers, false) ?>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $customers
     */
    private function renderCustomerTable(array $customers, bool $active): string
    {
        if ($customers === []) {
            return '<div class="empty">' . ($active ? 'Sin clientes activos cacheados.' : 'Sin clientes inactivos.') . '</div>';
        }

        ob_start();
        ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Packiyo updated</th>
                        <th>Cache</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong><?= $this->e($customer['packiyo_customer_id'] ?? '-') ?></strong></td>
                            <td><?= $this->e($this->customerDisplayName($customer)) ?></td>
                            <td><?= $this->e($customer['email'] ?? '-') ?></td>
                            <td class="mono"><?= $this->e($customer['packiyo_updated_at'] ?? '-') ?></td>
                            <td class="mono"><?= $this->e($customer['synced_at'] ?? '-') ?></td>
                            <td><span class="status <?= $active ? 'active' : 'inactive' ?>"><?= $active ? 'active' : 'inactive' ?></span></td>
                            <td>
                                <form class="inline-form" action="<?= $this->e($this->url($active ? '/packiyo/customers/deactivate' : '/packiyo/customers/activate')) ?>" method="post">
                                    <input type="hidden" name="customer_id" value="<?= $this->e($customer['packiyo_customer_id'] ?? '') ?>">
                                    <button class="button small <?= $active ? 'danger' : '' ?>" type="submit"><?= $active ? 'Desactivar' : 'Activar' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php

        return (string) ob_get_clean();
    }
}
