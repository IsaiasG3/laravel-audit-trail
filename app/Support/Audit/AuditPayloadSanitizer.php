<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

final class AuditPayloadSanitizer
{
    /**
     * Campos que nunca deben formar parte del audit trail.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'secret',
        'client_secret',
        'private_key',
        'authorization',
        'cookie',
    ];

    /**
     * Sanitiza todos los atributos actuales del modelo.
     *
     * @return array<string, mixed>
     */
    public function sanitizeModel(Model $model): array
    {
        $excluded = $this->excludedFields($model);

        return $this->sanitizeArray(
            $model->getAttributes(),
            $excluded
        );
    }

    /**
     * Sanitiza únicamente las claves indicadas usando
     * los valores actuales del modelo.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function sanitizeKeys(
        Model $model,
        array $keys
    ): array {
        return $this->sanitizeValues(
            $model->getAttributes(),
            $model,
            $keys
        );
    }

    /**
     * Sanitiza únicamente las claves indicadas usando
     * los valores originales del modelo.
     *
     * Este método es el utilizado para old_values.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function sanitizeOriginalKeys(
        Model $model,
        array $keys
    ): array {
        return $this->sanitizeValues(
            $model->getOriginal(),
            $model,
            $keys
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function sanitizeValues(
        array $source,
        Model $model,
        array $keys
    ): array {
        $excluded = $this->excludedFields($model);

        $filtered = [];

        foreach ($keys as $key) {
            if (
                array_key_exists($key, $source)
                && ! $this->isSensitiveField($key, $excluded)
            ) {
                $filtered[$key] = $source[$key];
            }
        }

        return $this->sanitizeArray(
            $filtered,
            $excluded
        );
    }

    /**
     * @return array<int, string>
     */
    private function excludedFields(Model $model): array
    {
        return array_merge(
            self::SENSITIVE_FIELDS,
            method_exists($model, 'auditExcluded')
                ? $model->auditExcluded()
                : []
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $excluded
     * @return array<string, mixed>
     */
    private function sanitizeArray(
        array $values,
        array $excluded
    ): array {
        $result = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveField($key, $excluded)) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray(
                    $value,
                    $excluded
                );

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int, string> $excluded
     */
    private function isSensitiveField(
        string $field,
        array $excluded
    ): bool {
        $normalized = strtolower($field);

        foreach ($excluded as $excludedField) {
            if ($normalized === strtolower($excludedField)) {
                return true;
            }
        }

        return (bool) preg_match(
            '/(^|_)(password|token|secret|private_key|authorization|cookie)(_|$)/i',
            $normalized
        );
    }
}