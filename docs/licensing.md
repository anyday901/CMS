# Software licensing

The **Software licensing** module sells license keys for your own software. Each service of a product on the module is one license. Its key is issued when the service starts (its first invoice is paid), stops working while the service is suspended, and expires when the service ends. Your software asks this app whether its key is good each time it starts, or once a day.

## Setting up a product

Choose **Software licensing** as the product's module and fill in:

- **Signing secret**: at least 16 random characters, which you also build into your software. Every answer is signed with it, so the software can tell a real answer from a faked one. Changing it means every copy of the software needs the new one.
- **Key prefix**: put at the start of each key, such as `SHOP-`.
- **Lock to**: what a license is locked to the first time it is checked: the domain, IP address and directory (the default), the domain and IP address, the domain only, or nothing.
- **Installs per license**: how many domains (and IP addresses and directories) one license may be locked to. 1 when blank.
- **Reissue**: whether clients can reissue their own license from the portal, or only staff can.

A reissue clears where the license is locked, so the next check locks it to wherever the software runs then. Staff can reissue from the service page at any time.

## The check

Send a `POST` to `https://your-domain/api/licenses/check` with these form or JSON fields:

| Field | |
| --- | --- |
| `key` | The license key. Required. |
| `domain` | The domain the software runs on. A full address such as `https://www.example.com/shop` is fine: the host is used, without `www.`. |
| `directory` | Where the software is installed, such as `/home/shop/public_html`. |
| `check_token` | Any random string, sent back in the signed answer so an old answer can't be replayed to the software. |

The IP address is the one the check comes from. Each address can make 60 checks a minute.

The answer is JSON:

```json
{
  "status": "Active",
  "message": null,
  "key": "SHOP-7KQ2M-XW9PA-3HTRD-QZ4NB",
  "product": "Shop Pro",
  "registered_name": "Ada Lovelace",
  "company": null,
  "service_status": "active",
  "billing_cycle": "annually",
  "next_due_date": "2027-03-01",
  "domains": ["example.com"],
  "ips": ["203.0.113.5"],
  "directories": ["/home/shop/public_html"],
  "checked_at": "2026-10-13T22:00:00+00:00",
  "check_token": "the token you sent"
}
```

`status` is `Active`, `Suspended`, `Expired` or `Invalid` (an unknown key, or one locked to somewhere else), with `message` saying why when it isn't active.

The `X-License-Signature` header is the HMAC-SHA256 of the raw response body with the product's signing secret, in hex. Answers for unknown keys aren't signed. Only trust an answer whose signature matches and whose `check_token` is the one you sent.

## Checking from PHP

```php
function licenseIsActive(string $key, string $secret): bool
{
    $token = bin2hex(random_bytes(16));
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'key' => $key,
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'directory' => __DIR__,
            'check_token' => $token,
        ]),
        'timeout' => 10,
    ]]);

    $body = @file_get_contents('https://billing.example.com/api/licenses/check', false, $context);
    $signature = '';
    foreach ($http_response_header ?? [] as $header) {
        if (stripos($header, 'X-License-Signature:') === 0) {
            $signature = trim(substr($header, strlen('X-License-Signature:')));
        }
    }

    if ($body === false || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
        return false;
    }

    $answer = json_decode($body, true);

    return ($answer['check_token'] ?? null) === $token && ($answer['status'] ?? null) === 'Active';
}
```

Keep the last good answer (and when it came) so the software keeps working for a while if the billing site can't be reached, rather than stopping the moment a check fails.
