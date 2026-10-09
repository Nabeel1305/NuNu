<?php

namespace App\Services\Codes;

class CodeGenerator
{
    public function __construct(private readonly ?int $length = null)
    {
    }

    /** A random numeric code, optionally preceded by a fixed tenant prefix. */
    public function generate(string $prefix = ''): string
    {
        $length = $this->length ?? (int) config('platform.code_length', 12);
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= random_int(0, 9);
        }

        return $prefix . $digits;
    }

    /** Keep digits only: DTMF capture can include separators or the # terminator. */
    public function normalize(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), (string) config('platform.code_pepper'));
    }
}
