# Troubleshooting

Start with the diagnostics. They never contain a secret and are safe to send to support.

Open *WooCommerce → ResellingKori → Logs & diagnostics → Copy diagnostics* and send the text to support.

## Part 1 – On the reseller's shop (Connector)

### 1.1 Connecting

| What you see | Likely cause | What to do |
|---|---|---|
| "The Key ID looks wrong. It starts with "rkk_" …" | Extra space, or the wrong value pasted | Copy the Key ID again exactly as ResellingKori showed it |
| "The secret looks wrong. Copy it again exactly … (letters, numbers, - and _ only, no spaces)" | Same | Copy it again; never type it from a screenshot |
| "This server cannot encrypt the secret (the PHP sodium extension is missing)" | Hosting without sodium | Ask your host to enable the PHP **sodium** extension (standard since PHP 7.2) |
| *Verify connection* → "Invalid credentials." | Key ID and secret do not belong together, or the key was replaced | Enter both again. If it still fails, ask ResellingKori whether the key is active |
| "… key was revoked" / "… store is disabled" | ResellingKori revoked the key or switched your store off | Contact ResellingKori. If you were given a new key, use *Settings → Connection → Replace the key and secret* |
| "Update the ResellingKori Connector plugin to 1.0.0 or newer" | An old connector (0.x) is talking to the new ResellingKori | Install the v1.0.0 connector (*Plugins → Add New → Upload → Replace current with uploaded*) |
| Message about the clock / "request expired" | The date or time of your server is wrong | The plug-in corrects small differences by itself (see *Clock correction* in the diagnostics). Ask your host to fix the server time (NTP) if it keeps happening |
| "Too many invalid requests" / HTTP 429 | Repeated wrong keys from your server's address, or too many requests | Wait five minutes; fix the credentials first |
| "The sales agent linked to your key is not the agent you expect" | You typed an expected agent e-mail under *Sales agent check* and ResellingKori reports another agent | Orders are paused on purpose so that commission does not go to the wrong person. Check with ResellingKori; correct or clear the expected e-mail |
| "No valid sales agent is linked to your ResellingKori key" | ResellingKori has not linked your agent account | Ask ResellingKori to link the agent; nothing you can change on the shop |
| Connection works but outgoing requests fail with a timeout/SSL error | Your host blocks outgoing HTTPS or has old CA certificates | Ask your host to allow outgoing HTTPS to `resellingkori.com` and to update CA certificates |

### 1.2 "Live updates are not active"

Statuses still arrive: every 15 minutes the plug-in asks ResellingKori about orders that are still open, and *Refresh status* asks immediately. Live updates need ResellingKori to be able to **POST** to your shop. If you want them:

