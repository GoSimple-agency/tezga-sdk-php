# Tezga Fiscal API — PHP SDK

Issue and fiscalize **legal fiscal receipts in Serbia (eKasa / SUF)** from PHP, with one
`composer require` and an API key. No L-PFR hardware, no signing code on your side: you
send a plain order, and Tezga, a cloud ESIR, fiscalizes it through the Serbian Tax
Administration's virtual processor (V-PFR) and returns the **PFR number, QR payload, verification URL and a
public PDF link**.

Written against the official contract:
[tezgafiskal.com/api/openapi.json](https://tezgafiskal.com/api/openapi.json) (v1.6.0).
Full documentation: [Fiscalization API](https://tezgafiskal.com/en/fiscalization-api).

## Installation

```bash
composer require gosimple/tezga
```

Requires PHP 8.1+ with the `curl` and `json` extensions.

## Quickstart

```php
use GoSimple\Tezga\TezgaClient;

$tezga = new TezgaClient(getenv('TEZGA_API_KEY'));

// A production key issues a REAL receipt. Check first.
echo $tezga->getEnvironment(); // "production" | "sandbox" | "simulation"

$doc = $tezga->issueReceipt([
    'externalId' => 'ORDER-1042',      // idempotency key: never issues twice
    'nacinPlacanja' => 'Kartica',
    'tok' => 'fiskalni',
    'stavke' => [
        ['naziv' => 'Espresso', 'kolicina' => 2, 'cenaSaPdv' => 180, 'poreskaStopa' => '20'],
        ['naziv' => 'Croissant', 'kolicina' => 1, 'cenaSaPdv' => 220, 'poreskaStopa' => '10'],
    ],
    'kupac' => ['naziv' => 'ACME d.o.o.', 'pib' => '123456789'], // pib -> B2B receipt
    'posaljiMejl' => true,
]);

echo $doc['fiskalni']['pfrBroj'], ' ', $doc['javniPdfUrl'];
```

### Why the field names are Serbian

Method names and docs are English, but **request keys use exactly the names the API
documents** (`stavke`, `kupac`, `nacinPlacanja`, …). No renaming layer sits between you
and the contract, so the SDK can never silently drift from
[the official spec](https://tezgafiskal.com/api/openapi.json) or the published docs.

## Methods

| Method | Endpoint | What it does |
| --- | --- | --- |
| `issueReceipt(array)` | `POST /order` | Issue and fiscalize a receipt or proforma. Idempotent on `externalId`. |
| `refund(array)` | `POST /refund` | Void (`'vrsta' => 'storno'`) or refund (`'refundacija'`), full or per item. |
| `copyReceipt(array, ?string)` | `POST /copy` | Issue a copy (КОПИЈА); optional `Idempotency-Key`. |
| `openAdvance(array)` | `POST /advance` | Open an advance for the amount actually received. |
| `closeAdvance(array)` | `POST /advance` | Refund the advance, then issue the final receipt. |
| `createCosting(array)` | `POST /kalkulacija` | Goods receipt / costing. |
| `createPriceChange(array)` | `POST /nivelacija` | Price change. |
| `upsertProducts(array)` | `POST /products` | Create or update catalogue items (1–500). |
| `upsertCustomers(array)` | `POST /customers` | Create or update customers (1–500). |
| `getDocumentByExternalId(string)` | `GET /documents` | Read a document by **your** order number. |
| `getDocument(string)` | `GET /documents/{id}` | Read a document by Tezga document ID. |
| `getTaxLabels()` | `GET /tax-labels` | Tax rates, labels and `okruzenje`. |
| `getTransactions(array)` | `GET /transactions` | Bank transactions (`smer`, `since`, `limit`). |
| `getEnvironment()` | — | `getTaxLabels()` mapped to an English string. |

## Authentication and environments

The key goes in the `X-Tezga-Api-Key` header (shape `tzg_` + 48 hex); the SDK sets it for
you. **The environment is a property of the account, not the URL**: test and production
keys look identical, so read `okruzenje` from `getTaxLabels()` (or call
`getEnvironment()`) before issuing anything. A sandbox or simulation key never creates a
real receipt.

Use one key per project and never commit it to a public repository.

Tag your integration once and it is sent on `/order`, `/documents` and friends:

```php
$tezga = new TezgaClient($apiKey, TezgaClient::DEFAULT_BASE_URL, 'my-shop');
```

Requests time out after **30 seconds** by default (the fourth constructor argument). A
network failure or timeout throws `TezgaException` with status `0`. It is deliberately not
marked retryable: re-sending a POST is safe only when the call is idempotent (an
`externalId`, or an `Idempotency-Key` on `copyReceipt()`).

## Async results

`POST /order` can answer **202**: the document was accepted and is still being processed.
Read `statusDokumenta`, or poll `getDocumentByExternalId($externalId)` until `fiskalni` is
filled in. `fiskalni` is `null` while a document is not fiscalized.

## Webhooks

Tezga POSTs to the URL from Settings → Integrations with `X-Tezga-Event`,
`X-Tezga-Delivery` and `X-Tezga-Signature` (sha256 HMAC of the body). Verify against the
**raw** body, never a re-encoded array:

```php
use GoSimple\Tezga\Webhooks;

$raw = file_get_contents('php://input');

$payload = Webhooks::parse(
    $raw,
    $_SERVER['HTTP_X_TEZGA_SIGNATURE'] ?? null,
    getenv('TEZGA_WEBHOOK_SECRET'),
);

// $payload['dogadjaj'] : racun.fiskalizovan | racun.greska
//                        | refundacija.izvrsena | storno.izvrsen
// $payload['podaci']   : the document, plus your externalId and provider when known
http_response_code(200);
```

`Webhooks::parse()` throws unless the signature matches, so an unverified payload never
reaches your handler. Use `Webhooks::verifySignature()` if you want the boolean instead.

A failed delivery is retried after **1, 5, 15, 60 and 360 minutes**, so make the handler
idempotent: key it on `X-Tezga-Delivery` or on `podaci['externalId']`.

## Error handling

```php
use GoSimple\Tezga\TezgaException;

try {
    $tezga->issueReceipt(['externalId' => 'X', 'stavke' => []]);
} catch (TezgaException $e) {
    error_log($e->getStatus() . ' ' . $e->getMessage()); // message is the API's `error`
    foreach ($e->getFields() as $f) {
        error_log(($f['path'] ?? '') . ': ' . ($f['message'] ?? ''));
    }
    if ($e->isRetryable()) { /* 429 rate limit, or 409 still in flight */ }
}
```

Statuses worth handling: **402** billing, **409** conflict, **413** too large, **422** the
Tax Administration rejected it, **429** rate limit.

## Testing

`TezgaClient::send()` is `protected`, so you can subclass it in tests and stub the
network without touching curl. See [`test/client_test.php`](./test/client_test.php).

## FAQ

**Do I need fiscal hardware (L-PFR)?** No. Tezga is a cloud ESIR that works with the Tax
Administration's virtual processor (V-PFR); you only call the REST API.

**How do I test without issuing real receipts?** Use a sandbox or simulation key and check
`getEnvironment()` first.

**How are retries kept safe?** Every receipt carries an `externalId`; re-sending the same
value returns the original receipt instead of issuing a second one.

**Can I issue B2B receipts?** Yes. Set `kupac.pib` and the receipt becomes B2B.

**Where do I get an API key?** In your Tezga account under Settings → Integrations.

## About

Tezga is a product of **GoSimple**.
Learn more at [gosimple.space](https://gosimple.space) and
[tezgafiskal.com](https://tezgafiskal.com).

Native apps: [iOS](https://apps.apple.com/rs/app/tezga-ekasa/id6815615096) ·
[Android](https://play.google.com/store/apps/details?id=rs.gosimple.tezga)

See [`LICENSE`](./LICENSE) and [`NOTICE`](./NOTICE).
