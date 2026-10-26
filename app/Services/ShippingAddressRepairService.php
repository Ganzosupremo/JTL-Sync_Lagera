<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\OrderDraft;
use App\Support\Logger;
use Throwable;

/**
 * Orders that reached Packiyo before the marketplace sent JTL the address went out as "Unknown"
 * with only an email. This reads the address JTL has now and writes it to the Packiyo order.
 * Orders whose tracking is already back in JTL are left alone: the parcel has left.
 */
final class ShippingAddressRepairService
{
    public function __construct(
        private readonly ?JtlClient $jtl = null,
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?MappingService $mapping = null,
        private readonly ?OrderDraft $drafts = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * With $apply false nothing is written; the result says what would change.
     *
     * @return array{revisadas: int, corregidas: int, sin_direccion_en_jtl: int, errores: int, aplicar: bool, ordenes: array<int, array<string, mixed>>}
     */
    public function repair(bool $apply = false, int $limit = 200): array
    {
        $summary = ['revisadas' => 0, 'corregidas' => 0, 'sin_direccion_en_jtl' => 0, 'errores' => 0, 'aplicar' => $apply, 'ordenes' => []];

        foreach ($this->drafts()->sentWithoutShippingAddress($limit) as $row) {
            $summary['revisadas']++;
            $jtlOrderId = (string) $row['jtl_order_id'];
            $packiyoOrderId = (string) $row['packiyo_order_id'];
            $result = [
                'jtl_order_id' => $jtlOrderId,
                'jtl_order_number' => $row['jtl_order_number'],
                'packiyo_order_id' => $packiyoOrderId,
                'packiyo_order_number' => $row['packiyo_order_number'],
            ];

            try {
                $order = $this->unwrap($this->jtl()->getOrder($jtlOrderId));

                if (!$this->mapper()->hasShippingAddress($order)) {
                    $summary['sin_direccion_en_jtl']++;
                    $summary['ordenes'][] = $result + ['estado' => 'sin_direccion_en_jtl'];
                    continue;
                }

                $contact = $this->mapper()->packiyoContactAttributes($order);
                $shipping = $contact['shipping_contact_information_data'];
                $result['direccion'] = trim($shipping['name'] . ', ' . $shipping['address'] . ', ' . $shipping['zip'] . ' ' . $shipping['city'] . ', ' . $shipping['country']);

                if (!$apply) {
                    $summary['ordenes'][] = $result + ['estado' => 'por_corregir'];
                    continue;
                }

                $this->packiyo()->updateOrder($packiyoOrderId, [
                    'data' => [
                        'type' => 'orders',
                        'id' => $packiyoOrderId,
                        'attributes' => $contact,
                    ],
                ]);
                $this->drafts()->updateSentAddresses(
                    $jtlOrderId,
                    $this->mapper()->shippingAddress($order),
                    $this->mapper()->billingAddress($order),
                    $contact
                );
                $summary['corregidas']++;
                $summary['ordenes'][] = $result + ['estado' => 'corregida'];
                $this->log()->info('order_sync', 'Direccion de envio corregida en Packiyo ' . $packiyoOrderId . ' para la orden JTL ' . $jtlOrderId . '.');
            } catch (Throwable $exception) {
                $summary['errores']++;
                $summary['ordenes'][] = $result + ['estado' => 'error', 'error' => $exception->getMessage()];
                $this->log()->error('order_sync', 'No se pudo corregir la direccion de la orden JTL ' . $jtlOrderId . ': ' . $exception->getMessage());
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function unwrap(array $response): array
    {
        foreach (['data', 'Data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && !array_is_list($response[$key])) {
                return $response[$key];
            }
        }

        return $response;
    }

    private function jtl(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function packiyo(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function mapper(): MappingService
    {
        return $this->mapping ?? new MappingService();
    }

    private function drafts(): OrderDraft
    {
        return $this->drafts ?? new OrderDraft();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}
