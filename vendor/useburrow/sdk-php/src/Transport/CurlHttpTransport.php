<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

use Burrow\Sdk\Transport\Exception\InvalidJsonException;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use JsonException;

final class CurlHttpTransport implements ConcurrentHttpTransportInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy()
    )
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        for ($attempt = 1; $attempt <= $this->retryPolicy->maxAttempts; $attempt++) {
            try {
                $response = $this->postOnce($url, $headers, $payload);
            } catch (TransportFailureException $exception) {
                if (!$this->retryPolicy->shouldRetryTransportFailure($attempt)) {
                    throw $exception;
                }

                $this->sleepForRetry($attempt);
                continue;
            }

            if ($this->retryPolicy->shouldRetryStatus($response->status, $attempt)) {
                $this->sleepForRetry($attempt);
                continue;
            }

            return $response;
        }

        throw new TransportFailureException('Request failed after exhausting retry attempts.');
    }

    public function postConcurrent(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $multiHandle = curl_multi_init();
        if ($multiHandle === false) {
            throw new TransportFailureException('Failed to initialize cURL multi handle.');
        }

        $handles = [];
        foreach ($requests as $index => $request) {
            $ch = $this->createHandle(
                $request['url'],
                $request['headers'],
                $request['payload']
            );
            $handles[$index] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
        }

        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($status > CURLM_OK) {
                break;
            }
            if ($active) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($active);

        $responses = [];
        foreach ($handles as $index => $ch) {
            $raw = curl_multi_getcontent($ch);
            if (!is_string($raw)) {
                $raw = '';
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errorNumber = curl_errno($ch);
            $error = curl_error($ch);
            $responseHeaders = $this->responseHeadersByHandle[(int) $ch] ?? [];
            unset($this->responseHeadersByHandle[(int) $ch]);

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if ($errorNumber !== 0) {
                curl_multi_close($multiHandle);
                throw new TransportFailureException('HTTP request failed: ' . $error);
            }

            $responses[$index] = $this->decodeHttpResponse($statusCode, $raw, $responseHeaders);
        }

        curl_multi_close($multiHandle);
        ksort($responses);
        return array_values($responses);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     */
    private function postOnce(string $url, array $headers, array $payload): HttpResponse
    {
        $ch = $this->createHandle($url, $headers, $payload);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            unset($this->responseHeadersByHandle[(int) $ch]);
            curl_close($ch);
            throw new TransportFailureException('HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $responseHeaders = $this->responseHeadersByHandle[(int) $ch] ?? [];
        unset($this->responseHeadersByHandle[(int) $ch]);
        curl_close($ch);

        return $this->decodeHttpResponse($status, $raw, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return resource
     */
    private function createHandle(string $url, array $headers, array $payload)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportFailureException('Failed to initialize cURL.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            curl_close($ch);
            throw new InvalidJsonException('Failed to encode JSON request payload.', 0, $exception);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Content-Type: application/json';

        $this->responseHeadersByHandle[(int) $ch] = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, string $headerLine): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $this->responseHeadersByHandle[(int) $curlHandle][strtolower(trim($name))] = trim($value);
                }
                return strlen($headerLine);
            },
        ]);

        return $ch;
    }

    /**
     * @param array<string,string> $responseHeaders
     */
    private function decodeHttpResponse(int $status, string $raw, array $responseHeaders): HttpResponse
    {
        if ($raw === '') {
            return new HttpResponse($status, null, $raw, $responseHeaders);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidJsonException('Failed to decode JSON response body.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidJsonException('Response JSON must decode into an object.');
        }

        return new HttpResponse($status, $decoded, $raw, $responseHeaders);
    }

    /** @var array<int, array<string,string>> */
    private array $responseHeadersByHandle = [];

    private function sleepForRetry(int $attempt): void
    {
        $delayMilliseconds = $this->retryPolicy->delayMillisecondsForAttempt($attempt);
        if ($delayMilliseconds <= 0) {
            return;
        }

        usleep($delayMilliseconds * 1_000);
    }
}
