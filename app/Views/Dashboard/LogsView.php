<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Views\View;

final class LogsView extends View
{
    /** @param array<int, array<string, mixed>> $logs */
    public function render(array $logs): string
    {
        ob_start();
        ?>
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
        <?php

        return (string) ob_get_clean();
    }
}
