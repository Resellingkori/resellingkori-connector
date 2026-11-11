# ResellingKori Connector – WooCommerce Plugin

Connect your WooCommerce store to [ResellingKori](https://resellingkori.com) and fulfill orders automatically.

Install this plugin on **your store** (the reseller's site). ResellingKori handles packing, shipping, and cash collection — you keep the profit.

> **This is the Connector plugin for reseller stores.**  
> It connects your WooCommerce shop to resellingkori.com.

---

## Requirements

- WordPress 6.0 or higher
- WooCommerce (HPOS supported)
- PHP 7.4 or higher
- An active API key from ResellingKori

---

## Download

Go to the [**Releases**](https://github.com/Resellingkori/resellingkori-connector/releases) tab and download the latest `.zip` file.

---

## Installation (5 minutes)

1. **Plugins → Add New → Upload Plugin** → select the ZIP → **Activate**
2. Go to **WooCommerce → ResellingKori → Settings**
3. Paste your **API Key** and **Secret** from ResellingKori → **Save** → **Test Connection**
4. **Product Mapping** tab → **Auto-match all by SKU**, then map the rest manually
5. Open any order → **ResellingKori** box → **Send to ResellingKori**

---

## Product Matching

| Method | How it works |
|---|---|
| **Exact SKU** | Automatically matched. Flagged for review if names look unrelated. |
| **Keyword suggestions** | Searches ResellingKori by product name keywords, ranked by similarity (High/Medium/Low). Never auto-confirmed. |
| **Manual** | Enter a ResellingKori product ID or SKU directly. |

Variations are mapped one-to-one; matching variations are linked automatically after the first one.

---

## Prices & Money

- Your customer's price (after discounts) is sent as the **set price** to ResellingKori
- Your profit = your selling price − ResellingKori's catalogue price
- Prices below ResellingKori's price are refused with a clear error
- For cash-on-delivery: courier collects the full order total; your delivery charge is sent as a delivery line

---

## Mixed Orders (some items from ResellingKori, some not)

| Items | What happens |
|---|---|
| All mapped to ResellingKori | One **Send to ResellingKori** button |
| Some mapped, some not | Option to send only ResellingKori items — tick what to send |
| None mapped | Blocked until at least one product is mapped |

---

## Status Sync

- **Push (webhook):** ResellingKori sends signed events to `/wp-json/rkc/v1/webhook`
- **Pull (fallback):** 15-minute cron polls open orders
- **Manual:** "Refresh status" button on the order page

Statuses: `pending → processing → shipped → delivered → returned → cancelled`  
Map each to a WooCommerce status in Settings (default: only `delivered → completed`).

Tracking number, courier, and tracking URL appear in the order box and as an order note.

---

## Developer Hooks

```php
// Modify the order payload before sending
apply_filters( 'rkc_order_payload', $payload, $order, $lines );

// Add custom send rules (return WP_Error to block)
apply_filters( 'rkc_can_send_order', true, $order, $lines );

// Fired after order is submitted
do_action( 'rkc_order_submitted', $order, $rk_order, $payload );

// Fired when remote status is applied
do_action( 'rkc_remote_status_applied', $order, $new, $old, $tracking, $source );

// Fired when a product is mapped
do_action( 'rkc_product_mapped', $woo_id, $rk_item, $rk_variation_id, $source );
```

---

## Security

- Requests signed with HMAC-SHA256 (`X-RK-Key`, `X-RK-Timestamp`, `X-RK-Signature`)
- Secret is never transmitted
- Timestamps older than 5 minutes are rejected
- All admin actions require `manage_woocommerce` capability and a nonce
- Remote data rendered with `textContent` only (no XSS)

---

## Troubleshooting

| Error | Fix |
|---|---|
| `HTTP 403/404` on resellingkori.com | A WAF/security plugin is blocking `/wp-json/rk/v1/` — allow it |
| "Request timestamp outside allowed window" | Fix your server clock (NTP) |
| "No sales agent linked to this API key" | Ask ResellingKori to link your agent account |
| "Selling price below ResellingKori price" | Raise the price on your store |
| Statuses not updating | Webhook URL must be publicly reachable; enable **Debug logging** in Settings |

---

## Not using WooCommerce?

Use one of the code SDKs to build a custom integration:

| Language | Repository |
|---|---|
| PHP | [resellingkori-php](https://github.com/Resellingkori/resellingkori-php) |
| Node.js | [resellingkori-nodejs](https://github.com/Resellingkori/resellingkori-nodejs) |
| Python | [resellingkori-python](https://github.com/Resellingkori/resellingkori-python) |

---

## Documentation

- [Partner Docs](https://github.com/Resellingkori/partner-docs)
- [Getting Started](https://github.com/Resellingkori/partner-docs/blob/main/GETTING_STARTED.md)
- [API Guide](https://github.com/Resellingkori/partner-docs/blob/main/PARTNER_API_GUIDE.md)

---

## License

GPL-2.0-or-later
