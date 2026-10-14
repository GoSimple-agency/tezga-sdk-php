<?php

declare(strict_types=1);

namespace GoSimple\Tezga;

/**
 * Thin client for the Tezga Fiscal API, written against the official contract:
 * https://tezgafiskal.com/api/openapi.json (v1.6.0).
 *
 * Request bodies are forwarded as-is with the field names the API documents, so the
 * SDK cannot drift from the published docs. Auth goes in the X-Tezga-Api-Key header.
 */
class TezgaClient
{
    public const DEFAULT_BASE_URL = 'https://pos.narbiz.com';

    private string $baseUrl;

    /**
     * @param string $provider Tag identifying your integration (max 40 chars).
     * @param int    $timeout  Seconds before a request is aborted.
     */
    public function __construct(
        private string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private string $provider = 'custom',
        private int $timeout = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ── Receipts ─────────────────────────────────────────────────────────────

    /**
     * Issue and fiscalize a receipt (or a proforma). Idempotent on externalId.
     * A 202 means it was accepted and is still processing: read statusDokumenta,
     * or poll getDocumentByExternalId().
     */
    public function issueReceipt(array $body): array
    {
        return $this->request('POST', '/order', $body, ['provider' => $this->provider]);
    }

    /** Void ('storno') or refund ('refundacija') a receipt, fully or per item. */
    public function refund(array $body): array
    {
        if (empty($body['documentId']) && empty($body['externalId'])) {
            throw new TezgaException('Provide documentId or externalId of the original receipt.', 400);
        }

        return $this->request('POST', '/refund', $body);
    }

    /** Issue a copy ('КОПИЈА') of an existing receipt. */
    public function copyReceipt(array $body, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key: ' . $idempotencyKey] : [];

        return $this->request('POST', '/copy', $body, [], $headers);
    }

    // ── Advances ─────────────────────────────────────────────────────────────

    /** Open an advance (avans) receipt for an amount actually received. */
    public function openAdvance(array $body): array
    {
        return $this->request('POST', '/advance', $body);
    }

    /** Close an advance: refunds the advance, then issues the final receipt. */
    public function closeAdvance(array $body): array
    {
        // array_merge so a caller's 'zatvori' => false can never turn this into an open.
        return $this->request('POST', '/advance', array_merge($body, ['zatvori' => true]));
    }

    // ── Stock and catalogue ──────────────────────────────────────────────────

    /** Costing / goods receipt (kalkulacija). */
    public function createCosting(array $body): array
    {
        return $this->request('POST', '/kalkulacija', $body);
    }

    /** Price change (nivelacija). */
    public function createPriceChange(array $body): array
    {
        return $this->request('POST', '/nivelacija', $body);
    }

    /** Create or update catalogue items, 1 to 500 per call. */
    public function upsertProducts(array $stavke): array
    {
        return $this->request('POST', '/products', ['stavke' => $stavke]);
    }

    /** Create or update customers, 1 to 500 per call. */
    public function upsertCustomers(array $kupci): array
    {
        return $this->request('POST', '/customers', ['kupci' => $kupci]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /** Read a document by YOUR order number. */
    public function getDocumentByExternalId(string $externalId): array
    {
        return $this->request('GET', '/documents', null, [
            'externalId' => $externalId,
            'provider' => $this->provider,
        ]);
    }

    /** Read a document by its Tezga document ID. */
    public function getDocument(string $id): array
    {
        return $this->request('GET', '/documents/' . rawurlencode($id));
    }

    /** Tax rates and labels, plus `okruzenje` for this key. */
    public function getTaxLabels(): array
    {
        return $this->request('GET', '/tax-labels');
    }

    /** Bank transactions for the company. Filters: smer, since, limit. */
    public function getTransactions(array $query = []): array
    {
        return $this->request('GET', '/transactions', null, $query);
    }

    /**
     * Environment this key is bound to: 'production' | 'sandbox' | 'simulation' |
     * 'unknown'. Call before issuing: a production key creates a REAL receipt.
     */
    public function getEnvironment(): string
    {
        $labels = $this->getTaxLabels();

        return match ($labels['okruzenje'] ?? null) {
            'produkcija' => 'production',
            'sandbox' => 'sandbox',
            'simulacija' => 'simulation',
            default => 'unknown',
        };
    }

    // ── Transport ────────────────────────────────────────────────────────────

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $extraHeaders = [],
    ): array {
        if ($this->apiKey === '') {
            throw new TezgaException('Missing Tezga API key (shape tzg_...).', 401);
        }

        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        $url = $this->baseUrl . '/api/integrations' . $path . ($query !== [] ? '?' . http_build_query($query) : '');

        $headers = array_merge([
            'X-Tezga-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ], $extraHeaders);

        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new TezgaException('Request body could not be encoded as JSON: ' . $e->getMessage(), 0);
            }
            $headers[] = 'Content-Type: application/json';
        }

        ['status' => $status, 'body' => $raw] = $this->send($method, $url, $payload, $headers);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // Not every response is JSON; keep the raw text.
            $data = $raw === '' ? [] : ['raw' => $raw];
        }

        if ($status < 200 || $status >= 300) {
            $message = is_string($data['error'] ?? null) && $data['error'] !== ''
                ? $data['error']
                : "Tezga API {$status}";
            $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
            throw new TezgaException($message, $status, $data, $fields);
        }

        return $data;
    }

    /**
     * Performs the HTTP call. Overridable so tests can exercise URL, headers and body
     * without touching the network.
     *
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    protected function send(string $method, string $url, ?string $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated in 8.5.
        $raw = curl_exec($ch);
        if ($raw === false) {
            // Not retryable on purpose: re-sending a POST is only safe when the call is
            // idempotent (externalId, or Idempotency-Key on /copy).
            throw new TezgaException('Network error calling Tezga: ' . curl_error($ch), 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => (string) $raw];
    }
}
