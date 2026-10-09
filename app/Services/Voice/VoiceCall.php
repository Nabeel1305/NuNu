<?php

namespace App\Services\Voice;

final class VoiceCall
{
    public function __construct(
        public readonly string $destination,
        public readonly string $caller,
        public readonly ?string $digits,
        public readonly string $sessionId,
    ) {
    }
}
