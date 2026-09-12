<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /**
     * Los campos criptográficos NO deben ser mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'event_uuid',
        'auditable_type',
        'auditable_id',
        'user_id',
        'event',
        'occurred_at',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'previous_hash',
        'hash',
        'created_at',
    ];

    protected $casts = [
        'auditable_id' => 'integer',
        'user_id' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Los registros de AuditLog son inmutables y no pueden actualizarse.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Los registros de AuditLog son inmutables y no pueden eliminarse.'
            );
        });
    }

    public static function genesisHash(): string
    {
        return hash(
            'sha256',
            'audit-genesis-' . config('app.name')
        );
    }

    public function hashPayload(): string
    {
        $payload = [
            'event_uuid' => $this->event_uuid,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'user_id' => $this->user_id,
            'event' => $this->event,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'old_values' => $this->old_values ?? [],
            'new_values' => $this->new_values ?? [],
            'previous_hash' => $this->previous_hash,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    public function calculatedHash(): string
    {
        return hash(
            'sha256',
            $this->previous_hash . $this->hashPayload()
        );
    }
}