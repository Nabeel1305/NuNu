<?php

namespace App\Support;

/** Validation rules for a bank account number and bank code, from config('platform.accounts'). */
class AccountRules
{
    /** @return list<string> */
    public static function number(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:/' . config('platform.accounts.number_pattern') . '/'];
    }

    /** @return list<string> */
    public static function bankCode(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:/' . config('platform.accounts.bank_code_pattern') . '/'];
    }

    /** @return array<string,string> messages to merge into a validator */
    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix . 'account_number.regex' => 'The account number is not in the expected format.',
            $prefix . 'bank_code.regex' => 'The bank code is not in the expected format.',
        ];
    }
}
