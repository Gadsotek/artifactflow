<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final readonly class TurnstileVerifier
{
    private const int MAX_LOGGED_ERROR_CODES = 8;

    private const int MAX_ERROR_CODE_LENGTH = 64;

    public function __construct(
        private TurnstileConfiguration $configuration,
    ) {
    }

    public function enabled(): bool
    {
        return $this->configuration->enabled();
    }

    public function verify(string $token, ?string $remoteIp, string $expectedAction): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->requestTimeoutSeconds())
                ->post(TurnstileConfiguration::VERIFY_URL, array_filter([
                    'secret' => $this->configuration->secret(),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ], static fn (?string $value): bool => $value !== null));
        } catch (ConnectionException) {
            return $this->reject('connection_failure');
        }

        return $this->validResponse($response, $expectedAction);
    }

    private function validResponse(Response $response, string $expectedAction): bool
    {
        if (!$response->successful()) {
            return $this->reject('http_status', status: $response->status());
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            return $this->reject('malformed_response');
        }

        $hostname = $payload['hostname'] ?? null;
        $action = $payload['action'] ?? null;

        if (($payload['success'] ?? null) !== true) {
            return $this->reject('rejected', $payload);
        }

        if (!is_string($action)) {
            return $this->reject('missing_action');
        }

        if (!hash_equals($expectedAction, $action)) {
            return $this->reject('action_mismatch');
        }

        if (!is_string($hostname)) {
            return $this->reject('missing_hostname');
        }

        if (!hash_equals($this->configuration->expectedHostname(), strtolower($hostname))) {
            return $this->reject('hostname_mismatch');
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function reject(string $reason, array $payload = [], ?int $status = null): false
    {
        $context = ['reason' => $reason];
        $errorCodes = $this->safeErrorCodes($payload);

        if ($errorCodes !== []) {
            $context['error_codes'] = $errorCodes;
        }

        if ($status !== null) {
            $context['status'] = $status;
        }

        Log::warning('turnstile.siteverify_failed', $context);

        return false;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function safeErrorCodes(array $payload): array
    {
        $untrustedCodes = $payload['error-codes'] ?? null;

        if (!is_array($untrustedCodes)) {
            return [];
        }

        $codes = [];
        foreach ($untrustedCodes as $code) {
            if (
                !is_string($code)
                || strlen($code) > self::MAX_ERROR_CODE_LENGTH
                || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $code) !== 1
            ) {
                continue;
            }

            $codes[] = $code;
            if (count($codes) === self::MAX_LOGGED_ERROR_CODES) {
                break;
            }
        }

        return array_values(array_unique($codes));
    }
}
