# Security – ResellingKori Fulfillment Connector 1.1.0

This page is for shop owners and their developers. It says what the plug-in does to keep your data and your ResellingKori account safe, what you must do yourself, and what no plug-in can protect against.

## What the plug-in does

| Risk | What the Connector does |
|---|---|
| Someone steals your secret from the network | The secret is **never sent**. Each request carries a signature made with the secret (HMAC-SHA256) over the request itself, the time and a one-time number. A captured request cannot be changed or replayed. |
| Someone steals your secret from the database or a backup | The secret is **encrypted** before it is saved (libsodium). You can add your own key in `wp-config.php` (`RKC_ENCRYPTION_KEY`) so that a database copy alone is not enough. |
| Someone redirects your orders to another server | The ResellingKori address is **fixed in the code**. It is not a setting and cannot be edited from the dashboard. |
| A staff member with a low role changes the connection or sees the key | Six dedicated permissions; the key area needs `rkc_manage_credentials` (administrators only). Having WooCommerce's `manage_woocommerce` is **not** enough. |
| A malicious web page makes your logged-in administrator send or cancel an order | Every action checks a security token (WordPress **nonce**) and the permission on the server. |
| An order is sent twice (double click, two staff, timeout) | One in-flight request per order, an immutable copy of what was sent, and recovery that **asks ResellingKori first**. |
| Prices or amounts are changed in the browser between review and send | The server recomputes everything and refuses to send if anything changed after the review. ResellingKori recomputes again and refuses a total that does not match. |
| Forged or replayed status updates | Webhooks are accepted only with a valid signature, a fresh timestamp, a one-time number and an unseen event id; older events are ignored; repeated failures from one address are slowed down. |
| Secrets in logs and exports | Secrets, signatures and tokens are removed before anything is logged; e-mail addresses and phone numbers are masked as a second line of defence; the CSV export protects against spreadsheet formula tricks and needs a permission and a nonce. |
| Tracking of your site | None: no analytics, no telemetry, no remote scripts or fonts, no update pings. |
| **Agent dashboard:** somebody forges an "instruction" to your shop | The shop **asks** ResellingKori for instructions and only accepts them inside an answer that carries ResellingKori's signature (HMAC-SHA256 with your secret, bound to that very request's one-time number, the time, the status and the content). Nobody can push an instruction into your shop, and an answer that is unsigned, altered or meant for another request is thrown away. |
| **Agent dashboard:** an old instruction or answer is replayed | An answer is only valid for the request it answers. Every instruction has an identifier that is carried out once, and it expires (15 minutes by default). |
| **Agent dashboard:** the dashboard (or the account of your sales agent) asks for something you did not intend | The dashboard cannot change **what** is sent: the recipient, the address and your selling prices come from your own order. It can choose which of the order's ResellingKori items to send, the delivery charge and whether a fee or credit on the order is included (within the limits ResellingKori applies). Before it does anything, **your shop checks everything again** with its own rules: the order must be unchanged since the agent reviewed it, the amounts, items and ResellingKori's current prices must be exactly what was confirmed, the order must be allowed to be sent and not already sent. Anything else is refused and reported. |
| **Agent dashboard:** a stranger calls the "please look now" address | The address (`…/wp-json/rkc/v1/wake`) accepts only a request signed with your secret, valid for two minutes. All it can do is make your shop run its normal check a little earlier. Wrong signatures are counted per address and slowed down; repeated calls are rate-limited. |
| **Agent dashboard:** customers' data goes to a screen | Only a short summary (name, city, partly hidden phone number, items, amounts, status). The e-mail address, street address and full phone number are **not** included; they leave the shop only when an order is sent. Nothing is collected until the owner has said yes; switching it off deletes it at ResellingKori (`PRIVACY.md`). |

## What you must do

1. **Protect administrator accounts**: unique strong passwords, two-step login, no shared logins. Anyone who can log in as an administrator can send orders in your name.
2. **Keep everything updated**: WordPress, WooCommerce, PHP, this plug-in. Remove plug-ins you do not use.
3. **Use HTTPS** on the whole site (needed for live status updates, and good practice).
4. **Keep the secret private.** Never put it in e-mail, chat, a shared document or a support ticket. If you think someone else saw it, tell ResellingKori **immediately** – they can revoke it at once and issue a new one – then enter the new one under *WooCommerce → ResellingKori → Settings → Connection → Replace the key and secret*.
5. **Give people only the roles they need.** Fulfilment staff need *Shop manager* (or only the single permissions they need), not *Administrator*.
6. **Back up** your site regularly, and keep backups private: they contain your customers' details.
7. **Optional hardening** in `wp-config.php`: `define( 'RKC_ENCRYPTION_KEY', '<32+ random characters>' );` (before saving the key), `define( 'DISALLOW_FILE_EDIT', true );`, correct file permissions, and a firewall.
8. **Check the first order**: the *Verify connection* step shows the store name and the sales agent that your key belongs to – make sure they are yours.
9. **Decide about the agent dashboard on purpose.** It lets the sales agent linked to your key send, cancel and link from ResellingKori's dashboard. Whoever controls that agent's account at ResellingKori can therefore send those orders to ResellingKori at the prices of your own orders. Protect the agent's account like your own administrator login. If the agent should only look, leave **"Let the dashboard send, cancel and link on this shop's behalf"** unticked: the dashboard then only shows, and your shop refuses every instruction that would change something.
10. **Switch the dashboard off before you delete the plug-in or sell the shop**, so that ResellingKori deletes what it holds.

## What no plug-in can protect against

* A server, hosting account or administrator account that an attacker controls. They can read the key and use it.
* A compromised **sales-agent account at ResellingKori** when you allowed the dashboard to act (see item 9 above). It cannot change your customers' addresses or your prices, but it can send orders you would otherwise have sent yourself.
* Malware on the computer of an administrator.
* What happens to your customers' data after ResellingKori received it (that is covered by your agreement with ResellingKori and by its own privacy practices).
* Mistakes: a wrong selling price you confirm is a price you chose. The review screen shows ResellingKori's price and your profit or loss to help you spot them.

## If something looks wrong

* Unexpected orders, unknown logins, or a warning about the sales agent: tell ResellingKori at once, and change administrator passwords.
* Strange entries in *WooCommerce → ResellingKori → Logs & diagnostics*: copy the diagnostics (it contains no secret) and send it to ResellingKori support.
* Report a suspected vulnerability **privately** to ResellingKori through your support contact. Please do not post details publicly before it is fixed.
