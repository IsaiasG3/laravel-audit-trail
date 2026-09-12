<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditChainState;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditChainService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function appendToChain(array $attributes): AuditLog
    {
        return DB::transaction(function () use ($attributes): AuditLog {

            $state = AuditChainState::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                throw new RuntimeException(
                    'El estado de la cadena de auditoría no existe.'
                );
            }

         
            $existing = AuditLog::query()
                ->where('event_uuid', $attributes['event_uuid'])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $previousHash = $state->last_hash;

            $log = new AuditLog();

            $log->event_uuid = $attributes['event_uuid'];
            $log->auditable_type = $attributes['auditable_type'];
            $log->auditable_id = $attributes['auditable_id'];
            $log->user_id = $attributes['user_id'];
            $log->event = $attributes['event'];

            $log->occurred_at = Carbon::parse(
                $attributes['occurred_at']
            );

            $log->old_values = $attributes['old_values'] ?? [];
            $log->new_values = $attributes['new_values'] ?? [];

            $log->ip_address = $attributes['ip_address'];
            $log->user_agent = $attributes['user_agent'];
            $log->url = $attributes['url'];

            $log->previous_hash = $previousHash;

            $log->created_at = now();

            $log->hash = $log->calculatedHash();

            $log->save();

            $state->update([
                'last_hash' => $log->hash,
                'last_audit_log_id' => $log->id,
                'updated_at' => now(),
            ]);

            return $log;
        });
    }

    /**
     * 
     *
     * @return array{
     *     broken_at_id:int,
     *     expected_hash:string,
     *     stored_hash:string
     * }|null
     */
    public function verifyChain(): ?array
    {
        $previousHash = AuditLog::genesisHash();

        foreach (
            AuditLog::query()
                ->orderBy('id')
                ->cursor()
            as $log
        ) {
            if ($log->previous_hash !== $previousHash) {
                return [
                    'broken_at_id' => $log->id,
                    'expected_hash' => $previousHash,
                    'stored_hash' => $log->previous_hash,
                ];
            }

            $expectedHash = $log->calculatedHash();

            if (! hash_equals($expectedHash, $log->hash)) {
                return [
                    'broken_at_id' => $log->id,
                    'expected_hash' => $expectedHash,
                    'stored_hash' => $log->hash,
                ];
            }

            $previousHash = $log->hash;
        }

        return null;
    }

    public function isValid(): bool
    {
        return $this->verifyChain() === null;
    }
}