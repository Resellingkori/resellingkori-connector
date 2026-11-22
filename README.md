# ResellingKori Fulfillment Connector

Version 1.1.0 · License GPL-2.0-or-later (`license.txt`) · Requires WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+ (HPOS compatible)

Connect your WooCommerce shop to **resellingkori.com**. You keep selling at your own prices; ResellingKori packs and ships the order to your customer, and the status and tracking number come back to your shop by themselves.

## What it does

| You want to… | The connector… |
|---|---|
| Know which of your products ResellingKori sells | Matches them **by exact SKU first**. For products without a SKU it suggests ResellingKori products from the **product-name keywords**. You can also search by hand. Nothing is linked from a *suggestion* until you click. |
| Sell above ResellingKori's price | Sends **your selling price** with the order. ResellingKori records it as the SalesKing *set price* next to its own *original price*, so your agent dashboard and commission work exactly like an order the agent placed by hand. ResellingKori's own price always comes from ResellingKori – it can't be changed from your side. |
| Ship an order that contains products from other suppliers too | If **some** items of an order are not linked, a "send only the ResellingKori items" choice appears (and only then). The rest of the order stays yours; your order status is never changed by a partial shipment. |
| Be sure an order is never sent twice | Every send is reviewed and confirmed on screen. A double click, two staff members, a lost internet connection or a crash can never create two orders at ResellingKori. If the answer gets lost, the connector **asks** ResellingKori instead of guessing. |
| Follow the delivery | Status, courier and tracking number arrive by signed webhook (and, as a fallback, by a check every 15 minutes for orders that are still open). They show in the ResellingKori box on the order and, if you choose, change your order's status. |
| Fix a mistake | **Cancel** the order at ResellingKori while it has not been processed, correct your order and **send it again**. |
| Let your sales agent do it from the ResellingKori dashboard *(optional, new in 1.1.0)* | When you switch it on, your agent sees this shop's orders that contain ResellingKori products on a separate **ResellingKori** page in the agent dashboard – with ResellingKori's price next to yours – and can send, cancel and check them and link products from there. Your shop does the work itself and re-checks everything first; nothing is collected until you say yes (see *Privacy* and `docs/AGENT_GUIDE.md`). |

## Before you start

1. A **ResellingKori key and secret** for your shop. They belong to your sales-agent account; ask ResellingKori. You never type your agent's password into the plugin. (*Settings* has an optional **Sales agent check** where you may type the e-mail of the agent you expect; it is used only to compare with what ResellingKori reports.)
2. Orders that go to ResellingKori must be in **BDT** (৳).
3. For automatic status updates your shop should be reachable over **HTTPS**. Without it the connector still works; it checks ResellingKori regularly instead.

## Set-up in six steps

Open **WooCommerce → ResellingKori**. The set-up guide remembers where you stopped.

1. **Welcome** – what will be shared and what will not (see *Privacy* below).
2. **Connect** – paste the *Key ID* and the *Secret*. Nothing else.
3. **Verify** – the connector makes a real, signed test call and shows the **store name and the sales agent** your key belongs to. Check that it is the agent you expect.
4. **Products** – link your products (SKU matches first).
5. **Orders** – preferences: which order statuses can be sent, what happens to the order after sending, the delivery charge suggested for partial parcels, notifications.
6. **Finish.**

## Sending an order

Open the order in WooCommerce. The **ResellingKori** box shows:

* every item, whether it is linked, its ResellingKori price and your profit or loss on it;
* for orders with unlinked items: tick the items you want ResellingKori to send;
* the delivery charge (the one amount you may change), what the customer has already paid, and **the cash the courier will collect**;
* the delivery address.

Press **Review and send**, read the summary, press **Confirm and send to ResellingKori**. If anything changed in the meantime (prices, stock, the order itself) the connector tells you and asks you to review again.

If the answer from ResellingKori gets lost, the box says **"not confirmed"** and offers **Check status**. The connector also checks by itself, a few times, with growing pauses.

## Security in one paragraph

Every request is signed with HMAC-SHA256 using your secret; the secret itself is **never sent**, never put in a URL, a page, a log or an e-mail, and is stored encrypted. ResellingKori decides *which shop and which agent* a request belongs to from the key – never from anything in the request. Replays and old requests are rejected. Webhooks are verified the same way. Read `docs/SECURITY.md` for details and limits.

## Privacy

What is sent to ResellingKori when you send an order: the items (ResellingKori product id, SKU, name, quantity, **your** selling price), the recipient's **name, phone, delivery address**, the **e-mail** if the order has one, the order note, the payment figures (delivery charge, prepaid, cash to collect), and your store's order number. While connecting, ResellingKori also receives the address of your shop's webhook endpoint and, only if you typed one, the expected agent e-mail (for comparison). Product matching sends SKUs or product-name keywords. **Only if you switch the agent dashboard on**, the shop also reports a short summary of the orders that contain ResellingKori products (order number, status, items and amounts, the recipient's name and city and a partly hidden phone number – never the e-mail, street address or full phone number) and of your product list (name, SKU, price, link state) to ResellingKori; switching it off deletes it there. Nothing else leaves your shop. No telemetry, no analytics, no remote scripts or fonts. Details, retention and the personal-data exporter/eraser are in `docs/PRIVACY.md`.

## Where to find things

| | |
|---|---|
| Dashboard, setup, products, orders, logs & diagnostics, settings | WooCommerce → ResellingKori (tabs) |
| Each order | the **ResellingKori** box on the order screen |
| Logs & diagnostics (secrets are never written; e-mail addresses and phone numbers are masked as a second line of defence) | WooCommerce → ResellingKori → Logs & diagnostics |
| Agent dashboard (optional) | WooCommerce → ResellingKori → Settings → ResellingKori agent dashboard |
| Documentation | `docs/` inside the plugin: `INSTALLATION.md`, `RESELLER_SETUP.md`, `AGENT_GUIDE.md`, `TROUBLESHOOTING.md`, `SECURITY.md`, `PRIVACY.md`, `CHANGELOG.md` |

## Permissions

Administrators can do everything. Shop managers can link products, send and cancel orders and see ResellingKori information on orders; they cannot see the logs, the settings or the credentials. Access is controlled by six capabilities (`rkc_manage_connection`, `rkc_manage_credentials`, `rkc_manage_mapping`, `rkc_submit_orders`, `rkc_view_orders`, `rkc_view_logs`), so a role can be given exactly what it needs. Having WooCommerce's `manage_woocommerce` is **not** enough on its own. Switching the agent dashboard on or off needs `rkc_manage_connection`.

## Uninstalling

Deactivating keeps everything. Deleting the plugin keeps your product links and order history unless you tick **Delete all data when the plugin is deleted** in Settings. Your key and secret are always erased on delete. If you used the agent dashboard, switch it off first so that ResellingKori deletes what it holds (deleting the plugin does not contact ResellingKori).
