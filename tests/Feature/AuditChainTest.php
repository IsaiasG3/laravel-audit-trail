<?php

use App\DTOs\AuditChange;
use App\Enums\AuditEvent;
use App\Jobs\StoreAuditLogJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditChainService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('encadena correctamente eventos consecutivos', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    /** @var Wallet $wallet */
    $wallet = Wallet::factory()->create([
        'user_id' => $user->id,
        'balance' => 100,
    ]);

    $wallet->update([
        'balance' => 200,
    ]);

    $wallet->update([
        'balance' => 300,
    ]);

    $allLogs = AuditLog::query()
        ->orderBy('id')
        ->get();

    expect($allLogs)->not->toBeEmpty();

    /*
     * La cadena es GLOBAL, por lo que el primer log de Wallet
     * no necesariamente parte del genesis.
     *
     * Lo importante es que cada registro apunte exactamente
     * al hash del registro inmediatamente anterior.
     */
    expect($allLogs->first()->previous_hash)
        ->toBe(AuditLog::genesisHash());

    for ($index = 1; $index < $allLogs->count(); $index++) {
        $current = $allLogs[$index];
        $previous = $allLogs[$index - 1];

        expect($current->previous_hash)
            ->toBe($previous->hash);
    }

    $walletLogs = AuditLog::query()
        ->where(
            'auditable_type',
            Wallet::class
        )
        ->where(
            'auditable_id',
            $wallet->id
        )
        ->orderBy('id')
        ->get();

    expect($walletLogs)
        ->toHaveCount(3);

    expect($walletLogs[0]->event)
        ->toBe('created');

    expect($walletLogs[1]->event)
        ->toBe('updated');

    expect($walletLogs[2]->event)
        ->toBe('updated');

    foreach ($walletLogs as $walletLog) {
        expect($walletLog->previous_hash)
            ->not
            ->toBe('');
    }
});

it('detecta manipulación de un snapshot', function (): void {
    $wallet = Wallet::factory()->create([
        'balance' => 100,
    ]);

    $wallet->update([
        'balance' => 200,
    ]);

    $log = AuditLog::query()
        ->where('auditable_type', Wallet::class)
        ->where('auditable_id', $wallet->id)
        ->firstOrFail();

    DB::table('audit_logs')
        ->where('id', $log->id)
        ->update([
            'new_values' => json_encode([
                'balance' => '999999.00',
            ]),
        ]);

    $result = app(AuditChainService::class)
        ->verifyChain();

    expect($result)
        ->not
        ->toBeNull()
        ->and($result['broken_at_id'])
        ->toBe($log->id);
});

it('confirma que la cadena es íntegra', function (): void {
    $wallet = Wallet::factory()->create([
        'balance' => 100,
    ]);

    $wallet->update([
        'balance' => 200,
    ]);

    expect(
        app(AuditChainService::class)->verifyChain()
    )->toBeNull();
});

it('no duplica un evento cuando el job se ejecuta dos veces', function (): void {
    $wallet = Wallet::factory()->create([
        'balance' => 100,
    ]);

    $change = AuditChange::forUpdated(
        $wallet,
        null
    );

    $job = new StoreAuditLogJob($change);

    $job->handle(
        app(AuditChainService::class)
    );

    $job->handle(
        app(AuditChainService::class)
    );

    expect(
        AuditLog::query()
            ->where(
                'event_uuid',
                $change->eventUuid
            )
            ->count()
    )->toBe(1);
});

it('nunca registra campos sensibles de User', function (): void {
    $user = User::factory()->create([
        'name' => 'Original',
    ]);

    $user->update([
        'name' => 'Editado',
        'password' => bcrypt(
            'nueva-clave-123'
        ),
    ]);

    $log = AuditLog::query()
        ->where(
            'auditable_type',
            User::class
        )
        ->where(
            'event',
            AuditEvent::UPDATED->value
        )
        ->latest('id')
        ->firstOrFail();

    expect($log->new_values)
        ->toHaveKey('name')
        ->not
        ->toHaveKey('password')
        ->and($log->old_values)
        ->not
        ->toHaveKey('password')
        ->and($log->new_values)
        ->not
        ->toHaveKey('remember_token');
});

it('despacha la auditoría en la cola correcta', function (): void {
    Queue::fake();

    $wallet = Wallet::factory()->create();

    Queue::assertPushedOn(
        'audits',
        StoreAuditLogJob::class
    );

    Queue::assertPushed(
        StoreAuditLogJob::class,
        function (StoreAuditLogJob $job) use ($wallet): bool {
            return $job->change->event
                === AuditEvent::CREATED
                && $job->change->auditableId
                === $wallet->id;
        }
    );
});