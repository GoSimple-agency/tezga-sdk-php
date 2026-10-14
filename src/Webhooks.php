<?php

declare(strict_types=1);

namespace GoSimple\Tezga;

/**
 * Webhooks. Tezga POSTs to the URL from Settings → Integrations with the headers
 * X-Tezga-Event, X-Tezga-Delivery and X-Tezga-Signature (sha256 HMAC of the body).
 * A failed delivery is retried after 1, 5, 15, 60 and 360 minutes, so your handler
 * must be idempotent: key it on X-Tezga-Delivery or on podaci.externalId.
 */
final class Webhooks
{
    public const EVENTS = [
        'racun.fiskalizovan',
        'racun.greska',
        'refundacija.izvrsena',
        'storno.izvrsen',
    ];

    /** Header names Tezga sends with every delivery. */
    public const HEADER_EVENT = 'X-Tezga-Event';
    public const HEADER_DELIVERY = 'X-Tezga-Delivery';
    public const HEADER_SIGNATURE = 'X-Tezga-Signature';

    /** Minutes after which a failed delivery is retried. */
    public const RETRY_MINUTES = [1, 5, 15, 60, 360];

    /**
     * Verify the X-Tezga-Signature header against the RAW request body.
     *
     * Pass the body exactly as received (file_get_contents('php://input')), never a
     * re-encoded array, or the HMAC will not match. $secret is the webhook secret
     * from Settings → Integrations.
     *
     * shortcut: the contract documents "sha256 HMAC of the body" without fixing the
     * encoding, so both hex and base64 of the same digest are accepted (an optional
     * "sha256=" prefix is stripped). Both are full HMAC checks, so this does not
     * weaken verification; pin it to the one real encoding once a live delivery
     * confirms it.
     */
    public static function verifySignature(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }
        $sig = (string) preg_replace('/^sha256=/i', '', trim($signature));
        $digest = hash_hmac('sha256', $rawBody, $secret, true);

        return hash_equals(bin2hex($digest), strtolower($sig))
            || hash_equals(base64_encode($digest), $sig);
    }

    /**
     * Verify and decode a delivery in one step. Throws when the signature does not
     * match, so an unverified payload can never reach your handler.
     *
     * @return array{dogadjaj: string, vreme: string, tenantId: string, podaci: array}
     */
    public static function parse(string $rawBody, ?string $signature, string $secret): array
    {
        if (!self::verifySignature($rawBody, $signature, $secret)) {
            throw new TezgaException('Invalid X-Tezga-Signature: the webhook could not be verified.', 401);
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new TezgaException('Webhook body is not valid JSON.', 400);
        }

        return $data;
    }
}
