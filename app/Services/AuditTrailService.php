<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AuditTrailService
{
    public function historyFor(Model $model): Collection
    {
        return $model->auditLogs()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get([
                'id',
                'event_uuid',
                'event',
                'old_values',
                'new_values',
                'user_id',
                'occurred_at',
                'created_at',
                'hash',
            ])
            ->map(static fn ($log): array => [
                'id' => $log->id,
                'event_uuid' => $log->event_uuid,
                'event' => $log->event,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'user_id' => $log->user_id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'persisted_at' => $log->created_at->toIso8601String(),
                'hash' => $log->hash,
            ]);
    }

    public function reconstructAt(
        Model $model,
        string $datetime
    ): array {
        $target = Carbon::parse($datetime);

        $logs = $model->auditLogs()
            ->where(
                'occurred_at',
                '<=',
                $target
            )
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $state = [];

        foreach ($logs as $log) {
            $state = match ($log->event) {
                'created' => $log->new_values ?? [],

                'updated' => array_merge(
                    $state,
                    $log->new_values ?? []
                ),

                'deleted' => [],

                'restored' => array_merge(
                    $state,
                    $log->new_values ?? []
                ),

                default => $state,
            };
        }

        return $state;
    }

    public function diffBetween(
        Model $model,
        string $fromDatetime,
        string $toDatetime
    ): array {
        $from = $this->reconstructAt(
            $model,
            $fromDatetime
        );

        $to = $this->reconstructAt(
            $model,
            $toDatetime
        );

        $keys = array_unique(
            array_merge(
                array_keys($from),
                array_keys($to)
            )
        );

        $diff = [];

        foreach ($keys as $key) {
            $before = $from[$key] ?? null;
            $after = $to[$key] ?? null;

            if ($before !== $after) {
                $diff[$key] = [
                    'from' => $before,
                    'to' => $after,
                ];
            }
        }

        return [
            'from' => $fromDatetime,
            'to' => $toDatetime,
            'diff' => $diff,
        ];
    }
}