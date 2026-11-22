# Privacy – what data moves, where it is kept, and how to export or erase it

Applies to **ResellingKori Fulfillment Connector 1.1.0**, installed on your shop.
The plug-in contains no analytics, advertising identifiers, telemetry, error reporting to a third party, update pings, remote fonts or remote scripts.
This text is technical documentation, not legal advice; a shop remains responsible for its own privacy policy.

---

## Part A – the Connector (reseller shop)

### A.1 Roles

The reseller's shop is the **controller** of its customers' data. ResellingKori receives order data to **fulfil** it (pack, ship, collect cash on delivery), acting for the shop and for itself as the supplier. If you switch on the **agent dashboard** (optional, A.2a), ResellingKori also holds a reduced copy of some orders and of your product list so that your sales agent can work with them.

### A.2 What is sent to ResellingKori, and when

All requests go to **`https://resellingkori.com`** (a fixed address – it cannot be changed from a settings screen), over HTTPS, signed with HMAC-SHA256. The secret itself is never sent.

| When | What is sent | Why |
|---|---|---|
| Every request | The key id (public), a signature, a timestamp and a one-time number; the server's IP address (inherent to any request); a user-agent line `ResellingKori-Connector/<version>; WordPress/<version>` | Authentication and replay protection; compatibility diagnosis |
| Setup guide, *Test connection*, settings save | The address of this shop's webhook endpoint (`…/wp-json/rkc/v1/webhook`); the **optional** "expected agent e-mail" only if the owner typed one (used for comparison only) | Live status updates; warns when the key belongs to a different sales agent |
| Matching products | SKUs of the shop's products (in batches) and **keywords from product names** for products without a SKU or when a person searches; ResellingKori product ids | Finding the same product in ResellingKori's catalogue |
| A person presses **Send to ResellingKori** (on the order screen, or in the agent dashboard if you switched that on) | The order: the shop's order number and a reference; for each line the ResellingKori product id, SKU, name, quantity and **the shop's selling price**; the payment figures (delivery charge, amount already paid, cash to collect); a "partial order" flag; **the recipient's name, phone number, delivery address**, the **e-mail address** if the order has one, and the customer's note (up to 500 characters) | To pack and deliver the order and collect the cash |
| After sending, in the background | ResellingKori order ids (a fallback status check, run every 15 minutes, for orders that are still open and have had no recent update); the shop's reference (to ask whether an order arrived, or to cancel it) | Status, tracking, recovery after a lost answer |
| **Only if the agent dashboard is switched on** | See A.2a | The sales agent works with these orders and products in the ResellingKori dashboard |

**Not sent:** orders that have no ResellingKori product, the shop's other customers, passwords, payment-gateway data, the full product catalogue (apart from the list in A.2a), analytics, anything in the background about visitors. The recipient's **e-mail address, street address and complete phone number** leave the shop only when a person sends an order, never as part of the dashboard summaries.

### A.2a The agent dashboard (optional – off until the shop owner switches it on)

**Nothing in this section happens unless an administrator switched on** *Settings → ResellingKori agent dashboard → "Show this shop's ResellingKori orders and products in my ResellingKori agent dashboard"* (or pressed *Turn on* in the notice, or left the box ticked in the setup guide). After an update from 1.0.0 the choice is *undecided*, and while it is undecided **nothing is collected or sent**.

When it is on, about once a minute (and straight away after a change) the shop sends ResellingKori:

| Item | Fields |
|---|---|
| **A card for each order that contains a ResellingKori product** (also unsent ones; cancelled, failed, refunded or trashed orders that were never sent are left out) | The shop's order id and number, date, status, whether it is cash on delivery, currency; the recipient's **name, city and state**, and a **partly hidden phone number** (for example `017•••••678`: the first three and last three digits only); **every item of the order** (name, SKU, quantity, your selling price, amount paid, picture address, and the ResellingKori product it is linked to); delivery charge and order total; whether the order can be sent and, if not, why; once sent: the ResellingKori order number and status, courier, tracking number and link, any error text; the address of the order in your WordPress admin |
| **A card for each published or private product** (simple products and variations) | The product id, name, SKU, your price, picture address, and which ResellingKori product it is linked to (if any) |
| **The shop's state** | Whether the connection is verified, whether the sales agent matches, the currency, whether High-Performance Order Storage is on, whether the dashboard may send, cancel and link, and the WordPress, WooCommerce and PHP version numbers |
| **Results of instructions** | For each instruction the dashboard gave (send, cancel, check, link …): the outcome and a short message |

**Never sent as part of the dashboard:** the recipient's e-mail address, street address, complete phone number, the customer's note, billing details, payment-gateway data, user accounts.

The shop **asks** ResellingKori for instructions in the same request; ResellingKori never connects into the shop. Your agent's browser may also send the shop a signed "please look now" request (a GET to `…/wp-json/rkc/v1/wake`); it carries no data.

