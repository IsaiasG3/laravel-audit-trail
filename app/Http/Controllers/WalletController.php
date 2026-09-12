<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWalletBalanceRequest;
use App\Models\Wallet;
use App\Services\AuditTrailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class WalletController extends Controller
{
    public function __construct(
        private readonly AuditTrailService $auditTrail
    ) {
    }

    public function update(
        UpdateWalletBalanceRequest $request,
        Wallet $wallet
    ): JsonResponse {
        Gate::authorize('update', $wallet);

        $wallet->update($request->validated());

        return response()->json([
            'data' => $wallet->fresh(),
        ]);
    }

    public function history(Wallet $wallet): JsonResponse
    {
        Gate::authorize('viewHistory', $wallet);

        return response()->json([
            'data' => $this->auditTrail->historyFor($wallet),
        ]);
    }

    public function snapshotAt(
        Wallet $wallet,
        string $datetime
    ): JsonResponse {
        Gate::authorize('viewSnapshot', $wallet);

        return response()->json([
            'data' => $this->auditTrail->reconstructAt(
                $wallet,
                $datetime
            ),
        ]);
    }

    public function diff(
        Request $request,
        Wallet $wallet
    ): JsonResponse {
        Gate::authorize('viewDiff', $wallet);

        $validated = $request->validate([
            'from' => [
                'required',
                'date',
            ],
            'to' => [
                'required',
                'date',
                'after_or_equal:from',
            ],
        ]);

        return response()->json([
            'data' => $this->auditTrail->diffBetween(
                $wallet,
                $validated['from'],
                $validated['to']
            ),
        ]);
    }
}