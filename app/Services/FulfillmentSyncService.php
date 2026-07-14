<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\JtlClient;
use App\Clients\PackiyoClient;
use App\Models\AppSyncState;
use App\Models\FulfillmentSync;
use App\Models\OrderMapping;
use App\Support\HttpException;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class FulfillmentSyncService
{
    public function __construct(
        private readonly ?OrderMapping $orders = null,
        private readonly ?FulfillmentSync $fulfillments = null,
        private readonly ?PackiyoClient $packiyo = null,
        private readonly ?JtlClient $jtl = null,
        private readonly ?AppSyncState $states = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /** @return array{checked: int, fulfilled: int, synced: int, skipped: int, failed: int, packiyo_customer_id: string|null, message: string} */
    public function sync(int $limit = 200, ?string $packiyoCustomerId = null): array
    {
        $packiyoCustomerId = trim((string) $packiyoCustomerId);
        $packiyoCustomerId = $packiyoCustomerId !== '' ? $packiyoCustomerId : null;
        $summary = [
            'checked' => 0,
            'fulfilled' => 0,
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'packiyo_customer_id' => $packiyoCustomerId,
            'message' => '',
        ];

        $this->log()->info(
            'fulfillment_sync',
            'Fulfillment sync started' . ($packiyoCustomerId !== null ? ' for Packiyo customer ' . $packiyoCustomerId : '') . '.'
        );
        $jtlUnavailableMessage = null;

        foreach ($this->orderModel()->all($limit, $packiyoCustomerId) as $mapping) {
            $summary['checked']++;

            try {
                $shipments = $this->packiyoFulfillments($mapping);

                if ($shipments === []) {
                    $summary['skipped']++;
                    continue;
                }

                $summary['fulfilled']++;

                foreach ($shipments as $shipment) {
                    if ($this->fulfillmentModel()->exists((string) $mapping['jtl_order_id'], $shipment['tracking_number'])) {
                        $summary['skipped']++;
                        $this->log()->info(
                            'fulfillment_sync',
                            'Tracking ' . $shipment['tracking_number'] . ' for JTL order '
                            . (string) $mapping['jtl_order_id'] . ' was already completed in JTL sync history.'
                        );
                        continue;
                    }

                    try {
                        $this->sendShipmentToJtl($mapping, $shipment);
                    } catch (Throwable $exception) {
                        if ($this->jtlClient()->isReachabilityException($exception)) {
                            throw new JtlUnavailableDuringFulfillmentException(
                                $this->jtlClient()->friendlyReachabilityMessage($exception),
                                0,
                                $exception
                            );
                        }

                        throw $exception;
                    }

                    $summary['synced']++;
                }
            } catch (JtlUnavailableDuringFulfillmentException $exception) {
                $summary['failed']++;
                $jtlUnavailableMessage = $exception->getMessage();
                $this->log()->error('fulfillment_sync', 'Fulfillment sync stopped: ' . $jtlUnavailableMessage);
                break;
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->log()->error(
                    'fulfillment_sync',
                    'Unable to sync fulfillment for JTL order '
                    . (string) ($mapping['jtl_order_number'] ?: $mapping['jtl_order_id'])
                    . ': ' . $this->friendlyException($exception)
                );
            }
        }

        $summary['message'] = ($jtlUnavailableMessage !== null ? 'Fulfillment sync detenido: ' . $jtlUnavailableMessage . ' ' : '')
            . sprintf(
                'Fulfillment sync terminado%s: %d revisadas, %d con tracking, %d enviadas a JTL, %d omitidas, %d errores.',
                $packiyoCustomerId !== null ? ' para cliente Packiyo ' . $packiyoCustomerId : '',
                $summary['checked'],
                $summary['fulfilled'],
                $summary['synced'],
                $summary['skipped'],
                $summary['failed']
            );

        $this->stateModel()->markSuccess('fulfillment_sync', date('Y-m-d H:i:s'), $summary['message']);
        $this->log()->info('fulfillment_sync', $summary['message']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<int, array{
     *     tracking_number: string,
     *     tracking_url: string,
     *     carrier: string,
     *     shipped_at: string,
     *     packiyo_shipment_id: string,
     *     packiyo_tracking_id: string
     * }>
     */
    private function packiyoFulfillments(array $mapping): array
    {
        $response = $this->packiyoOrderResponse($mapping);

        if ($response === null) {
            return [];
        }

        $data = $this->firstPackiyoData($response) ?? [];
        $attributes = $this->arrayValue($data, ['attributes', 'Attributes']);

        if ($this->stringValue($attributes, ['archived_at', 'archivedAt', 'deleted_at', 'deletedAt']) !== null) {
            return [];
        }

        $included = $this->includedLookup($response);
        $shipments = [];
        $seen = [];

        foreach ($this->shipmentResources($data, $included) as $shipment) {
            $shipmentAttributes = $this->arrayValue($shipment, ['attributes', 'Attributes']);
            $shipmentId = $this->stringValue($shipment, ['id', 'Id']) ?? '';
            $shippedAt = $this->dateString(
                $this->stringValue($attributes, ['fulfilled_at', 'fulfilledAt'])
                ?? $this->stringValue($shipmentAttributes, ['shipped_at', 'shippedAt', 'updated_at', 'updatedAt', 'created_at', 'createdAt'])
            );
            $carrier = $this->shipmentCarrier($shipment, $included);

            foreach ($this->trackingResources($shipment, $included) as $tracking) {
                $trackingAttributes = $this->arrayValue($tracking, ['attributes', 'Attributes']);
                $trackingNumber = $this->stringValue($trackingAttributes, ['tracking_number', 'trackingNumber', 'number']);

                if ($trackingNumber === null || isset($seen[$trackingNumber])) {
                    continue;
                }

                $seen[$trackingNumber] = true;
                $shipments[] = [
                    'tracking_number' => $trackingNumber,
                    'tracking_url' => $this->stringValue($trackingAttributes, ['tracking_url', 'trackingUrl']) ?? '',
                    'carrier' => $carrier,
                    'shipped_at' => $shippedAt ?? date('Y-m-d H:i:s'),
                    'packiyo_shipment_id' => $shipmentId,
                    'packiyo_tracking_id' => $this->stringValue($tracking, ['id', 'Id']) ?? '',
                ];
            }
        }

        return $shipments;
    }

    /** @param array<string, mixed> $mapping */
    private function packiyoOrderResponse(array $mapping): ?array
    {
        try {
            return $this->packiyoClient()->getOrder((string) $mapping['packiyo_order_id']);
        } catch (HttpException $exception) {
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }

            $response = $this->packiyoClient()->findOrder((string) $mapping['jtl_order_id']);
            $order = $this->firstPackiyoData($response);

            return $order === null ? null : $response;
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, string> $shipment
     */
    private function sendShipmentToJtl(array $mapping, array $shipment): void
    {
        $jtlOrderId = (string) $mapping['jtl_order_id'];
        $jtlOrderNumber = isset($mapping['jtl_order_number']) ? (string) $mapping['jtl_order_number'] : null;
        $deliveryNote = $this->firstDeliveryNote($this->jtlClient()->getDeliveryNotes($jtlOrderId, $jtlOrderNumber));

        if ($deliveryNote === null) {
            throw new RuntimeException(
                'No JTL delivery note was found for order ' . ($jtlOrderNumber ?: $jtlOrderId) . '.'
            );
        }

        $deliveryNoteId = $this->deliveryNoteId($deliveryNote);

        if ($deliveryNoteId === null) {
            throw new RuntimeException('JTL delivery note has no usable id.');
        }

        foreach ($this->jtlClient()->getDeliveryNotePackages($deliveryNoteId) as $package) {
            if ($this->stringValue($package, ['TrackingID', 'trackingID', 'trackingId', 'tracking_number']) === $shipment['tracking_number']) {
                $this->saveFulfillment($mapping, $shipment, $deliveryNoteId, $this->stringValue($package, ['Id', 'id', 'PackageId', 'packageId']), 'already_present');
                return;
            }
        }

        $response = $this->jtlClient()->createDeliveryNotePackages($deliveryNoteId, [[
            'ShippedDate' => $this->jtlDate($shipment['shipped_at']),
            'TrackingID' => $shipment['tracking_number'],
            'Comment' => $this->shipmentComment($shipment),
        ]]);

        $createdPackage = $this->firstDeliveryNote($this->collection($response));
        $this->saveFulfillment(
            $mapping,
            $shipment,
            $deliveryNoteId,
            $createdPackage !== null ? $this->stringValue($createdPackage, ['Id', 'id', 'PackageId', 'packageId']) : null,
            'synced'
        );
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, string> $shipment
     */
    private function saveFulfillment(
        array $mapping,
        array $shipment,
        string $deliveryNoteId,
        ?string $packageId,
        string $status
    ): void {
        $this->fulfillmentModel()->upsert([
            'jtl_order_id' => (string) $mapping['jtl_order_id'],
            'jtl_order_number' => $mapping['jtl_order_number'] ?? null,
            'packiyo_order_id' => (string) $mapping['packiyo_order_id'],
            'packiyo_customer_id' => $mapping['packiyo_customer_id'] ?? null,
            'packiyo_customer_name' => $mapping['packiyo_customer_name'] ?? null,
            'packiyo_shipment_id' => $shipment['packiyo_shipment_id'],
            'packiyo_tracking_id' => $shipment['packiyo_tracking_id'],
            'tracking_number' => $shipment['tracking_number'],
            'tracking_url' => $shipment['tracking_url'],
            'carrier' => $shipment['carrier'],
            'shipped_at' => $this->mysqlDate($shipment['shipped_at']),
            'jtl_delivery_note_id' => $deliveryNoteId,
            'jtl_package_id' => $packageId,
            'status' => $status,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log()->info(
            'fulfillment_sync',
            'Sent tracking ' . $shipment['tracking_number'] . ' to JTL order ' . (string) $mapping['jtl_order_id'] . '.'
        );
    }

    /** @param array<string, mixed> $response */
    private function firstPackiyoData(array $response): ?array
    {
        $data = $response['data'] ?? $response['Data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }

            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, array<string, mixed>>
     */
    private function includedLookup(array $response): array
    {
        $included = $response['included'] ?? $response['Included'] ?? [];
        $lookup = [];

        if (!is_array($included)) {
            return $lookup;
        }

        foreach ($included as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $type = $this->stringValue($resource, ['type', 'Type']);
            $id = $this->stringValue($resource, ['id', 'Id']);

            if ($type !== null && $id !== null) {
                $lookup[$type . ':' . $id] = $resource;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, array<string, mixed>> $included
     * @return array<int, array<string, mixed>>
     */
    private function shipmentResources(array $order, array $included): array
    {
        $relationship = $order['relationships']['shipments']['data'] ?? [];
        $shipments = [];

        if (is_array($relationship)) {
            foreach ($relationship as $resourceId) {
                if (!is_array($resourceId)) {
                    continue;
                }

                $key = ($resourceId['type'] ?? '') . ':' . ($resourceId['id'] ?? '');

                if (isset($included[$key])) {
                    $shipments[] = $included[$key];
                }
            }
        }

        if ($shipments !== []) {
            return $shipments;
        }

        foreach ($included as $key => $resource) {
            if (str_starts_with($key, 'shipments:')) {
                $shipments[] = $resource;
            }
        }

        return $shipments;
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, array<string, mixed>> $included
     * @return array<int, array<string, mixed>>
     */
    private function trackingResources(array $shipment, array $included): array
    {
        $relationship = $shipment['relationships']['shipment_trackings']['data']
            ?? $shipment['relationships']['shipmentTrackings']['data']
            ?? [];
        $trackings = [];

        if (is_array($relationship)) {
            foreach ($relationship as $resourceId) {
                if (!is_array($resourceId)) {
                    continue;
                }

                $key = ($resourceId['type'] ?? '') . ':' . ($resourceId['id'] ?? '');

                if (isset($included[$key])) {
                    $trackings[] = $included[$key];
                }
            }
        }

        return $trackings;
    }

    /** @param array<string, array<string, mixed>> $included */
    private function shipmentCarrier(array $shipment, array $included): string
    {
        $method = $this->relatedResource($shipment, $included, ['shipping_method', 'shippingMethod']);

        if ($method === null) {
            return '';
        }

        $carrier = $this->relatedResource($method, $included, ['shipping_carrier', 'shippingCarrier']);
        $methodAttributes = $this->arrayValue($method, ['attributes', 'Attributes']);
        $carrierAttributes = $carrier !== null ? $this->arrayValue($carrier, ['attributes', 'Attributes']) : [];

        return $this->stringValue($carrierAttributes, ['name', 'Name'])
            ?? $this->stringValue($methodAttributes, ['name', 'Name'])
            ?? '';
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, array<string, mixed>> $included
     * @param array<int, string> $relationshipNames
     * @return array<string, mixed>|null
     */
    private function relatedResource(array $resource, array $included, array $relationshipNames): ?array
    {
        foreach ($relationshipNames as $name) {
            $id = $resource['relationships'][$name]['data'] ?? null;

            if (!is_array($id)) {
                continue;
            }

            $key = ($id['type'] ?? '') . ':' . ($id['id'] ?? '');

            if (isset($included[$key])) {
                return $included[$key];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $notes
     * @return array<string, mixed>|null
     */
    private function firstDeliveryNote(array $notes): ?array
    {
        foreach ($notes as $note) {
            if (is_array($note)) {
                return $note;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $note */
    private function deliveryNoteId(array $note): ?string
    {
        return $this->stringValue($note, [
            'Id',
            'id',
            'DeliveryNoteId',
            'deliveryNoteId',
            'LieferscheinId',
            'lieferscheinId',
            'LieferscheinKey',
        ]);
    }

    /** @param array<string, mixed> $shipment */
    private function shipmentComment(array $shipment): string
    {
        $parts = ['Packiyo'];

        if ($shipment['carrier'] !== '') {
            $parts[] = $shipment['carrier'];
        }

        if ($shipment['tracking_url'] !== '') {
            $parts[] = $shipment['tracking_url'];
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function collection(array $response): array
    {
        foreach (['data', 'Data', 'items', 'Items', 'packages', 'Packages', 'value', 'Value'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values(array_filter($response[$key], 'is_array'));
            }
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return $response === [] ? [] : [$response];
    }

    /** @param array<string, mixed> $data */
    private function arrayValue(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function dateString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
    }

    private function mysqlDate(string $value): string
    {
        return $this->dateString($value) ?? date('Y-m-d H:i:s');
    }

    private function jtlDate(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? $value : date('c', $timestamp);
    }

    private function friendlyException(Throwable $exception): string
    {
        if ($exception instanceof HttpException && $exception->statusCode() === 403) {
            return 'JTL rejected the request with 403. Re-register this app in JTL and approve deliverynotes.read + deliverynotes.write scopes.';
        }

        return $exception->getMessage();
    }

    private function orderModel(): OrderMapping
    {
        return $this->orders ?? new OrderMapping();
    }

    private function fulfillmentModel(): FulfillmentSync
    {
        return $this->fulfillments ?? new FulfillmentSync();
    }

    private function packiyoClient(): PackiyoClient
    {
        return $this->packiyo ?? new PackiyoClient();
    }

    private function jtlClient(): JtlClient
    {
        return $this->jtl ?? new JtlClient();
    }

    private function stateModel(): AppSyncState
    {
        return $this->states ?? new AppSyncState();
    }

    private function log(): Logger
    {
        return $this->logger ?? new Logger();
    }
}

final class JtlUnavailableDuringFulfillmentException extends RuntimeException
{
}