**How long ResellingKori keeps it.** By default a finished order (delivered, cancelled, returned) is deleted 90 days after its last change, an unfinished one (not sent yet, or on its way) 60 days after its last change, every order card of a shop that has stopped reporting after 120 days, a product card 180 days after the shop last reported it, and the record of which agent pressed which button 180 days after the action. These are ResellingKori's current settings, which ResellingKori can change; ask ResellingKori for its current figures and for its own privacy information. **Switching the dashboard off, disconnecting the shop, or erasing a customer's data deletes the corresponding cards right away** (A.5, A.6).

**Who sees it.** The sales agent linked to your key, in the agent dashboard. ResellingKori's administrators see, in their admin screen, which shops report and how many orders and products they hold – not names, phone numbers or items; the cards themselves are stored in ResellingKori's database like all other data ResellingKori holds.

### A.3 What the Connector stores on the shop

| Data | Where | Retention |
|---|---|---|
| Key id and the **encrypted** secret | WordPress option (autoload off) | Until the plug-in is deleted (always erased on delete) |
| Product links (which shop product = which ResellingKori product) | Product meta | Until unlinked / (optionally) plug-in deletion |
| A **copy of each order submission** (an immutable snapshot of what was sent, including the recipient's name, phone, address, e-mail and note), its state and ResellingKori's answer | Table `rkc_submissions` | Kept so that duplicates can be prevented and a lost answer recovered; removed by plug-in deletion only if *Delete all data* is ticked, or blanked by the privacy eraser (A.5) |
| Received webhook events (event id, type, sequence, outcome – no personal data) and one-time numbers (nonces) | Tables `rkc_events`, `rkc_nonces` | Events 30 days, nonces a few minutes; expired rows are removed by a daily job |
| Order information from ResellingKori (status, tracking number and link, courier, ResellingKori order number, error text) | Order meta | With the order |
| Diagnostic log | Table `rkc_log` | **30 days by default** (setting: 1–365 days), cleaned daily |
| Agent dashboard: which orders and products still have to be reported (the id, a short fingerprint of the last version reported, and flags – **no names, phone numbers or addresses**) | Table `rkc_ws_items` | While the dashboard is switched on; emptied when it is switched off |
| Agent dashboard: the instructions received from the dashboard (the instruction type, the order or product id, the amounts the agent confirmed, the outcome) | Table `rkc_ws_cmd` | Finished instructions are removed after 3 days; unfinished ones after 1 day |
| Agent dashboard: the sync state (last success, back-off, counters) | WordPress option `rkc_ws_state` | While the plug-in is installed; erased on delete |

The diagnostic log **never** contains the secret (secrets, signatures, tokens, nonces and authorisation values are redacted before anything is stored). The Connector does not write customer details into log messages; as a second line of defence, e-mail addresses and telephone numbers that do appear in a message (Bangladeshi mobile numbers or numbers written with a leading `+`) are masked on a best-effort basis. The export ("CSV") contains the redacted rows only and neutralises spreadsheet formulas.

### A.4 Policy text

*WordPress → Settings → Privacy → Policy Guide* gains a suggested paragraph from the Connector ("ResellingKori orders") that describes the sharing with the supplier, including the agent dashboard. Review and adapt it.

### A.5 Export and erasure (WordPress Tools → Export / Erase Personal Data)

* **Export** – lists, for the e-mail address entered, every submission the Connector sent for that customer's orders: order number, ResellingKori reference, state, date and the recipient details in the stored copy, and whether the order is shown in the agent dashboard.
* **Erase** – replaces the recipient's details in the stored copy with placeholders and removes the note, and marks the copy as erased. The rows themselves (state, ids, amounts) stay so duplicate protection and accounting keep working. **An erased submission is never sent again** (the Connector refuses it with a clear message). WooCommerce's own order eraser handles the order itself. If the agent dashboard is on, the card of an erased order is **deleted at ResellingKori** with the next report (within about a minute) and is not reported again.
* **ResellingKori keeps its own record** of orders already sent; the shop cannot erase it. Ask ResellingKori to erase an end customer's data on request through your ResellingKori support contact.

### A.6 Uninstall

Deactivating changes nothing. Deleting the plug-in always erases the credentials, scheduled jobs, the agent-dashboard state and temporary data; it keeps the submission records, product links, settings and logs **unless** *Settings → Delete all data when the plugin is deleted* is ticked, in which case everything the Connector created is removed (tables, product meta, order meta, capabilities, options).

**The agent dashboard's data at ResellingKori:** *switching the dashboard off* (Settings) or *Disconnect* tells ResellingKori to delete everything it holds from your shop. *Deactivating or deleting the plug-in does not contact ResellingKori* – so, if you used the dashboard, **switch it off first**. Otherwise the cards simply expire there after the retention period in A.2a.
