# Changelog

This plug-in follows [Semantic Versioning](https://semver.org/). **Connector 1.x works with ResellingKori's service 1.x**; a change that would break that (the signing scheme, the order format or the webhook format) always gets a new major version.

---

## 1.1.1 – 2026-11-22

The agent dashboard's order cards now also carry the recipient's **complete phone number and delivery address** (street lines), so the agent can see who to deliver to. The e-mail address is still never included. The consent text and the suggested privacy-policy paragraph were updated to say so. Update the add-on on resellingkori.com (1.0.2) first.

---

## 1.1.0 – 2026-11-04

Adds the optional **ResellingKori agent dashboard**. Everything in 1.0.0 works as before; nothing new is collected or sent until an administrator says yes.

### Connector 1.1.0 (resellers)

**New: the agent dashboard (optional)**
* Your sales agent's dashboard at ResellingKori gets a separate **ResellingKori** page (visible only to agents whose shop is connected) with three tabs: **Orders** (the shop's orders that contain ResellingKori products, with "ResellingKori has this product", ResellingKori's price next to your selling price and the profit), **Products** (link, unlink, confirm, search, exact-SKU link, match all by SKU) and an **Overview**.
* From there the agent can **send** an order (with the same review as on your order screen, including choosing the items of a mixed order and the delivery charge), **cancel** it at ResellingKori, **check its status**, or hide it in the dashboard only. The agent no longer has to open WooCommerce for each order.
* **Your shop stays in charge.** The shop reports a short summary by itself and **asks** ResellingKori for instructions in the same signed request; ResellingKori never connects into the shop. Every instruction is checked again by the shop before anything happens (order unchanged, amounts, items and ResellingKori's prices exactly as confirmed, order allowed and not sent yet, instruction not expired). Answers and instructions must carry ResellingKori's signature.
* The agent's browser can nudge the shop (a signed "please look now" request) so that a button press is usually carried out within seconds; otherwise the shop looks about once a minute through WordPress' scheduler (a real cron job is recommended).
* *Settings → ResellingKori agent dashboard*: a switch for the whole feature, a second switch to let the dashboard act or only look, **Sync now**, and a status line that says in plain words what is happening. The *Dashboard* tab repeats the state.
* The sync, instructions and results are logged in *Logs & diagnostics* (without personal data).

**Privacy and consent**
* After an update from 1.0.0 the feature is **undecided and nothing is collected or sent**; administrators see a notice with *Turn on* and *Not now*. In the setup guide (step 5) the choice is **pre-ticked** with the full explanation above it and takes effect only when the step is saved; untick it to decline.
* Only a summary is shared: order number, date, status, items and amounts, the recipient's **name, city and state** and a **partly hidden phone number**; for products: name, SKU, price, picture address and link state. **Never** the e-mail address, street address, complete phone number or customer note (`PRIVACY.md` lists every field).
* Switching the dashboard off, disconnecting the shop, or erasing a customer's data with WordPress' eraser makes ResellingKori **delete** the corresponding data. Deactivating or deleting the plug-in does not contact ResellingKori: switch the dashboard off first.
* The suggested privacy-policy text and the personal-data exporter mention the dashboard.

**Changed**
* Setup guide step 5 ("Orders") contains the dashboard choice. The *Dashboard* tab shows the dashboard state.
* Two new tables (`rkc_ws_items`, `rkc_ws_cmd`; database version 2) and a one-minute scheduled task `rkc_ws_tick`; uninstall removes them (tables only with *Delete all plugin data*).
* Documentation: `PRIVACY.md` no longer points to a part that is not in this package; new `AGENT_GUIDE.md` for the sales agent.

**Fixed while preparing 1.1.0 (found by the test suites before release)**
* The signature on ResellingKori's answers is now really verified before any instruction in an answer is used (an early build forwarded it incorrectly, so a wrong signature was not rejected).
* The first report of a shop's orders was sent twice.
* After linking, unlinking or confirming a product, the dashboard kept showing the old state until the next full report; it is now updated at once.
* After the shop stopped in the middle of a send, the dashboard could still offer **Send** for an order whose outcome was unknown; for such an order it now offers only **Check status**, as the order screen does, and the shop refuses to send it a second time.
* A "please look now" request that arrived during the short pause after the previous one could be lost; it is now remembered and handled.

**Compatibility.** Works with ResellingKori's service 1.x. The dashboard needs the *ResellingKori Agent Workspace* add-on on ResellingKori's side: until ResellingKori has switched it on, the plug-in says so in *Settings* and asks again about once an hour; nothing else is affected. Upgrade by installing the ZIP over 1.0.0; settings, links and history are kept.

### Known limits

* Tested on SQLite with PHP 8.3 / WordPress 7.1 / WooCommerce 11.2 in both order-storage modes; **not** on MySQL/MariaDB, **not** on PHP 7.4 (only a syntax-token check), **not** against a live SalesKing, real couriers, a real firewall or the public internet.
* On a shop that nobody visits and that has no real cron job, WordPress may run scheduled tasks late, and an instruction can then expire (nothing happens; the agent presses the button again).
* No independent penetration test has been performed.

---

## 1.0.0 – 2026-11-02

First release that is meant for production. It replaces 0.2.0 and **cannot be mixed with 0.2.0 on ResellingKori's side**: ResellingKori upgrades its side at the same time and tells you the day. This page lists what changed.

### Connector 1.0.0 (resellers)

**Security**
* The address of ResellingKori is fixed (`https://resellingkori.com`) and is no longer a setting. A developer override exists only when a constant (`RKC_DEV_API_BASE`) is defined **and** the WordPress environment type is not `production`.
* The secret is encrypted before it is saved (libsodium; optional `RKC_ENCRYPTION_KEY`), shown only while typing, never printed into HTML, JavaScript, AJAX answers, logs or exports. Migrated v0.2.0 secrets are moved into encrypted storage and the plain copy is deleted.
* Requests are signed with RK2 (method, route, sorted query, body hash, timestamp, one-time number). The clock is corrected automatically from the server's answer.
* Dedicated capabilities (`rkc_manage_connection`, `rkc_manage_credentials`, `rkc_manage_mapping`, `rkc_submit_orders`, `rkc_view_orders`, `rkc_view_logs`) replace "anyone who can manage WooCommerce". Every action checks a nonce and a capability on the server.
* The webhook receiver verifies signature, timestamp, one-time number and event id; duplicates and older events are dropped; failures from one address are limited.
* Logs and exports redact secrets, signatures and (best effort) e-mail addresses and phone numbers; CSV export neutralises spreadsheet formulas; exports need `rkc_view_logs` and a nonce.

**Orders**
* **The shop's selling price travels with the order** (as the sales agent's *set price*; ResellingKori's own price is recorded as the *original price*), together with explicit financial figures: items, delivery, order total, already paid, **cash the courier collects**. The review panel shows ResellingKori's price and your profit or loss for every line.
* Review-then-confirm: nothing is sent without a person pressing *Confirm and send to ResellingKori*; what is sent is exactly what was shown (a changed order must be reviewed again).
* **Mixed orders:** when an order contains products that are not ResellingKori's, the person ticks the items to send. A suggested delivery charge for that parcel is configurable. Such orders never have their own status changed by ResellingKori.
* Each attempt is stored as an immutable snapshot; sending is idempotent. If the answer is lost the plug-in asks ResellingKori what it holds before it ever sends again (*Check status*), automatically and by hand.
* **Cancel at ResellingKori** while the order has not been processed; the order can then be sent again as a new attempt.
* Status, tracking number and link, courier and cash to collect appear in the order box and in the order list; configurable mapping from ResellingKori's statuses to the shop's statuses; a 15-minute fallback check when live updates are unavailable.
* Optional **Sales agent check** (expected agent e-mail; comparison only) that pauses ordering if the key belongs to a different agent.

**Setup and use**
* Six-step setup guide (Welcome, Connect, Verify, Products, Orders, Finish); Dashboard; Products, Orders, Logs & diagnostics and Settings screens, usable on a phone.
* Product matching: SKU first (exact), similar names second (never linked without the person's choice), your own search third; a safety check for same-SKU-different-name; variations; "needs review" and "unavailable" states; bulk SKU matching and availability check.
* Diagnostics you can copy and send to support (contains no secret).
* Compatible with High-Performance Order Storage; translation-ready (text domain `resellingkori-connector`, template in `languages/`).
* Privacy: WordPress personal-data exporter and eraser, suggested privacy-policy text, no telemetry; an order whose personal data has been erased is never sent.
* Uninstall: credentials are always erased; other data only when asked.

**Migration from 0.2.0**: credentials moved to encrypted storage (connection marked *unverified* until verified once), the expected agent e-mail kept as *Sales agent check*, orders already sent are recognised, a custom API address is ignored.

**Removed**: the *API address* setting (see Security); the plain-text secret in the settings option.


### Fixed while preparing 1.0.0 (found by the test suites before release)

* WordPress calls a REST permission callback a second time to build the `Allow` header; both plug-ins now remember the result of the first check for the request (without it the second call looked like a replay).
* A webhook's signature is bound to the exact query that was received, not to a rebuilt one.
* When an order is cancelled and sent again, state belonging to the earlier remote order is forgotten, and events about the earlier attempt are ignored.
* A tracking number is never erased by a later update that has none.
* An order whose personal data was erased can no longer be re-sent from the stored snapshot.
* Log redaction now covers e-mail addresses and phone numbers as well as secrets, in both plug-ins.
* User-visible texts made consistent with the buttons ("Check status") and the settings (failed sign-ins are counted per address **per 10 minutes**).

### Known limits

* Tested on SQLite with PHP 8.3 / WordPress 7.1 / WooCommerce 11.2; **not** on MySQL/MariaDB, **not** on PHP 7.4 (only a syntax-token check), **not** against a live SalesKing, real couriers or the public internet.
* No independent penetration test has been performed.

---

## 0.2.0 (previous)

Initial connector and API with HMAC-signed requests, SKU/name product matching, order submission with the shop's selling price as set price, partial (mixed) orders and signed status webhooks. Superseded by 1.0.0.
