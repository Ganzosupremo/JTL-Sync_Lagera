<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class OrdersView extends View
{
    /** @param array<int, array<string, mixed>> $mappings */
    public function render(array $mappings): string
    {
        ob_start();
        ?>
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
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $mapping): ?>
                                <tr>
                                    <td class="mono"><?= $this->e($mapping['jtl_order_number'] ?: $mapping['jtl_order_id']) ?></td>
                                    <td class="mono"><?= $this->e($mapping['packiyo_order_number'] ?: $mapping['packiyo_order_id']) ?></td>
                                    <td class="mono"><?= $this->e($mapping['synced_at']) ?></td>
                                    <td>synced</td>
                                    <td><a class="button secondary small button-link" href="<?= $this->e($this->url('/?') . http_build_query(['tab' => 'jtl-orders', 'order_reference' => $mapping['jtl_order_id']])) ?>">Editar y mapear</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php

        return (string) ob_get_clean();
    }
}
