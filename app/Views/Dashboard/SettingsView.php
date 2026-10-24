<?php

declare(strict_types=1);

namespace App\Views\Dashboard;

use App\Models\AppUser;
use App\Models\UserInvitation;
use App\Support\Arr;
use App\Support\Auth;
use App\Support\Config;
use App\Support\JtlScopeList;
use App\Support\Setting;
use App\Support\SettingsCatalog;
use App\Views\View;

final class SettingsView extends View
{
    public function render(): string
    {
        if (!(new Auth())->isAdmin()) {
            return '<section><div class="section-head"><h2>Ajustes</h2></div><div class="section-body">'
                . '<div class="notice">Solo un administrador puede ver y cambiar los ajustes y los usuarios.</div></div></section>';
        }

        $database = Config::get('database.mysql', []);
        $users = (new AppUser())->all();
        $invitations = (new UserInvitation())->recent(500);
        $workflowEvents = $this->cachedSalesOrderWorkflowEvents();
        $workflowEventsReadAt = $this->cachedSessionString('jtl_sales_order_workflow_events_read_at');

        ob_start();
        ?>
            <section>
                <div class="section-head">
                    <h2>Ajustes</h2>
                </div>
                <div class="section-body">
                    <form class="settings-form" action="<?= $this->e($this->url('/settings')) ?>" method="post" autocomplete="off">
                        <?php foreach (SettingsCatalog::sections() as $section): ?>
                            <div>
                                <h3><?= $this->e($section['title'] ?? '') ?></h3>
                                <p class="muted"><?= $this->e($section['description'] ?? '') ?></p>
                                <div class="settings-grid">
                                    <?php foreach (($section['fields'] ?? []) as $field): ?>
                                        <?= $this->renderSettingField($field) ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="actions">
                            <button class="button" type="submit">Guardar ajustes</button>
                        </div>
                    </form>

                    <h3>Workflow events de Aufträge</h3>
                    <p class="muted">Usa esto para sacar el ID del evento manual que crea el Lieferschein. Copia el numero en JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID.</p>
                    <div class="jtl-worker-actions">
                        <form class="inline-form" action="<?= $this->e($this->url('/jtl/workflows/sales-order-events')) ?>" method="post">
                            <button class="button secondary" type="submit">Leer workflow events de JTL</button>
                        </form>
                    </div>

                    <?php if ($workflowEventsReadAt !== null): ?>
                        <div class="field-hint">Leido desde JTL: <?= $this->e($workflowEventsReadAt) ?></div>
                    <?php endif; ?>

                    <?php if ($workflowEvents !== []): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Raw</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workflowEvents as $event): ?>
                                    <tr>
                                        <td><strong><?= $this->e($this->workflowEventId($event) ?: '-') ?></strong></td>
                                        <td><?= $this->e($this->workflowEventName($event) ?: '-') ?></td>
                                        <td class="muted"><?= $this->e($this->shortJson($event, 260)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty">Todavia no hay workflow events leidos. Crea el evento manual en JTL-Wawi y usa el boton para leer su ID.</div>
                    <?php endif; ?>

                    <h3>Usuarios</h3>
                    <form class="invite-form" action="<?= $this->e($this->url('/users/invite')) ?>" method="post">
                        <input name="email" type="email" placeholder="email@empresa.com" required>
                        <input name="ttl_hours" type="number" min="1" max="720" value="<?= $this->e(Setting::get('AUTH_INVITATION_TTL_HOURS', 72)) ?>" aria-label="Horas">
                        <select name="role" aria-label="Rol">
                            <option value="<?= $this->e(AppUser::ROLE_ADMIN) ?>">Administrador</option>
                            <option value="<?= $this->e(AppUser::ROLE_OPERATOR) ?>">Operador (sin ajustes ni usuarios)</option>
                        </select>
                        <button class="button" type="submit">Crear invitacion</button>
                    </form>

                    <?php if ($users === []): ?>
                        <div class="empty">Todavia no hay usuarios en MySQL. Crea una invitacion y abre el link para crear el primer usuario.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                    <th>Ultimo login</th>
                                    <th>Creado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong><?= $this->e($user['username'] ?? '-') ?></strong></td>
                                        <td><?= $this->e($user['email'] ?? '-') ?></td>
                                        <td><?= ($user['role'] ?? AppUser::ROLE_ADMIN) === AppUser::ROLE_OPERATOR ? 'Operador' : 'Administrador' ?></td>
                                        <td><span class="status <?= ((int) ($user['active'] ?? 0) === 1) ? 'active' : 'inactive' ?>"><?= ((int) ($user['active'] ?? 0) === 1) ? 'active' : 'inactive' ?></span></td>
                                        <td><?= $this->e($user['last_login_at'] ?? '-') ?></td>
                                        <td><?= $this->e($user['created_at'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3>Invitaciones recientes</h3>
                    <?php if ($invitations === []): ?>
                        <div class="empty">Sin invitaciones.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Estado</th>
                                    <th>Invitado por</th>
                                    <th>Expira</th>
                                    <th>Creada</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invitations as $invitation): ?>
                                    <?php $status = $this->invitationStatus($invitation); ?>
                                    <tr>
                                        <td><?= $this->e($invitation['email'] ?? '-') ?></td>
                                        <td><span class="status <?= $status === 'pending' ? 'ready' : ($status === 'accepted' ? 'synced' : 'inactive') ?>"><?= $this->e($status) ?></span></td>
                                        <td><?= $this->e($invitation['created_by_username'] ?? '-') ?></td>
                                        <td><?= $this->e($invitation['expires_at'] ?? '-') ?></td>
                                        <td><?= $this->e($invitation['created_at'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($status === 'pending'): ?>
                                                <form class="inline-form" action="<?= $this->e($this->url('/users/invite/revoke')) ?>" method="post">
                                                    <input type="hidden" name="id" value="<?= $this->e($invitation['id'] ?? '') ?>">
                                                    <button class="button secondary small" type="submit">Revocar</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3>Base de datos</h3>
                    <div class="details">
                        <div class="detail">
                            <span>Host</span>
                            <strong><?= $this->e($database['host'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Puerto</span>
                            <strong><?= $this->e($database['port'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Database</span>
                            <strong><?= $this->e($database['database'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Usuario</span>
                            <strong><?= $this->e($database['username'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Charset</span>
                            <strong><?= $this->e($database['charset'] ?? '-') ?></strong>
                        </div>
                        <div class="detail">
                            <span>Collation</span>
                            <strong><?= $this->e($database['collation'] ?? '-') ?></strong>
                        </div>
                    </div>
                </div>
            </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $invitation */
    private function invitationStatus(array $invitation): string
    {
        if (($invitation['accepted_at'] ?? null) !== null) {
            return 'accepted';
        }

        if (($invitation['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }

        $expiresAt = strtotime((string) ($invitation['expires_at'] ?? ''));

        if ($expiresAt !== false && $expiresAt <= time()) {
            return 'expired';
        }

        return 'pending';
    }

    /** @param array<string, mixed> $field */
    private function renderSettingField(array $field): string
    {
        $key = (string) ($field['key'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $id = 'setting-' . strtolower(str_replace('_', '-', $key));
        $value = $this->settingValue($field);
        $full = $type === 'textarea' || in_array($key, [
            'JTL_BASE_URL',
            'PACKIYO_BASE_URL',
            'APP_BASE_URL',
            'JTL_DELIVERY_NOTE_PACKAGES_ENDPOINT',
            'JTL_SALES_ORDER_WORKFLOW_EVENTS_ENDPOINT',
            'JTL_SALES_ORDER_WORKFLOW_TRIGGER_ENDPOINT',
        ], true);

        ob_start();
        ?>
            <div class="setting-field <?= $full ? 'full' : '' ?>">
                <label for="<?= $this->e($id) ?>"><?= $this->e($field['label'] ?? $key) ?></label>

                <?php if ($type === 'boolean'): ?>
                    <select id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>">
                        <option value="true" <?= $value === 'true' ? 'selected' : '' ?>>true</option>
                        <option value="false" <?= $value === 'false' ? 'selected' : '' ?>>false</option>
                    </select>
                <?php elseif ($type === 'select'): ?>
                    <select id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>">
                        <?php foreach (($field['options'] ?? []) as $option): ?>
                            <option value="<?= $this->e($option) ?>" <?= $value === (string) $option ? 'selected' : '' ?>><?= $this->e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'textarea'): ?>
                    <textarea id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>"><?= $this->e($value) ?></textarea>
                <?php elseif (!empty($field['secret'])): ?>
                    <input id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>" type="password" value="" placeholder="Nuevo valor">
                    <div class="field-hint"><?= $this->settingConfigured($key) ? 'Configurado. Dejar vacio para mantenerlo.' : 'Sin configurar.' ?></div>
                <?php else: ?>
                    <input id="<?= $this->e($id) ?>" name="<?= $this->e($key) ?>" type="<?= $type === 'number' ? 'number' : 'text' ?>" value="<?= $this->e($value) ?>">
                <?php endif; ?>

                <?php if ($key === 'JTL_BASE_URL'): ?>
                    <div class="field-hint">En hosting compartido, usa la URL publica del Cloudflare Tunnel, por ejemplo https://jtl-wawi.3plgermany.com.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_WORKER_DISCOVERY_ENABLED'): ?>
                    <div class="field-hint">Solo activa esto para diagnostico. El marketplace abgleich debe ejecutarlo JTL Worker 2.0 en JTL-Wawi, no esta app.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_AUTO_CREATE_DELIVERY_NOTE'): ?>
                    <div class="field-hint">Activalo solo despues de crear en JTL-Wawi un workflow manual de Auftrag/Sales Order que genere el Lieferschein.</div>
                <?php endif; ?>
                <?php if ($key === 'JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID'): ?>
                    <div class="field-hint">Es el ID numerico del workflow event de Auftrag/Sales Order que debe crear el delivery note.</div>
                <?php endif; ?>
                <?php if ($key === 'AUTOMATION_INTERVAL_MINUTES'): ?>
                    <div class="field-hint">360 minutos son 6 horas. El cron puede llamar /automation/tick cada 5 minutos; la app decide si ya toca correr.</div>
                <?php endif; ?>
            </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $field */
    private function settingValue(array $field): string
    {
        $key = (string) ($field['key'] ?? '');
        $default = (string) ($field['default'] ?? '');
        $value = Setting::get($key, $default);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($key === 'JTL_MANDATORY_API_SCOPES' && is_scalar($value)) {
            return implode(',', JtlScopeList::mandatoryFromConfigured((string) $value));
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    private function settingConfigured(string $key): bool
    {
        return Setting::configured($key);
    }

    /** @return array<int, array<string, mixed>> */
    private function cachedSalesOrderWorkflowEvents(): array
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $events = $_SESSION['jtl_sales_order_workflow_events'] ?? [];

        if (!is_array($events)) {
            return [];
        }

        $items = [];

        foreach ($events as $event) {
            if (is_array($event)) {
                $items[] = $event;
            }
        }

        return $items;
    }

    private function cachedSessionString(string $key): ?string
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $value = $_SESSION[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $event */
    private function workflowEventId(array $event): string
    {
        return Arr::firstScalar($event, ['Id', 'id', 'ID']) ?? '';
    }

    /** @param array<string, mixed> $event */
    private function workflowEventName(array $event): string
    {
        return Arr::firstScalar($event, ['Name', 'name', 'DisplayName', 'displayName']) ?? '';
    }
}
