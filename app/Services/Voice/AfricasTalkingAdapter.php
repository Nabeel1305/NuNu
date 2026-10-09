<?php

namespace App\Services\Voice;

use Illuminate\Http\Request;

/**
 * Translates Africa's Talking voice callbacks to and from the platform's own
 * terms. Other telephony providers get an adapter with the same three methods.
 */
class AfricasTalkingAdapter
{
    public const PROVIDER = 'africastalking';

    public function parse(Request $request): VoiceCall
    {
        $digits = $request->input('dtmfDigits');

        return new VoiceCall(
            destination: $this->normalizeNumber((string) $request->input('destinationNumber', '')),
            caller: (string) $request->input('callerNumber', ''),
            digits: filled($digits) ? (string) $digits : null,
            sessionId: (string) $request->input('sessionId', ''),
        );
    }

    /** Ask the caller to key in their code; the callback comes back to the same URL. */
    public function promptForDigits(string $callbackUrl): string
    {
        $url = htmlspecialchars($callbackUrl, ENT_XML1 | ENT_QUOTES);

        return '<?xml version="1.0" encoding="UTF-8"?><Response>'
            . '<GetDigits timeout="30" finishOnKey="#" callbackUrl="' . $url . '">'
            . '<Say voice="woman">Please enter your payment code, then press hash.</Say>'
            . '</GetDigits></Response>';
    }

    /** The same words whatever happened: the caller never learns why a code failed. */
    public function acknowledge(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'
            . '<Say voice="woman">Thank you. You will receive a confirmation shortly.</Say></Response>';
    }

    public function normalizeNumber(string $number): string
    {
        return preg_replace('/[^\d+]/', '', $number) ?? '';
    }
}
