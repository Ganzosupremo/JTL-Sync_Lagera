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
use App\Support\Setting;
use RuntimeException;
use Throwable;

final class FulfillmentSyncService
{
    /** Wall-clock deadline (microtime(true) seconds) for the current sync() call, or null when unbounded. */
    private ?float $deadline = null;

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

        // Packiyo/JTL calls happen one order at a time over the network, so a large pending queue can easily
        // run past a browser or reverse-proxy request timeout. Rather than trying to process everything in one
        // HTTP request, stop early once the budget is spent: already-synced orders are skipped next time
        // (see OrderMapping::pendingFulfillment()), so the remaining ones simply pick up on the next click or
        // cron tick instead of the whole request failing with a timeout.
        $budgetSeconds = max(1, (int) Setting::get('FULFILLMENT_SYNC_TIME_BUDGET_SECONDS', 20));
        $this->deadline = microtime(true) + $budgetSeconds;

        $this->log()->info(
            'fulfillment_sync',
            'Fulfillment sync started' . ($packiyoCustomerId !== null ? ' for Packiyo customer ' . $packiyoCustomerId : '') . '.'
        );
        $jtlUnavailableMessage = null;
        $stoppedForTimeBudget = false;

        foreach ($this->orderModel()->pendingFulfillment($limit, $packiyoCustomerId) as $mapping) {
            if ($summary['checked'] > 0 && $this->timeBudgetExceeded()) {
                $stoppedForTimeBudget = true;
                $this->log()->info(
                    'fulfillment_sync',
                    'Fulfillment sync paused after ' . $budgetSeconds . 's to avoid a request timeout; '
                    . 'the remaining pending orders will be picked up on the next run.'
                );
                break;
            }

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
                        $summary['synced']++;
                    } catch (Throwable $exception) {
                        if ($this->jtlClient()->isReachabilityException($exception)) {
                            throw new JtlUnavailableDuringFulfillmentException(
                                $this->jtlClient()->friendlyReachabilityMessage($exception),
                                0,
                                $exception
                            );
                        }

                        // A non-reachability failure (e.g. no matching JTL delivery note yet) used to only be
                        // logged and counted in this run's ephemeral summary, then disappear: nothing was saved,
                        // so the order never showed up anywhere in the dashboard even though it kept silently
                        // failing on every run. Persisting it here as a `failed` fulfillment_syncs row makes it
                        // visible (and filterable by customer) in the Fulfillment tab, while still letting it be
                        // retried automatically next run: exists()/pendingFulfillment() only treat `synced` and
                        // `already_present` as done, not `failed`.
                        $summary['failed']++;
                        $message = $this->friendlyException($exception);
                        $this->log()->error(
                            'fulfillment_sync',
                            'Unable to sync fulfillment for JTL order '
                            . (string) ($mapping['jtl_order_number'] ?: $mapping['jtl_order_id'])
                            . ': ' . $message
                        );
                        $this->saveFailedAttempt($mapping, $shipment, $message);
                    }
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
            )
            . ($stoppedForTimeBudget
                ? sprintf(
                    ' Se detuvo tras %ds para evitar un timeout; quedan mas ordenes pendientes y se revisaran en la siguiente corrida (cron o un nuevo click).',
                    $budgetSeconds
                )
                : '');

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
        $deliveryNote = $this->deliveryNoteForOrder($jtlOrderId, $jtlOrderNumber);

        if ($deliveryNote === null) {
            $message = 'No JTL delivery note was found for order ' . ($jtlOrderNumber ?: $jtlOrderId) . '.';

            if ($this->jtlClient()->autoCreateDeliveryNoteEnabled()) {
                $message .= ' Auto-create workflow was triggered, but no delivery note appeared within the configured retries.';
            }

            throw new RuntimeException($message);
        }

        $deliveryNoteId = $this->deliveryNoteId($deliveryNote);

        if ($deliveryNoteId === null) {
            throw new RuntimeException('JTL delivery note has no usable id.');
        }

        $orderLabel = $jtlOrderNumber !== null && $jtlOrderNumber !== '' ? $jtlOrderNumber : $jtlOrderId;

        foreach ($this->jtlClient()->getDeliveryNotePackages($deliveryNoteId) as $package) {
            if ($this->stringValue($package, ['TrackingID', 'trackingID', 'trackingId', 'tracking_number']) === $shipment['tracking_number']) {
                $shippedError = $this->markDeliveryNoteShipped($deliveryNoteId, $orderLabel);
                $this->saveFulfillment(
                    $mapping,
                    $shipment,
                    $deliveryNoteId,
                    $this->stringValue($package, ['Id', 'id', 'PackageId', 'packageId']),
                    $shippedError === null ? 'already_present' : 'shipped_pending',
                    $shippedError
                );
                return;
            }
        }

        $response = $this->jtlClient()->createDeliveryNotePackages($deliveryNoteId, [[
            'ShippedDate' => $this->jtlDate($shipment['shipped_at']),
            'TrackingID' => $shipment['tracking_number'],
            'Comment' => $this->shipmentComment($shipment),
        ]]);

        $shippedError = $this->markDeliveryNoteShipped($deliveryNoteId, $orderLabel);

        $createdPackage = $this->firstDeliveryNote($this->collection($response));
        $this->saveFulfillment(
            $mapping,
            $shipment,
            $deliveryNoteId,
            $createdPackage !== null ? $this->stringValue($createdPackage, ['Id', 'id', 'PackageId', 'packageId']) : null,
            $shippedError === null ? 'synced' : 'shipped_pending',
            $shippedError
        );
    }

    /**
     * JTL does not accept a shipping-method/carrier field on delivery note packages (it is a read-only value JTL
     * derives itself from the order). What BOL/marketplace fulfillment actually depends on is JTL's own delivery
     * note "Shipped" workflow event, which this triggers explicitly so JTL-Worker reports the order as fulfilled.
     *
     * A non-reachability failure here (e.g. missing scope) does not throw: the tracking package itself was
     * already sent to JTL successfully, so we still record that. Instead the fulfillment row is saved with
     * status `shipped_pending` and the error message (see sendShipmentToJtl callers). FulfillmentSync::exists()
     * does not treat `shipped_pending` as done, so the next sync run naturally retries this same shipment's
     * Shipped event until it succeeds - without resending the tracking number.
     *
     * @return string|null null if the Shipped event was triggered (or the feature is intentionally disabled),
     *                      otherwise the friendly error message to persist and retry on the next sync run.
     */
    private function markDeliveryNoteShipped(string $deliveryNoteId, string $orderLabel): ?string
    {
        if (!$this->jtlClient()->markDeliveryNoteShippedEnabled()) {
            return null;
        }

        try {
            $this->jtlClient()->triggerDeliveryNoteShippedWorkflowEvent($deliveryNoteId);
            $this->log()->info(
                'fulfillment_sync',
                'JTL delivery note ' . $deliveryNoteId . ' de la orden ' . $orderLabel
                . ' fue marcado como Shipped para que el marketplace lo vea como fulfilled.'
            );

            return null;
        } catch (Throwable $exception) {
            if ($this->jtlClient()->isReachabilityException($exception)) {
                throw $exception;
            }

            $message = $this->friendlyException($exception);
            $this->log()->error(
                'fulfillment_sync',
                'No se pudo marcar como Shipped el delivery note ' . $deliveryNoteId . ' de la orden ' . $orderLabel
                . ' (el tracking si se envio a JTL, se reintentara marcar como Shipped en la proxima corrida): ' . $message
            );

            return $message;
        }
    }

    private function deliveryNoteForOrder(string $jtlOrderId, ?string $jtlOrderNumber): ?array
    {
        $deliveryNote = $this->findDeliveryNote($jtlOrderId, $jtlOrderNumber);

        if ($deliveryNote !== null || !$this->jtlClient()->autoCreateDeliveryNoteEnabled()) {
            return $deliveryNote;
        }

        $workflowEventId = $this->jtlClient()->deliveryNoteWorkflowEventId();

        if ($workflowEventId === null) {
            throw new RuntimeException(
                'JTL auto-create delivery note is enabled, but JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID is empty.'
            );
        }

        $orderLabel = $jtlOrderNumber !== null && $jtlOrderNumber !== '' ? $jtlOrderNumber : $jtlOrderId;
        $this->log()->info(
            'fulfillment_sync',
            'No JTL delivery note found for order ' . $orderLabel
            . '; triggering sales order workflow event ' . $workflowEventId . '.'
        );

        $this->jtlClient()->triggerSalesOrderWorkflowEvent($jtlOrderId, $workflowEventId);

        $attempts = $this->jtlClient()->deliveryNoteCreateRetries();
        $delaySeconds = $this->jtlClient()->deliveryNoteCreateRetryDelaySeconds();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1 && $delaySeconds > 0) {
                if ($this->timeBudgetExceeded()) {
                    // Don't let one order's retry-with-sleep loop burn the whole request's time budget;
                    // give up for now so other pending orders still get a chance this run, and retry this
                    // one (no fulfillment row was saved) on the next sync.
                    break;
                }

                sleep($delaySeconds);
            }

            $deliveryNote = $this->findDeliveryNote($jtlOrderId, $jtlOrderNumber);

            if ($deliveryNote !== null) {
                $this->log()->info(
                    'fulfillment_sync',
                    'JTL delivery note found for order ' . $orderLabel
                    . ' after workflow trigger attempt ' . $attempt . '.'
                );

                return $deliveryNote;
            }
        }

        return null;
    }

    private function findDeliveryNote(string $jtlOrderId, ?string $jtlOrderNumber): ?array
    {
        return $this->firstDeliveryNote($this->jtlClient()->getDeliveryNotes($jtlOrderId, $jtlOrderNumber));
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
        string $status,
        ?string $lastError = null
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
            'last_error' => $lastError,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log()->info(
            'fulfillment_sync',
            'Sent tracking ' . $shipment['tracking_number'] . ' to JTL order ' . (string) $mapping['jtl_order_id'] . '.'
        );
    }

    /**
     * Records a failed attempt to send an already-known shipment/tracking to JTL (e.g. no matching delivery
     * note yet) so it shows up in the Fulfillment tab instead of silently vanishing. Uses the same
     * (jtl_order_id, tracking_number) upsert key as saveFulfillment(), so a later successful retry simply
     * overwrites this row with status `synced`/`already_present`.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, string> $shipment
     */
    private function saveFailedAttempt(array $mapping, array $shipment, string $lastError): void
    {
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
            'jtl_delivery_note_id' => null,
            'jtl_package_id' => null,
            'status' => 'failed',
            'last_error' => $lastError,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);
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
            return 'JTL rejected the request with 403. Re-register this app in JTL and approve deliverynotes.read, deliverynotes.write, deliverynote.triggerdeliverynoteworkflow, salesorders.write, salesorder.triggersalesorderworkflowevent and salesorder.triggersalesorderworkflow scopes.';
        }

        if (
            $exception instanceof HttpException
            && $exception->statusCode() === 404
            && str_contains($exception->url(), 'workflowEvents')
            && str_contains($exception->getMessage(), 'EntityNotFound')
        ) {
            // Unlike delivery notes (fixed 1=Created/2=Deleted/3=Shipped enum), sales order workflow event IDs
            // are custom automation rules configured per JTL-Wawi installation - there is no universal "1".
            // This error means JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID does not match any real event on this install.
            return $exception->getMessage()
                . ' Tip: JTL_DELIVERY_NOTE_WORKFLOW_EVENT_ID no coincide con ningun workflow event de Auftraege '
                . 'real en esta instalacion de JTL-Wawi (a diferencia de los delivery notes, estos IDs son '
                . 'especificos de cada instalacion, no un enum fijo). En Ajustes, usa el boton "Leer workflow '
                . 'events de JTL" para ver los IDs y nombres reales, y corrige el valor con el ID del evento manual '
                . 'que crea el Lieferschein. Si ese evento todavia no existe, hay que crearlo primero en el '
                . 'Workflow-Designer de JTL-Wawi.';
        }

        return $exception->getMessage();
    }

    private function timeBudgetExceeded(): bool
    {
        return $this->deadline !== null && microtime(true) >= $this->deadline;
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
