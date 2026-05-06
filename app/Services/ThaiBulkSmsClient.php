<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ThaiBulkSmsClient
{
    public function credit(): array
    {
        $config = config('services.thaibulksms');

        $response = Http::withBasicAuth($config['api_key'], $config['api_secret'])
            ->acceptJson()
            ->timeout((int) $config['timeout'])
            ->get($config['credit_endpoint']);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $response->json() ?? ['raw' => $response->body()],
        ];
    }

    public function send(string $recipient, string $message): array
    {
        $config = config('services.thaibulksms');

        $payload = array_filter([
            'msisdn' => $recipient,
            'message' => $message,
            'sender' => $config['sender'],
            'force' => $config['credit_type'],
            'shorten_url' => $config['shorten_url'],
            'expire' => $config['expire'],
        ], fn ($value) => $value !== null && $value !== '');

        $response = Http::asForm()
            ->withBasicAuth($config['api_key'], $config['api_secret'])
            ->timeout((int) $config['timeout'])
            ->post($config['endpoint'], $payload);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'payload' => $payload,
            'body' => $response->json() ?? ['raw' => $response->body()],
        ];
    }
}
