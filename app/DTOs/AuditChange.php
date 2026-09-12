<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditPayloadSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class AuditChange
{
    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    public function __construct(
        public string $eventUuid,
        public AuditEvent $event,
        public string $auditableType,
        public int|string $auditableId,
        public ?int $userId,
        public array $oldValues,
        public array $newValues,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $url,
        public string $occurredAt,
    ) {}

    public static function forCreated(
        Model $model,
        ?int $userId,
        array $requestContext = []
    ): self {
        $sanitizer = app(AuditPayloadSanitizer::class);

        return new self(
            eventUuid: (string) Str::uuid(),
            event: AuditEvent::CREATED,
            auditableType: $model::class,
            auditableId: $model->getKey(),
            userId: $userId,
            oldValues: [],
            newValues: $sanitizer->sanitizeModel($model),
            ipAddress: $requestContext['ip'] ?? null,
            userAgent: self::normalizeUserAgent($requestContext['agent'] ?? null),
            url: self::normalizeUrl($requestContext['url'] ?? null),
            occurredAt: now()->toIso8601String(),
        );
    }

 public static function forUpdated(
    Model $model,
    ?int $userId,
    array $requestContext = []
): self {
    $sanitizer = app(AuditPayloadSanitizer::class);

    $changedKeys = array_keys(
        $model->getChanges()
    );

    return new self(
        eventUuid: (string) Str::uuid(),
        event: AuditEvent::UPDATED,
        auditableType: $model::class,
        auditableId: $model->getKey(),
        userId: $userId,

        oldValues: $sanitizer->sanitizeOriginalKeys(
            $model,
            $changedKeys
        ),

        newValues: $sanitizer->sanitizeKeys(
            $model,
            $changedKeys
        ),

        ipAddress: $requestContext['ip'] ?? null,
        userAgent: self::normalizeUserAgent(
            $requestContext['agent'] ?? null
        ),
        url: self::normalizeUrl(
            $requestContext['url'] ?? null
        ),
        occurredAt: now()->toIso8601String(),
    );
}

    public static function forDeleted(
        Model $model,
        ?int $userId,
        array $requestContext = []
    ): self {
        $sanitizer = app(AuditPayloadSanitizer::class);

        return new self(
            eventUuid: (string) Str::uuid(),
            event: AuditEvent::DELETED,
            auditableType: $model::class,
            auditableId: $model->getKey(),
            userId: $userId,
            oldValues: $sanitizer->sanitizeModel($model),
            newValues: [],
            ipAddress: $requestContext['ip'] ?? null,
            userAgent: self::normalizeUserAgent($requestContext['agent'] ?? null),
            url: self::normalizeUrl($requestContext['url'] ?? null),
            occurredAt: now()->toIso8601String(),
        );
    }



    private static function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 2048);
    }

    private static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        /*
         * Nunca almacenamos query parameters.
         */
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = isset($parts['scheme'])
            ? $parts['scheme'] . '://'
            : '';

        $host = $parts['host'] ?? '';

        $port = isset($parts['port'])
            ? ':' . $parts['port']
            : '';

        $path = $parts['path'] ?? '';

        return mb_substr(
            $scheme . $host . $port . $path,
            0,
            2048
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_uuid' => $this->eventUuid,
            'auditable_type' => $this->auditableType,
            'auditable_id' => $this->auditableId,
            'user_id' => $this->userId,
            'event' => $this->event->value,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'url' => $this->url,
            'occurred_at' => $this->occurredAt,
        ];
    }
}