1. Your shop must use **HTTPS** with a valid certificate (no self-signed certificate, no redirect from the webhook address).
2. The address `https://<your-shop>/wp-json/rkc/v1/webhook` (shown under *Settings → Connection → Webhook address*) must be reachable from the internet **for POST requests**. Test from any computer: `curl -i -X POST https://<your-shop>/wp-json/rkc/v1/webhook` – the right answer is **HTTP 401** with a short JSON message (*Unauthorized*): it proves the request reaches the plug-in, which refuses it because it is unsigned. A 404, 403, a redirect or an HTML page means something in front of WordPress is in the way. (Typing the address in a browser sends a GET request and always shows 404 *No route was found*; that is normal and proves nothing.)
3. Security plug-ins that **disable the REST API for visitors** ("Disable REST API", some firewall/hardening plug-ins) must allow the `rkc/v1` namespace.
4. Firewalls and bot protection (for example a CDN's "bot fight" or "under attack" mode, ModSecurity rules, Basic-Auth on a staging site) must let server-to-server `POST` requests to that address through. Ask the host to allow them for `resellingkori.com`'s server.
5. Plain permalinks (*Settings → Permalinks → Plain*) work, but some hardening rules block the `?rest_route=` form. Pick **Post name** if possible.
6. Then press *Settings → Test connection*; the webhook is verified again.

The page *Logs & diagnostics* shows *Webhook* (`verified` or `pending`/`none`) and *Last webhook received*.

### 1.3 Products

| Problem | Cause / fix |
|---|---|
| "This product has no SKU, so there is nothing to match exactly" | Add the SKU to your product, or link it by name from the *Products* tab |
| "No ResellingKori product has this SKU" | The SKU differs from ResellingKori's. Use the similar-names column or search by name/ID |
| "The SKU matches but the names look different" | Check carefully: the same SKU can belong to a different product at another supplier. Use it only if it is the same item |
| "Link the individual variations of a variable product, not the parent" / "That ResellingKori product has variations. Select the exact variation" | Link each variation to the matching ResellingKori variation |
| "Link needs review" | A link made by the plug-in with low confidence. Open the product list → *Review* and confirm or change it; until then the link is not used for orders |
| "unavailable" | The linked product is out of stock or no longer exists at ResellingKori. Change the link or wait |

### 1.4 Sending an order

The box on the order says why the *Review and send* button is missing or disabled:

| Message | What to do |
|---|---|
| "Orders with status "…" cannot be sent. Change the allowed statuses in Settings if needed." | Move the order to an allowed status or change *Settings → Which orders may be sent* |
| "Not connected with ResellingKori: <products>. Map at least one product first." | None of the order's items is linked. Link at least one product |
| "ResellingKori only accepts orders in BDT (this order is in …)" | The store currency must be BDT |
| "Customer information missing: …" | Complete the recipient name, phone (10–15 digits) and address on the order |
| "This order has already been submitted to ResellingKori." | It is there. Use *Refresh status*. To send a corrected version, cancel it first |
| "Someone else is sending this order right now. Reload the page." | Wait a moment and reload |
| "The order or the amounts changed after you reviewed them. Review the order again, then confirm." | Press *Review and send* again |
| "The review has expired or the order changed" | Same |
| "ResellingKori price changed from … to …" | Review again and confirm the new figures |
| "<product>: you need 3, ResellingKori has 1" | Reduce the quantity or wait for stock |
| "The recipient details of this order were erased at the customer's request, so it can no longer be sent." | The customer's personal data was erased with WordPress' privacy tool. Create a new order |
| "ResellingKori did not confirm this order, so it may or may not have arrived." | **Do not send again.** Press *Check status* |
| "Automatic checks have stopped. Press "Check status" to try again." | Press it. It is safe: nothing is sent twice |
| "ResellingKori did not confirm the cancellation. Use "Check status"…" | Same, then try cancelling again |

### 1.5 Status and tracking do not update

1. Look at *Last update* in the order box and *Last webhook received* / *Next status check* in the diagnostics.
2. Press **Refresh status**.
3. Mixed orders (only some items sent) never change your order's status – by design.
4. By default only **Delivered** changes your order (to *Completed*). Other statuses are shown as a badge. Change the mapping under *Settings → Status updates from ResellingKori*.
5. The 15-minute check uses WordPress' scheduler (WP-Cron). On a very quiet site WP-Cron may not run; ask your host for a real cron job (`wp cron event run --due-now`, every minute or every five minutes).
6. After an order is **Delivered**, **Cancelled** or **Returned** it is final: later, older messages are ignored.

### 1.6 I cannot see the ResellingKori menu or box

* Administrators see everything. Shop managers can link products, view and send orders.
* A custom role needs the matching capability for each area (`rkc_view_orders`, `rkc_submit_orders`, `rkc_manage_mapping`, `rkc_manage_connection`, `rkc_manage_credentials`, `rkc_view_logs`), and WooCommerce's own `edit_shop_orders` to open and send orders. Switching the agent dashboard on or off needs `rkc_manage_connection`.
* People who cannot open WooCommerce's menu see the page as a top-level **ResellingKori** item.
* The ResellingKori box is on the order edit screen (both the classic and the High-Performance Order Storage screens). If it is hidden, open *Screen Options* at the top of the order screen and tick it.

### 1.7 After removing the plug-in

Credentials and the agent-dashboard state are erased on deletion. Orders, links and history stay unless *Delete all plugin data …* was ticked. Reinstalling restores them; you enter the Key ID and Secret again. Deleting the plug-in does **not** tell ResellingKori to remove what the agent dashboard holds: if you used the dashboard, switch it off first (*Settings → ResellingKori agent dashboard*), or ask ResellingKori to remove your shop's data.


### 1.8 The agent dashboard

Where to look: *WooCommerce → ResellingKori → Settings → ResellingKori agent dashboard* shows a badge (**Working**, **Waiting**, **Problem**, **Off**) and one sentence; the *Dashboard* tab repeats it; *Logs & diagnostics* has entries that start with "Agent dashboard". Pressing **Sync now** tries again immediately and ignores the waiting times below.

**What the card says**

| The card says | What it means | What to do |
|---|---|---|
| **Off** – "Not switched on yet. Nothing is sent to ResellingKori until you switch it on." | You have not decided. Nothing is collected or sent | Tick the box and save (or press *Turn on* in the notice) if you want the dashboard |
| **Off** – "Switched off. Nothing is sent to ResellingKori." | You switched it off | Tick the box if you want it again |
| **Waiting** – "Waiting for the connection to ResellingKori to be verified." | The key is not verified | *Settings → Connection → Test connection* |
| **Waiting** – "Switched on. The first sync happens within a minute." | The first report has not run yet | Wait a minute. If it is still there after five minutes WordPress' scheduler is not running: press **Sync now**, and ask your host for a real cron job (`INSTALLATION.md`) |
| **Waiting** – "ResellingKori has not switched the agent dashboard on for stores yet. Nothing is shown there until it does; this plugin keeps checking." | ResellingKori's server does not have the dashboard yet (it answers that the address does not exist). Your shop is fine | Nothing to fix on your shop. The shop asks again about once an hour; ask ResellingKori when it will be available. **Sync now** checks at once |
| **Waiting** – "The agent dashboard is switched off at ResellingKori. This plugin keeps checking." | ResellingKori turned the service off | Ask ResellingKori. The shop asks again every 30 minutes |
| **Waiting** – "ResellingKori needs to update before this version of the plugin can sync. This plugin keeps checking." | This plug-in speaks a newer version of the dashboard protocol than ResellingKori's server | Ask ResellingKori to update; the shop asks again every six hours, or press **Sync now** after they confirm |
| **Problem** – a sentence such as "The last sync did not work. This plugin tries again automatically." or ResellingKori's own message | A temporary failure: the network, a timeout, an HTTPS or certificate error, a very busy server, or credentials that no longer work | The shop tries again by itself: after 30 seconds, then 1, 2, 4 and 8 minutes, and from then on every 15 minutes. If it continues: check *Test connection*, then `1.1 Connecting`; a firewall must allow outgoing HTTPS to `resellingkori.com` |
| **Problem** – "The answer from ResellingKori could not be verified, so it was ignored. If this keeps happening, a proxy or security plugin may be changing the response." | The answer arrived without a valid signature, or with a time more than five minutes away from your request. The shop never acts on an answer it cannot verify | A proxy, CDN rule, firewall page or security plug-in between your shop and ResellingKori may be altering responses, or a server clock is wrong (`1.1 Connecting`, clock). Send the diagnostics to ResellingKori |
| **Working** – "Working. Last synced … ago." | All is well | – |

**Instructions from the dashboard that did not happen.** The dashboard tells your agent in plain words why. These are the messages and what to do:

| Message (shortened) | Meaning and what to do |
|---|---|
| "The order changed after it was confirmed in the dashboard, so nothing was sent. Open the order again and confirm." | Somebody edited the order (or its status, address, items) between the agent's review and the click. This is the safety check working: the agent reloads the order, checks it and confirms again |
| "The amounts are not what was confirmed …" / "The items to send are not what was confirmed …" | Same, for the amounts or for the chosen items |
| "A ResellingKori price changed after it was confirmed in the dashboard, so nothing was sent. Open the order again and check your profit." | ResellingKori's price moved while the window was open. Review the new figures |
| "This order has already been sent, or is being checked." | It is at ResellingKori (or the shop is asking). Use **Check status** |
| The reason text of the order screen, for example "Orders with status … cannot be sent", "Customer information missing …", "ResellingKori only accepts orders in BDT" | The order cannot be sent for the same reason as on the order screen. Fix the order, or read the same box in WooCommerce (`1.4 Sending an order`) |
| "This order has more than 100 lines, which the agent dashboard cannot show. Send it from the shop's own order screen." | Use the order screen |
| "The shop owner has switched off sending, cancelling and linking from the agent dashboard. Nothing was changed." | The second box under *ResellingKori agent dashboard* is off. Tick it if the agent should be allowed to act |
| "This shop picked the instruction up too late, so it was not carried out. Nothing was changed. Try again." | The shop did not look for the instruction within the time allowed (15 minutes by default) – a very quiet shop without a real cron job, or a firewall blocking the nudge. Nothing happened; the agent presses the button again. See *Scheduled tasks* in `INSTALLATION.md` |
| "This shop is not connected to ResellingKori right now. Nothing was changed." | The key is not verified on the shop. *Test connection* |
| "This shop received the instruction but stopped before it could report. Look at the order to see what happened before you try again." / "This shop hit an unexpected error while carrying this out. Look at the order …" | The shop crashed or ran out of time at the wrong moment. **Open the order in WooCommerce** and read the ResellingKori box: if it says *not confirmed*, press **Check status** – never send blindly |
| "ResellingKori answered with something this shop did not expect. Look at the order before you try again." | Open the order and press **Check status** |
| "This order no longer exists in the shop." / "This product no longer exists in the shop." | Deleted in WooCommerce after the dashboard last looked. The card disappears at the next report |
| "This order is not active at ResellingKori, so there is nothing to cancel." / "ResellingKori has already started processing or shipped this order, so it can no longer be cancelled here. Contact ResellingKori." / "This order was replaced at ResellingKori after you opened it …" | Cancelling is only possible while ResellingKori has not processed the order. Reload; if it is shipped, contact ResellingKori |
| "Link the individual variations of a variable product, not the parent." / "This product is not linked any more, so there is nothing to confirm." | Link a variation; reload the product list |

**The agent presses a button and nothing seems to happen.** The dashboard shows "waiting for the shop" until the shop collects the instruction. The usual cause is that the shop is not asked for a while. Things to check, in this order:

1. *Settings → ResellingKori agent dashboard* says **Working** and *Last synced* is recent. If not, see the table above.
2. Your agent's browser nudges the shop with a GET request to `https://<your-shop>/wp-json/rkc/v1/wake?…`. ResellingKori builds that address only from your shop's **verified** address for live status updates, so the nudge exists only while the Connector shows "Live status updates are active" (see `1.2 "Live updates are not active"`). If the dashboard page shows "Your browser could not reach your shop directly", something between the browser and the shop blocks it: a firewall or bot protection (allow GET requests to `/wp-json/rkc/v1/wake`), a plug-in that disables the REST API for visitors (allow the `rkc/v1` namespace), or a cross-origin rule that removes the `Access-Control-Allow-Origin` header. This only makes the instruction slower, not impossible.
3. Without the nudge the shop looks about once a minute **if WordPress' scheduler runs**. On a shop without visitors it may not: ask your host for a real cron job that runs every minute (`INSTALLATION.md`).
4. *Settings → Sync now* collects pending instructions immediately.

**The agent dashboard shows an order that is "old" or an order I cancelled.** Orders are reported when they change. Press **Sync now**, or ask ResellingKori to press "Ask for full sync" for your shop. Cancelled, failed, refunded or trashed orders that were never sent are removed from the dashboard at the next report.

**The wrong person sees my shop / a different agent appears.** The dashboard follows your *key*: it belongs to the sales agent that *Verify connection* showed. If that is wrong, stop and tell ResellingKori; switch the dashboard off meanwhile.

**I switched the dashboard off – is my data gone?** The shop tells ResellingKori to delete it within about a minute. If your shop could not reach ResellingKori at that moment it keeps trying (the card shows **Off**); if you also deleted the plug-in the request cannot be repeated – write to ResellingKori and ask for your shop's data to be removed. Whatever remains expires after the retention period in `PRIVACY.md`.

## Getting help

Send: the plug-in version (in diagnostics), the exact message, the date/time with time zone, the order number, and the diagnostics text (for the agent dashboard also the sentence from the *Settings → ResellingKori agent dashboard* card). Do **not** send the secret, the encryption key or customers' personal data.
