<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditChainService;
use Illuminate\Console\Command;

final class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain';

    protected $description =
        'Verifica la integridad criptográfica de la cadena de auditoría';

    public function handle(AuditChainService $chain): int
    {
        $this->info(
            'Verificando integridad de la cadena de auditoría...'
        );

        $breakPoint = $chain->verifyChain();

        if ($breakPoint === null) {
            $this->info(
                'La cadena de auditoría es íntegra.'
            );

            return self::SUCCESS;
        }

        $this->error(
            "Se detectó una inconsistencia en el AuditLog ID {$breakPoint['broken_at_id']}."
        );

        $this->line(
            "Hash esperado: {$breakPoint['expected_hash']}"
        );

        $this->line(
            "Hash almacenado: {$breakPoint['stored_hash']}"
        );

        return self::FAILURE;
    }
}