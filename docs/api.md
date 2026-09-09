# API reference

Every path below is relative to a subsite's REST root, `http://<host>/<subsite>/wp-json`.
In the dev stack the sample subsite's root is `http://localhost:8080/customer-one/wp-json`.

## Authentication

Send the API key in a header:

```
Authorization: Bearer thl_<64 hex chars>
```

or, for a client that cannot set `Authorization`:

```
X-Templ-Api-Key: thl_<64 hex chars>
```

Keys are per subsite: a key minted on `customer-one` returns `401` everywhere else.
A key in the query string is refused; it would leak into logs and history.

Common auth errors, all HTTP `401`:

| Code | When |
| --- | --- |
| `templ_headless_missing_key` | No key header on a protected route |
| `templ_headless_invalid_key` | The key is unknown on this subsite |
| `templ_headless_revoked_key` | The key exists but has been revoked |

## Health

### `GET /templ-headless/v1/ping`

Confirms a key works, with no side effects. Key required.

**200**

```json
{ "ok": true, "site_id": 2, "site_url": "http://localhost:8080/customer-one/" }
```

## Contact form

Namespace `templ-contact-form/v1`.

### `POST /submissions`

Records a submission. Key required.

Request:

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "subject": "Hello",
  "message": "A message.",
  "website": ""
}
```

| Field | Required | Rules |
| --- | --- | --- |
| `name` | yes | 1-100 characters |
| `email` | yes | a valid email |
| `subject` | no | up to 200 characters |
| `message` | yes | 1-5000 characters |
| `website` | no | honeypot; must be empty |

**201**

```json
{ "id": 42, "created_at": "2026-01-01 12:00:00" }
```

A filled `website` also returns `201`, with `"id": 0`, and stores nothing: the honeypot answers a bot with a fake success.

Errors:

| Status | Code | When |
| --- | --- | --- |
| 400 | `rest_missing_callback_param` | A required field is absent |
| 400 | `templ_contact_form_email_invalid` and siblings | A field fails validation; all field errors are returned together |
| 429 | `templ_contact_form_rate_limited` | More than 5 submissions from one sender in an hour |

### `GET /submissions`

Lists submissions, newest first. Key required.

| Query | Default | Notes |
| --- | --- | --- |
| `page` | 1 | |
| `per_page` | 20 | max 100 |
| `search` | - | free-text over the stored fields |

**200** - an array of submissions, plus `X-WP-Total` and `X-WP-TotalPages` headers.

```json
[
  { "id": 42, "name": "Ada Lovelace", "email": "ada@example.com", "subject": "Hello", "message": "A message.", "created_at": "2026-01-01 12:00:00" }
]
```

### `GET /submissions/<id>`

One submission. Key required. `404 templ_contact_form_not_found` when it does not exist on this subsite.

### `DELETE /submissions/<id>`

Deletes one submission. Key required.

**200** `{ "deleted": true }`

## Newsletter

Namespace `templ-newsletter/v1`.

### `POST /subscribers`

Subscribes an email. Key required. Idempotent: re-subscribing returns the same record.

Request:

```json
{ "email": "reader@example.com", "name": "Reader", "website": "" }
```

| Field | Required | Rules |
| --- | --- | --- |
| `email` | yes | a valid email; lowercased and trimmed |
| `name` | no | up to 100 characters |
| `website` | no | honeypot; must be empty |

**201** for a new subscriber, **200** for one re-activated:

```json
{ "id": 7, "status": "templ_subscribed" }
```

A filled `website` returns `201 { "ok": true }` and stores nothing.
A bad email returns `400 templ_newsletter_email_invalid`.

### `GET /subscribers`

Lists subscribers, newest first. Key required.

| Query | Default | Notes |
| --- | --- | --- |
| `page` | 1 | |
| `per_page` | 20 | max 100 |
| `status` | - | `templ_subscribed` or `templ_unsubscribed` |

**200** - an array plus `X-WP-Total` and `X-WP-TotalPages`.

```json
[
  { "id": 7, "email": "reader@example.com", "name": "Reader", "status": "templ_subscribed", "subscribed_at": "2026-01-01 12:00:00", "unsubscribed_at": "" }
]
```

### `POST /subscribers/unsubscribe`

Unsubscribes by email, for a frontend acting on its own list. Key required.

Request `{ "email": "reader@example.com" }`.

**200** `{ "id": 7, "status": "templ_unsubscribed" }`, or `404 templ_newsletter_not_found`.

### `DELETE /subscribers/<id>`

Hard-deletes a subscriber, for a GDPR erasure request. Key required.

**200** `{ "deleted": true }`, or `404 templ_newsletter_not_found`.

### `GET /unsubscribe?token=<token>`

**The one endpoint that needs no key.**
Opened from an email client, which cannot carry a key.
The token is 256 bits, single-purpose, and only ever unsubscribes its own subscriber.

**200** `{ "unsubscribed": true, "email": "reader@example.com" }`

A missing or unknown token returns `404 templ_newsletter_invalid_token` - never a 500.

## Frontend integration

The key must stay on a server.
A headless frontend proxies submissions through its own route handler, which holds the key and forwards the request.
A Next.js App Router example:

```ts
// app/api/contact/route.ts
export async function POST(request: Request) {
  const body = await request.json();

  const upstream = await fetch(
    "https://cms.example.com/customer-one/wp-json/templ-contact-form/v1/submissions",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        // Server-side only. Never shipped to the browser.
        Authorization: `Bearer ${process.env.TEMPL_API_KEY}`,
      },
      body: JSON.stringify(body),
    },
  );

  return new Response(await upstream.text(), {
    status: upstream.status,
    headers: { "Content-Type": "application/json" },
  });
}
```

The browser calls `/api/contact` on its own origin; the key never leaves the server.
