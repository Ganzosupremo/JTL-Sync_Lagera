<?php

declare(strict_types=1);

namespace App\Support;

final class JtlScopeList
{
    /** @var array<int, string> */
    private const DEFAULT_MANDATORY = [
        'salesorders.read',
        'salesorders.write',
        'salesorder.querysalesorderworkflowevents',
        'salesorder.triggersalesorderworkflow',
        'salesorder.triggersalesorderworkflowevent',
        'items.read',
        'items.write',
        'item.queryitems',
        'item.createitem',
        'item.updateitem',
        'inventories.read',
        'inventories.write',
        'stock.querystocksperitem',
        'stock.stockadjustment',
        'deliverynotes.read',
        'deliverynotes.write',
        'worker.getworkersyncs',
        'worker.getworkerstatus',
        'worker.synccontrol',
        'system.worker.read',
        'system.worker.write',
    ];

    /** @var array<int, string> */
    private const UNSUPPORTED = [
        'worker.putworkersyncaction',
    ];

    /** @return array<int, string> */
    public static function defaultMandatory(): array
    {
        return self::DEFAULT_MANDATORY;
    }

    public static function defaultMandatoryString(): string
    {
        return implode(',', self::DEFAULT_MANDATORY);
    }

    /** @return array<int, string> */
    public static function mandatoryFromConfigured(string $configured): array
    {
        return array_values(array_unique(array_merge(
            self::sanitize($configured),
            self::DEFAULT_MANDATORY
        )));
    }

    public static function sanitizeString(string $scopes): string
    {
        return implode(',', self::sanitize($scopes));
    }

    /** @return array<int, string> */
    private static function sanitize(string $scopes): array
    {
        $unsupported = array_flip(self::UNSUPPORTED);
        $result = [];

        foreach (preg_split('/[\s,]+/', $scopes) ?: [] as $scope) {
            $scope = trim($scope);

            if ($scope === '' || isset($unsupported[strtolower($scope)])) {
                continue;
            }

            $result[] = $scope;
        }

        return array_values(array_unique($result));
    }
}
