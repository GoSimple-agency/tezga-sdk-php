<?php

declare(strict_types=1);

// Signature verification is security code, so it gets its own suite.
// Run: php test/webhooks_test.php
require __DIR__ . '/../src/TezgaException.php';
require __DIR__ . '/../src/Webhooks.php';

use GoSimple\Tezga\TezgaException;
use GoSimple\Tezga\Webhooks;

$fail = 0;
function ok(string $name, bool $cond): void
{
    global $fail;
    if ($cond) { echo "OK  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}

$secret = 'whsec_test';
$body = json_encode([
    'dogadjaj' => 'racun.fiskalizovan',
    'vreme' => '2026-10-14T10:00:00Z',
    'tenantId' => '11111111-2222-3333-4444-555555555555',
    'podaci' => ['documentId' => 'd1', 'externalId' => 'ORDER-1042'],
], JSON_UNESCAPED_UNICODE);

$hex = hash_hmac('sha256', $body, $secret);
$b64 = base64_encode(hash_hmac('sha256', $body, $secret, true));

ok('accepts hex signature', Webhooks::verifySignature($body, $hex, $secret));
ok('accepts base64 signature', Webhooks::verifySignature($body, $b64, $secret));
ok('accepts uppercase hex', Webhooks::verifySignature($body, strtoupper($hex), $secret));
ok('accepts sha256= prefix', Webhooks::verifySignature($body, 'sha256=' . $hex, $secret));
ok('tolerates surrounding spaces', Webhooks::verifySignature($body, " {$hex} ", $secret));

ok('rejects tampered body', !Webhooks::verifySignature(str_replace('ORDER-1042', 'ORDER-9999', $body), $hex, $secret));
ok('rejects wrong secret', !Webhooks::verifySignature($body, $hex, 'whsec_other'));
ok('rejects wrong signature', !Webhooks::verifySignature($body, 'deadbeef', $secret));
ok('rejects empty signature', !Webhooks::verifySignature($body, '', $secret));
ok('rejects null signature', !Webhooks::verifySignature($body, null, $secret));
ok('rejects empty secret', !Webhooks::verifySignature($body, $hex, ''));
ok('length mismatch returns false', !Webhooks::verifySignature($body, substr($hex, 0, 10), $secret));

$payload = Webhooks::parse($body, $hex, $secret);
ok('parse returns the event', ($payload['dogadjaj'] ?? null) === 'racun.fiskalizovan');
ok('parse returns externalId', ($payload['podaci']['externalId'] ?? null) === 'ORDER-1042');

try {
    Webhooks::parse($body, 'deadbeef', $secret);
    ok('parse rejects a bad signature', false);
} catch (TezgaException $e) {
    ok('parse rejects a bad signature', $e->getStatus() === 401);
}

ok('event list is complete', Webhooks::EVENTS === [
    'racun.fiskalizovan',
    'racun.greska',
    'refundacija.izvrsena',
    'storno.izvrsen',
]);

echo $fail === 0 ? "\nAll passed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
