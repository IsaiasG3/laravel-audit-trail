<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ExportAuditTrail extends Command
{
    protected $signature = 'audit:export
        {auditable_type : Clase del modelo auditable}
        {auditable_id : ID del registro}
        {--format=json : Formato de salida: json|csv}';

    protected $description =
        'Exporta el historial de auditoría de un recurso';

    public function handle(): int
    {
        $type = (string) $this->argument('auditable_type');
        $id = (string) $this->argument('auditable_id');
        $format = strtolower(
            (string) $this->option('format')
        );

        if (! in_array($format, ['json', 'csv'], true)) {
            $this->error(
                'El formato debe ser json o csv.'
            );

            return self::INVALID;
        }

        if (
            ! class_exists($type)
            || ! is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)
        ) {
            $this->error(
                'El auditable_type no corresponde a un modelo Eloquent válido.'
            );

            return self::INVALID;
        }

        $logs = AuditLog::query()
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            $this->warn(
                'No se encontraron registros de auditoría.'
            );

            return self::FAILURE;
        }

        $content = match ($format) {
            'json' => $this->toJson(
                $logs,
                $type,
                $id
            ),

            'csv' => $this->toCsv($logs),

            default => throw new RuntimeException(
                'Formato de exportación inválido.'
            ),
        };

        $signature = hash_hmac(
            'sha256',
            $content,
            config('app.key')
        );

        $basename = sprintf(
            'audit-exports/%s_%s_%s',
            class_basename($type),
            $id,
            now()->format('Ymd_His')
        );

        $filename = "{$basename}.{$format}";

        Storage::disk('local')->put(
            $filename,
            $content
        );

        Storage::disk('local')->put(
            "{$filename}.sig",
            $signature
        );

        $this->info(
            "Exportado: storage/app/{$filename}"
        );

        $this->info(
            "Firma HMAC: storage/app/{$filename}.sig"
        );

        return self::SUCCESS;
    }

    private function toJson(
        $logs,
        string $type,
        string $id
    ): string {
        return json_encode(
            [
                'auditable_type' => $type,
                'auditable_id' => $id,
                'exported_at' => now()->toIso8601String(),
                'record_count' => $logs->count(),
                'records' => $logs->toArray(),
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    private function toCsv($logs): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException(
                'No se pudo crear el stream temporal.'
            );
        }

        fputcsv($handle, [
            'id',
            'event_uuid',
            'event',
            'user_id',
            'occurred_at',
            'old_values',
            'new_values',
            'previous_hash',
            'hash',
        ]);

        foreach ($logs as $log) {
            fputcsv($handle, [
                $log->id,
                $log->event_uuid,
                $log->event,
                $log->user_id,
                $log->occurred_at->toIso8601String(),
                json_encode(
                    $log->old_values,
                    JSON_UNESCAPED_UNICODE
                ),
                json_encode(
                    $log->new_values,
                    JSON_UNESCAPED_UNICODE
                ),
                $log->previous_hash,
                $log->hash,
            ]);
        }

        rewind($handle);

        $csv = stream_get_contents($handle);

        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException(
                'No se pudo leer el CSV generado.'
            );
        }

        return $csv;
    }
}