# REST API

The REST API lets other software, such as an accounting tool, a status page or your own scripts, read and change clients, services, invoices, orders, products and tickets. It speaks JSON and lives under `https://your-domain/api/v1/`.

Records look the same as in [webhooks](https://github.com/anyday901/CMS/blob/main/app/Hooks/Payload.php): money is an integer in minor units (cents) with its currency next to it, dates are `YYYY-MM-DD` and times are ISO 8601.

## API keys

Staff who can change settings make keys under **Settings → API keys** (`/admin/settings/api-keys`). For each key choose:

- **Name**: what uses it, such as "Accounting sync". Changes made with the key show in the activity log as `API key: <name>`.
- **What it can do**: the abilities below. Give each key only what it needs.
- **Only from these IP addresses** (optional): addresses or CIDR ranges such as `203.0.113.0/24`, one per line. Requests from anywhere else are refused.
- **Expires on** (optional): the key stops working after that day.

The full key is shown once, right after it is made. Only a SHA-256 hash of it is stored, so if it is lost, revoke it and make a new one. A key looks like this:

```
cms_ab12cd34_9fK2mQ7xPz1LwR4tY8vB3nH6jD0sG5aE2cU7iO1k
```

The first part (`cms_ab12cd34`) is the key's prefix, shown in the list so you can tell keys apart. Name, abilities and IP addresses can be changed later; the key and its expiry can't.

**Revoke** stops a key straight away and keeps it in the list. **Delete** removes it.

## Authentication

Send the key in the `Authorization` header with every request:

```
GET /api/v1/me HTTP/1.1
Host: your-domain
Authorization: Bearer cms_ab12cd34_9fK2mQ7xPz1LwR4tY8vB3nH6jD0sG5aE2cU7iO1k
Accept: application/json
```

```sh
curl https://your-domain/api/v1/me \
  -H "Authorization: Bearer $CMS_API_KEY" \
  -H "Accept: application/json"
```

Send request bodies as JSON with `Content-Type: application/json`.

## Abilities

| Ability | Allows |
| --- | --- |
| `clients:read` | List and show clients |
| `clients:write` | Create and update clients |
| `services:read` | List and show services |
| `services:write` | Suspend, unsuspend and terminate services |
| `invoices:read` | List and show invoices, with their lines and payments |
| `invoices:write` | Record payments on invoices |
| `orders:read` | List and show orders |
| `products:read` | List and show products, with their prices |
| `tickets:read` | List and show tickets, with their replies (not internal notes) |
| `tickets:write` | Open tickets and reply to them |

`GET /api/v1/me` works with any key.

## Errors

Errors are JSON with a `message`:

| Status | When |
| --- | --- |
| `401` | No key, a key that doesn't exist, or a revoked or expired key |
| `403` | The key doesn't have the ability, or is used from an IP address it doesn't allow |
| `404` | The record doesn't exist |
| `422` | The request isn't valid, or the action can't be done (such as suspending a suspended service) |
| `429` | Too many requests (see below) |

```json
{ "message": "This API key doesn't have the invoices:write ability." }
```

Validation errors are Laravel's usual `422` answer, with each field's problems under `errors`:

```json
{
  "message": "The email field is required. (and 1 more error)",
  "errors": {
    "email": ["The email field is required."],
    "last_name": ["The last name field is required."]
  }
}
```

## Rate limits

Each key can make 120 requests a minute. Every answer says how many are left:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 117
```

Past the limit the answer is `429` with a `Retry-After` header giving the seconds to wait.

The time and IP address a key was last used show in the API keys list (updated at most once a minute).

## Pagination

Lists return 25 records a page. Ask for up to 100 with `per_page`, and for a page with `page`:

```
GET /api/v1/clients?per_page=50&page=2
```

```json
{
  "data": [ { "id": 51, "...": "..." } ],
  "links": {
    "first": "https://your-domain/api/v1/clients?per_page=50&page=1",
    "last": "https://your-domain/api/v1/clients?per_page=50&page=3",
    "prev": "https://your-domain/api/v1/clients?per_page=50&page=1",
    "next": "https://your-domain/api/v1/clients?per_page=50&page=3"
  },
  "meta": {
    "current_page": 2,
    "from": 51,
    "last_page": 3,
    "path": "https://your-domain/api/v1/clients",
    "per_page": 50,
    "to": 100,
    "total": 128
  }
}
```

Follow `links.next` until it is `null`. Single records come back under `data` too.

## Endpoints

### The key

#### `GET /api/v1/me`

The key making the request.

```json
{
  "data": {
    "id": 3,
    "name": "Accounting sync",
    "prefix": "cms_ab12cd34",
    "abilities": ["clients:read", "invoices:read", "invoices:write"],
    "expires_at": "2027-06-30T23:59:59+00:00"
  }
}
```

### Clients

#### `GET /api/v1/clients` (`clients:read`)

Filters, the same as the admin clients search:

| Parameter | |
| --- | --- |
| `q` | Name or company; every word has to match |
| `email`, `phone`, `address1`, `address2`, `city`, `state`, `postcode` | Part of the value |
| `country`, `currency` | Exact value, such as `US` or `USD` |
| `status` | `active`, `inactive` or `closed` |
| `show_closed` | `1` to include closed clients, which are left out otherwise (unless `status=closed`) |
| `group` | A client group id, or `none` |
| `credit_balance`, `tax_exempt`, `marketing_emails` | `yes` or `no` |
| `signup_from`, `signup_to` | Signed up on or after / on or before a date, `YYYY-MM-DD` |

Clients are in id order.

#### `GET /api/v1/clients/{id}` (`clients:read`)

```json
{
  "data": {
    "id": 42,
    "first_name": "Jane",
    "last_name": "Doe",
    "company": "Doe Widgets",
    "email": "jane@example.com",
    "phone": "+1 555 0100",
    "country": "US",
    "currency": "USD",
    "status": "active",
    "client_group_id": null,
    "credit_balance": 2500,
    "marketing_emails": false,
    "created_at": "2026-10-01T14:03:11+00:00",
    "address1": "1 Main Street",
    "address2": null,
    "city": "Springfield",
    "state": "IL",
    "postcode": "62701",
    "tax_exempt": false,
    "custom_fields": { "3": "Reseller" },
    "updated_at": "2026-10-12T09:30:00+00:00"
  }
}
```

`custom_fields` is keyed by custom field id (see **Settings → Custom fields**).

#### `POST /api/v1/clients` (`clients:write`)

Fields: `first_name`, `last_name` and `email` are required; `company`, `phone`, `address1`, `address2`, `city`, `state`, `postcode`, `country` (two letters), `status` (`active` when left out), `notes`, `tax_exempt`, `marketing_emails` (only set it when the client agreed), `client_group_id` and `custom_fields` are optional. Required custom fields have to be sent.

```json
{
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "country": "US"
}
```

The answer is `201` with the new client. It fires the same `client.created` event as adding a client in the admin area, so webhooks and add-ons see it. The client gets no password: send them a password link from the admin area, or they can use **Forgot password** in the portal.

#### `PUT` or `PATCH /api/v1/clients/{id}` (`clients:write`)

Send only the fields to change. A new email address has to be confirmed by the client again.

```json
{ "phone": "+1 555 0199", "status": "inactive" }
```

### Services

#### `GET /api/v1/services` (`services:read`)

Filters: `client_id`, `product_id`, `status` (`pending`, `active`, `suspended`, `terminated`, `cancelled`). In id order.

#### `GET /api/v1/services/{id}` (`services:read`)

```json
{
  "data": {
    "id": 17,
    "client_id": 42,
    "product_id": 5,
    "product_name": "Starter Hosting",
    "description": "Starter Hosting - example.com",
    "label": "example.com",
    "status": "active",
    "billing_cycle": "monthly",
    "recurring_amount": 999,
    "registration_date": "2026-01-01",
    "next_due_date": "2026-11-01",
    "order_id": 9,
    "parent_service_id": null,
    "server_id": 2,
    "suspension_reason": null,
    "suspended_at": null,
    "terminated_at": null,
    "created_at": "2026-01-01T10:00:00+00:00"
  }
}
```

Logins and passwords are never included.

#### `POST /api/v1/services/{id}/suspend`, `/unsuspend`, `/terminate` (`services:write`)

The same as the buttons on the admin service page: the provisioning module runs, the client is emailed where a template is set up, and add-ons follow their parent. No body is needed. Suspend works on active services, unsuspend on suspended ones, and terminate on pending, active or suspended ones; anything else is a `422`:

```json
{ "message": "Can't suspend a suspended service." }
```

The answer is the service after the change.

### Invoices

#### `GET /api/v1/invoices` (`invoices:read`)

Filters: `client_id`, `status` (`draft`, `unpaid`, `paid`, `cancelled`, `refunded`). In id order.

#### `GET /api/v1/invoices/{id}` (`invoices:read`)

```json
{
  "data": {
    "id": 120,
    "number": "1120",
    "client_id": 42,
    "status": "unpaid",
    "currency": "USD",
    "subtotal": 1500,
    "tax": 0,
    "total": 1500,
    "balance": 500,
    "issue_date": "2026-10-01",
    "due_date": "2026-10-15",
    "paid_at": null,
    "tax_name": null,
    "tax_rate": 0,
    "tax_inclusive": false,
    "notes": null,
    "created_at": "2026-10-01T00:05:00+00:00",
    "items": [
      {
        "id": 301,
        "service_id": 17,
        "type": "service",
        "description": "Starter Hosting - example.com (11/01/2026 - 11/30/2026)",
        "amount": 1500,
        "taxable": true,
        "period_start": "2026-11-01",
        "period_end": "2026-11-30"
      }
    ],
    "payments": [
      {
        "id": 88,
        "invoice_id": 120,
        "client_id": 42,
        "gateway": "bank_transfer",
        "method": "Bank transfer",
        "reference": "TX-1",
        "currency": "USD",
        "amount": 1000,
        "fee": 0,
        "pending": false,
        "refund_of_id": null,
        "created_at": "2026-10-03T12:00:00+00:00"
      }
    ]
  }
}
```

`items` and `payments` are only on a single invoice. Refunds are payments with a negative amount and a `refund_of_id`. `tax_rate` is in hundredths of a percent.

#### `POST /api/v1/invoices/{id}/payments` (`invoices:write`)

Records a payment made outside a payment gateway, like **Record payment** on the admin invoice page.

| Field | |
| --- | --- |
| `amount` | Required. In cents, such as `1000` for $10.00 |
| `method` | Required. `cash`, `check`, `bank_transfer`, `paypal`, `venmo`, `cashapp` or `other` |
| `reference` | Optional, such as a bank reference |

```json
{ "amount": 1000, "method": "bank_transfer", "reference": "TX-1" }
```

The answer is `201` with the payment, and the invoice after it:

```json
{
  "data": { "id": 88, "amount": 1000, "method": "Bank transfer", "reference": "TX-1", "...": "..." },
  "invoice": { "id": 120, "status": "unpaid", "balance": 500, "...": "..." }
}
```

Paying the whole balance marks the invoice paid and starts its services. Anything over the balance goes to the client's credit. Only unpaid invoices take payments; others get a `422` on `amount`.

### Orders

#### `GET /api/v1/orders` (`orders:read`)

Filters: `client_id`, `status` (`pending`, `active`, `cancelled`, `fraud`). Newest first.

#### `GET /api/v1/orders/{id}` (`orders:read`)

```json
{
  "data": {
    "id": 9,
    "client_id": 42,
    "invoice_id": 118,
    "status": "active",
    "promotion_code": "SAVE10",
    "service_ids": [17],
    "created_at": "2026-01-01T10:00:00+00:00",
    "whmcs_number": null,
    "reviewed_at": "2026-01-01T10:20:00+00:00",
    "services": [ { "id": 17, "product_name": "Starter Hosting", "...": "..." } ],
    "invoice": { "id": 118, "status": "paid", "total": 999, "...": "..." }
  }
}
```

The list has each order's services too; `invoice` is only on a single order.

### Products

#### `GET /api/v1/products` (`products:read`)

Filters: `product_group_id`, `active` (`1` or `0`). In the store's order, hidden products included.

#### `GET /api/v1/products/{id}` (`products:read`)

```json
{
  "data": {
    "id": 5,
    "product_group_id": 1,
    "group": "Web Hosting",
    "name": "Starter Hosting",
    "description": "10 GB of space and free SSL.",
    "active": true,
    "is_addon": false,
    "taxable": true,
    "module": "cpanel",
    "trial_days": null,
    "stock_control": false,
    "stock_quantity": null,
    "prices": [
      { "billing_cycle": "monthly", "currency": "USD", "price": 999, "setup_fee": 0 },
      { "billing_cycle": "annually", "currency": "USD", "price": 9990, "setup_fee": 0 }
    ]
  }
}
```

`prices` is only on a single product. Module settings are never included.

### Tickets

#### `GET /api/v1/tickets` (`tickets:read`)

Filters: `client_id`, `department_id`, `status` (`open`, `answered`, `customer_reply`, `on_hold`, `closed`). Most recently replied first. Tickets merged into another ticket are left out.

#### `GET /api/v1/tickets/{id}` (`tickets:read`)

```json
{
  "data": {
    "id": 31,
    "client_id": 42,
    "guest_email": null,
    "department_id": 1,
    "department": "Support",
    "subject": "Site down",
    "status": "answered",
    "priority": "high",
    "assigned_to": null,
    "last_reply_at": "2026-10-14T09:12:00+00:00",
    "guest_name": null,
    "merged_into_id": null,
    "created_at": "2026-10-14T08:55:00+00:00",
    "closed_at": null,
    "messages": [
      {
        "id": 70,
        "ticket_id": 31,
        "from": "client",
        "author": "Jane Doe",
        "body": "My site is down.",
        "internal": false,
        "via": null,
        "attachments": [],
        "created_at": "2026-10-14T08:55:00+00:00"
      },
      {
        "id": 71,
        "ticket_id": 31,
        "from": "staff",
        "author": "Alex Admin",
        "body": "Looking into it now.",
        "internal": false,
        "via": null,
        "attachments": [],
        "created_at": "2026-10-14T09:12:00+00:00"
      }
    ]
  }
}
```

`messages` is only on a single ticket, oldest first. `from` is `client`, `guest` (someone without an account) or `staff`. Internal staff notes are never included. Attachments are listed by name; download them in the admin area.

#### `POST /api/v1/tickets` (`tickets:write`)

Opens a ticket for a client, as if they had opened it in the portal: they're emailed its number and staff are alerted.

| Field | |
| --- | --- |
| `client_id` | Required |
| `department_id` | Required. An active department |
| `subject` | Required. Up to 250 characters |
| `body` | Required |
| `priority` | `low`, `medium` (the default), `high` or `urgent` |

```json
{ "client_id": 42, "department_id": 1, "subject": "Site down", "body": "My site is down.", "priority": "high" }
```

The answer is `201` with the ticket and its first message.

#### `POST /api/v1/tickets/{id}/replies` (`tickets:write`)

| Field | |
| --- | --- |
| `body` | Required |
| `as` | `staff` (the default) or `client` |
| `internal` | `true` for an internal staff note that isn't emailed. Staff replies only |

A staff reply is emailed to the client and marks the ticket answered. It is sent in the name of the staff member who made the key; if that staff member has been removed, make a new key. A client reply is added as the client (or the guest who opened the ticket) and puts the ticket back in the staff queue.

```json
{ "body": "Looking into it now." }
```

The answer is `201` with the new message, and the ticket after it:

```json
{
  "data": { "id": 71, "from": "staff", "author": "Alex Admin", "body": "Looking into it now.", "...": "..." },
  "ticket": { "id": 31, "status": "answered", "...": "..." }
}
```

Attachments can't be sent through the API yet.
