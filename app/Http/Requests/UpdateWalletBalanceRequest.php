<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWalletBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $wallet = $this->route('wallet');

        return $wallet instanceof Wallet
            && $this->user()?->can('update', $wallet);
    }

    public function rules(): array
    {
        return [
            'balance' => [
                'required',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],

            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    'active',
                    'frozen',
                    'closed',
                ]),
            ],
        ];
    }
}