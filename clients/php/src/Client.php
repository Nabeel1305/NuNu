<?php

namespace OfflinePayments;

/**
 * Minimal client for the Offline Payment Platform API. Needs PHP 8.1+ and the
 * curl extension (or pass your own $transport).
 */
class Client
{
    /** @var callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private $transport;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curl'];
    }

    /** The account number and bank code are where the payer's funds are held and debited from. */
    public function upsertSubscriber(string $reference, string $accountNumber, string $bankCode, ?string $phone = null): array
    {
        return $this->request('PUT', '/subscribers/' . rawurlencode($reference), ['account_number' => $accountNumber, 'bank_code' => $bankCode, 'phone' => $phone]);
    }

    /** The account number and bank code are where captured funds are credited; $accountReference is your own optional label. */
    public function upsertMerchant(string $reference, string $name, string $accountNumber, string $bankCode, ?string $accountReference = null): array
    {
        return $this->request('PUT', '/merchants/' . rawurlencode($reference), ['name' => $name, 'account_number' => $accountNumber, 'bank_code' => $bankCode, 'account_reference' => $accountReference]);
    }

    /** The returned `secret` is shown once; store it. */
    public function createWebhookEndpoint(string $url, ?array $events = null): array
    {
        return $this->request('POST', '/webhook-endpoints', array_filter(['url' => $url, 'events' => $events], fn ($v) => $v !== null));
    }

    /**
     * Issue a code. Pass the same $idempotencyKey when retrying the same
     * payment so a timeout can never produce a second code. The returned
     * `code` is shown once.
     */
    public function issueCode(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/codes', $params, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function getCode(string $id): array
    {
        return $this->request('GET', '/codes/' . rawurlencode($id));
    }

    public function cancelCode(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/codes/' . rawurlencode($id) . '/cancel', null, $idempotencyKey ?? bin2hex(random_bytes(16)));
    }

    public function getTransaction(string $id): array
    {
        return $this->request('GET', '/transactions/' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json', 'Content-Type: application/json'];

        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        [$status, $raw] = ($this->transport)($method, rtrim($this->baseUrl, '/') . $path, $headers, $body === null ? null : json_encode($body));

        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 400) {
            throw new ApiException(
                $status,
                $decoded['error']['code'] ?? 'http_' . $status,
                $decoded['error']['message'] ?? $decoded['message'] ?? 'Request failed.',
                $decoded,
            );
        }

        return $decoded;
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException('Request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $raw];
    }
}
