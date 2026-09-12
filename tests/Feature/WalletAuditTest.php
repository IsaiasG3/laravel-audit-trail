<?php

declare(strict_types=1);

use App\Jobs\StoreAuditLogJob;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditTrailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

it(
    'despacha un job asíncrono de auditoría al crear una wallet',
    function (): void {
        Queue::fake();

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
        ]);

        Queue::assertPushed(
            StoreAuditLogJob::class,
            function (StoreAuditLogJob $job) use ($wallet): bool {
                return $job->change->event->value === 'created'
                    && $job->change->auditableId === $wallet->id;
            }
        );
    }
);

it(
    'captura old_values y new_values al actualizar un registro crítico',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
            'status' => 'active',
        ]);

        $wallet->update([
            'balance' => 250.50,
            'status' => 'frozen',
        ]);

        $log = AuditLog::query()
            ->where(
                'auditable_type',
                Wallet::class
            )
            ->where(
                'auditable_id',
                $wallet->id
            )
            ->where(
                'event',
                'updated'
            )
            ->latest('id')
            ->first();

        expect($log)
            ->not
            ->toBeNull();

        expect($log->old_values)
            ->toMatchArray([
                'balance' => '100.00',
                'status' => 'active',
            ]);

        expect($log->new_values)
            ->toMatchArray([
                'balance' => '250.50',
                'status' => 'frozen',
            ]);
    }
);

it(
    'no genera auditoría si no hubo cambios reales',
    function (): void {
        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
        ]);

        Queue::fake();

        $wallet->update([
            'balance' => 100.00,
        ]);

        Queue::assertNotPushed(
            StoreAuditLogJob::class
        );
    }
);

it(
    'procesa el job de auditoría sin errores y persiste en JSON',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
        ]);

        $wallet->update([
            'balance' => 999.99,
        ]);

        $log = AuditLog::query()
            ->where(
                'auditable_type',
                Wallet::class
            )
            ->where(
                'auditable_id',
                $wallet->id
            )
            ->where(
                'event',
                'updated'
            )
            ->latest('id')
            ->first();

        expect($log)
            ->not
            ->toBeNull();

        expect($log->old_values)
            ->toBeArray();

        expect($log->new_values)
            ->toBeArray();

        expect($log->event)
            ->toBe('updated');
    }
);

it(
    'impide actualizar o eliminar un AuditLog existente',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
        ]);

        $wallet->update([
            'balance' => 50,
        ]);

        $log = AuditLog::query()
            ->where(
                'auditable_type',
                Wallet::class
            )
            ->where(
                'auditable_id',
                $wallet->id
            )
            ->where(
                'event',
                'updated'
            )
            ->latest('id')
            ->first();

        expect($log)
            ->not
            ->toBeNull();

        expect(function () use ($log): void {
            $log->event = 'tampered';
            $log->save();
        })->toThrow(RuntimeException::class);

        expect(function () use ($log): void {
            $log->delete();
        })->toThrow(RuntimeException::class);
    }
);

it(
    'reconstruye el estado histórico de una wallet',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        $baseTime = Carbon::parse(
            '2026-01-01 10:00:00'
        );

        Carbon::setTestNow($baseTime);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        Carbon::setTestNow(
            $baseTime->copy()->addMinute()
        );

        $wallet->update([
            'balance' => 200,
        ]);

        $checkpoint = Carbon::now();

        Carbon::setTestNow(
            $baseTime->copy()->addMinutes(2)
        );

        $wallet->update([
            'balance' => 300,
        ]);

        $state = app(
            AuditTrailService::class
        )->reconstructAt(
            $wallet->fresh(),
            $checkpoint->toIso8601String()
        );

        expect($state)
            ->toHaveKey('balance');

        expect((float) $state['balance'])
            ->toBe(200.00);

        Carbon::setTestNow();
    }
);

it(
    'no persiste auditoría si la transacción principal hace rollback',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $initialAuditCount = AuditLog::query()
            ->where(
                'auditable_type',
                Wallet::class
            )
            ->where(
                'auditable_id',
                $wallet->id
            )
            ->count();

        expect(function () use ($wallet): void {
            DB::transaction(
                function () use ($wallet): void {
                    $wallet->update([
                        'balance' => 999,
                    ]);

                    throw new RuntimeException(
                        'Rollback intencional'
                    );
                }
            );
        })->toThrow(RuntimeException::class);

        expect($wallet->fresh()->balance)
            ->toBe('100.00');

        expect(
            AuditLog::query()
                ->where(
                    'auditable_type',
                    Wallet::class
                )
                ->where(
                    'auditable_id',
                    $wallet->id
                )
                ->count()
        )->toBe($initialAuditCount);
    }
);

it(
    'persiste la auditoría después de confirmar la transacción',
    function (): void {
        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 100,
        ]);

        $initialAuditCount = AuditLog::query()
            ->where(
                'auditable_type',
                Wallet::class
            )
            ->where(
                'auditable_id',
                $wallet->id
            )
            ->count();

        DB::transaction(
            function () use ($wallet): void {
                $wallet->update([
                    'balance' => 999,
                ]);

                /*
                 * El job está marcado como afterCommit(),
                 * por lo que todavía no debe ejecutarse
                 * dentro de esta transacción.
                 */
                expect(
                    AuditLog::query()
                        ->where(
                            'auditable_type',
                            Wallet::class
                        )
                        ->where(
                            'auditable_id',
                            $wallet->id
                        )
                        ->where(
                            'event',
                            'updated'
                        )
                        ->count()
                )->toBe(0);
            }
        );

        /*
         * Después del commit, la cola sync ejecuta
         * el StoreAuditLogJob y la auditoría aparece.
         */
        expect(
            AuditLog::query()
                ->where(
                    'auditable_type',
                    Wallet::class
                )
                ->where(
                    'auditable_id',
                    $wallet->id
                )
                ->count()
        )->toBe($initialAuditCount + 1);

        expect(
            AuditLog::query()
                ->where(
                    'auditable_type',
                    Wallet::class
                )
                ->where(
                    'auditable_id',
                    $wallet->id
                )
                ->where(
                    'event',
                    'updated'
                )
                ->whereJsonContains(
                    'new_values->balance',
                    999
                )
                ->exists()
        )->toBeTrue();
    }
);

it(
    'el controlador dispara la auditoría mediante una petición HTTP real',
    function (): void {
        /** @var TestCase $this */

        config([
            'queue.default' => 'sync',
        ]);

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Wallet $wallet */
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance' => 10,
        ]);

        $this->actingAs(
            $user,
            'sanctum'
        )
            ->putJson(
                "/api/wallets/{$wallet->id}",
                [
                    'balance' => 500,
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'auditable_type' => Wallet::class,
                'auditable_id' => $wallet->id,
                'event' => 'updated',
                'user_id' => $user->id,
            ]
        );
    }
);