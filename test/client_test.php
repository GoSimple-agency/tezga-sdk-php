<?php

declare(strict_types=1);

// Exercises the real client with the network stubbed out: URL building, headers,
// body forwarding and error parsing. Run: php test/client_test.php
require __DIR__ . '/../src/TezgaException.php';
require __DIR__ . '/../src/TezgaClient.php';

use GoSimple\Tezga\TezgaClient;
use GoSimple\Tezga\TezgaException;

/** Records every call and replays a canned response instead of hitting the network. */
final class StubClient extends TezgaClient
{
    public array $calls = [];

    public function __construct(
        private int $stubStatus = 200,
        private array $stubBody = [],
        string $provider = 'custom',
        string $apiKey = 'tzg_test',
    ) {
        parent::__construct($apiKey, TezgaClient::DEFAULT_BASE_URL, $provider);
    }

    protected function send(string $method, string $url, ?string $payload, array $headers): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'payload' => $payload, 'headers' => $headers];

        return ['status' => $this->stubStatus, 'body' => json_encode($this->stubBody, JSON_UNESCAPED_UNICODE)];
    }
}

$fail = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $fail;
    if ($actual == $expected) {
        echo "OK  {$name}\n";
    } else {
        $fail++;
        echo "FAIL {$name}\n";
        echo '  got: ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
        echo '  exp: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
function ok(string $name, bool $cond): void
{
    global $fail;
    if ($cond) { echo "OK  {$name}\n"; } else { $fail++; echo "FAIL {$name}\n"; }
}

// issueReceipt: URL, method, headers, untouched body
$c = new StubClient();
$c->issueReceipt([
    'externalId' => 'ORDER-1042',
    'nacinPlacanja' => 'Kartica',
    'tok' => 'fiskalni',
    'stavke' => [['naziv' => 'Espresso', 'kolicina' => 2, 'cenaSaPdv' => 180, 'poreskaStopa' => '20']],
    'kupac' => ['naziv' => 'ACME', 'pib' => '123456789'],
]);
check('issueReceipt url', $c->calls[0]['url'], 'https://pos.narbiz.com/api/integrations/order?provider=custom');
check('issueReceipt method', $c->calls[0]['method'], 'POST');
ok('issueReceipt api key header', in_array('X-Tezga-Api-Key: tzg_test', $c->calls[0]['headers'], true));
check('issueReceipt body untouched', json_decode($c->calls[0]['payload'], true), [
    'externalId' => 'ORDER-1042',
    'nacinPlacanja' => 'Kartica',
    'tok' => 'fiskalni',
    'stavke' => [['naziv' => 'Espresso', 'kolicina' => 2, 'cenaSaPdv' => 180, 'poreskaStopa' => '20']],
    'kupac' => ['naziv' => 'ACME', 'pib' => '123456789'],
]);

// provider override
$c = new StubClient(200, [], 'woocommerce');
$c->issueReceipt(['externalId' => 'X', 'stavke' => []]);
check('provider override', $c->calls[0]['url'], 'https://pos.narbiz.com/api/integrations/order?provider=woocommerce');

// closeAdvance adds zatvori
$c = new StubClient();
$c->closeAdvance(['externalId' => 'ADV-5', 'stavke' => [['naziv' => 'Usluga', 'kolicina' => 1, 'cenaSaPdv' => 1000]]]);
check('closeAdvance body', json_decode($c->calls[0]['payload'], true), [
    'externalId' => 'ADV-5',
    'stavke' => [['naziv' => 'Usluga', 'kolicina' => 1, 'cenaSaPdv' => 1000]],
    'zatvori' => true,
]);

// openAdvance forwards the legally required fields
$c = new StubClient();
$c->openAdvance(['externalId' => 'ADV-5', 'placeniAvans' => 10000, 'datumUplate' => '2026-10-14']);
check('openAdvance body', json_decode($c->calls[0]['payload'], true), [
    'externalId' => 'ADV-5', 'placeniAvans' => 10000, 'datumUplate' => '2026-10-14',
]);

// reads
$c = new StubClient();
$c->getDocumentByExternalId('ORDER-1042');
check('getDocumentByExternalId url', $c->calls[0]['url'], 'https://pos.narbiz.com/api/integrations/documents?externalId=ORDER-1042&provider=custom');
ok('GET has no payload', $c->calls[0]['payload'] === null);

$c = new StubClient();
$c->getDocument('d/01 HX');
check('getDocument encodes id', $c->calls[0]['url'], 'https://pos.narbiz.com/api/integrations/documents/d%2F01%20HX');

$c = new StubClient();
$c->getTransactions(['smer' => 'in', 'limit' => 50]);
check('getTransactions query', $c->calls[0]['url'], 'https://pos.narbiz.com/api/integrations/transactions?smer=in&limit=50');
$c->getTransactions();
check('getTransactions no query', $c->calls[1]['url'], 'https://pos.narbiz.com/api/integrations/transactions');

// copy sends Idempotency-Key
$c = new StubClient();
$c->copyReceipt(['documentId' => 'D1'], 'key-1');
ok('copyReceipt idempotency header', in_array('Idempotency-Key: key-1', $c->calls[0]['headers'], true));

// bulk wrappers
$c = new StubClient();
$c->upsertProducts([['sifra' => 'K1', 'naziv' => 'Kafa']]);
$c->upsertCustomers([['naziv' => 'ACME', 'pib' => '123456789']]);
check('upsertProducts wraps', json_decode($c->calls[0]['payload'], true), ['stavke' => [['sifra' => 'K1', 'naziv' => 'Kafa']]]);
check('upsertCustomers wraps', json_decode($c->calls[1]['payload'], true), ['kupci' => [['naziv' => 'ACME', 'pib' => '123456789']]]);

// errors: { error, fields }
$c = new StubClient(422, ['error' => 'Poreska uprava je odbila račun', 'fields' => [['path' => 'stavke.0.cenaSaPdv', 'message' => 'mora biti > 0']]]);
try {
    $c->issueReceipt(['externalId' => 'X', 'stavke' => []]);
    ok('error thrown', false);
} catch (TezgaException $e) {
    check('error message', $e->getMessage(), 'Poreska uprava je odbila račun');
    check('error status', $e->getStatus(), 422);
    check('error field path', $e->getFields()[0]['path'], 'stavke.0.cenaSaPdv');
    ok('422 not retryable', $e->isRetryable() === false);
}

foreach ([[429, true], [409, true], [402, false]] as [$status, $expected]) {
    $c = new StubClient($status, ['error' => 'x']);
    try {
        $c->getTaxLabels();
        ok("status {$status} thrown", false);
    } catch (TezgaException $e) {
        ok("status {$status} retryable={$expected}", $e->isRetryable() === $expected);
    }
}

// environment mapping
foreach ([['produkcija', 'production'], ['sandbox', 'sandbox'], ['simulacija', 'simulation'], [null, 'unknown']] as [$sr, $en]) {
    $c = new StubClient(200, $sr === null ? [] : ['okruzenje' => $sr]);
    check('environment ' . ($sr ?? 'null'), $c->getEnvironment(), $en);
}

// guards
$c = new StubClient();
try {
    $c->refund(['vrsta' => 'storno']);
    ok('refund guard', false);
} catch (TezgaException $e) {
    ok('refund guard before request', count($c->calls) === 0);
}

$c = new StubClient(200, [], 'custom', '');
try {
    $c->getTaxLabels();
    ok('missing key guard', false);
} catch (TezgaException $e) {
    ok('missing key fails fast', $e->getStatus() === 401);
}

// closeAdvance must close even if the caller passes zatvori => false
$c = new StubClient();
$c->closeAdvance(['externalId' => 'ADV-5', 'zatvori' => false]);
check('closeAdvance forces zatvori true', json_decode($c->calls[0]['payload'], true)['zatvori'], true);

// invalid UTF-8 fails loudly instead of sending the body "false"
$c = new StubClient();
try {
    $c->issueReceipt(['externalId' => "\xB1\x31", 'stavke' => []]);
    ok('invalid UTF-8 rejected', false);
} catch (TezgaException $e) {
    ok('invalid UTF-8 rejected before sending', count($c->calls) === 0);
}

echo $fail === 0 ? "\nAll passed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
