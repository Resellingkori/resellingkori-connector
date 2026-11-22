# Reseller guide – selling ResellingKori products from your own shop

This guide is for the owner or staff of a WooCommerce shop (for example `example.com`) that sells ResellingKori products at its **own prices** and lets ResellingKori pack, ship and collect the cash.

**The idea in one paragraph.** You keep selling in your own shop at your own price. When a customer orders, you open the order, press **Review and send**, check the figures and confirm. ResellingKori receives the order with *your* selling price (it is recorded as the sales agent's *set price*, so the agent's commission follows the normal SalesKing rules), packs it, and the courier collects the cash you confirmed. Status and tracking come back to your order automatically. Nothing is ever sent without a person pressing the button – that person can be you, on the order screen, or (if you switch it on) your sales agent, in the agent dashboard at ResellingKori (section 9).

---

## 1. What you need

* Your **Key ID** (starts with `rkk_`) and **Secret** (starts with `rks_`). ResellingKori staff create them for your store and give them to you **once**. Keep the secret private, like a bank password. Do not e-mail it in clear text, post it in a group chat, or save it in a shared document.
* WooCommerce with the store currency set to **BDT**.
* The Connector plug-in installed (see `INSTALLATION.md`).

## 2. Setup in six steps (WooCommerce → ResellingKori → Setup)

1. **Welcome** – read the four checks. A "!" is a warning, not a stop: no HTTPS means live updates are replaced by a check every 15 minutes; a currency other than BDT means ResellingKori will refuse your orders.
2. **Connect** – type the Key ID and the Secret. Nothing else is needed: your store, your sales agent and the address for live updates are taken from the key.
3. **Verify** – press **Verify connection**. You must see **Connected** with *your* store name and *your* sales agent. If they are wrong, stop and tell ResellingKori. The *Continue* button stays disabled until the connection works.
4. **Products** – press **Match all by SKU now**. Every product in your shop whose SKU is exactly the same as a ResellingKori product is linked.
5. **Orders** – the preferences (section 6). The defaults are usually right. This step also has the choice **"Show this shop's ResellingKori orders and products in my ResellingKori agent dashboard"** (section 9). It is ticked because it is the recommended choice; read the text above it, which says what is shared, and untick it if you do not want it.
6. **Finish** – summary.

You can leave the guide and come back; it remembers where you were.

## 3. Linking your products (WooCommerce → ResellingKori → Products)

Only linked products can be sent. The plug-in looks for the match in this order:

1. **Exact SKU.** The same SKU means the same product. If the SKU matches but the names look completely different, the plug-in does not link it by itself (there is a setting for this under *Product matching*) – it asks you to check.
2. **Similar names.** For products without a match by SKU, the plug-in searches ResellingKori by keywords taken from your product name and shows the best candidates with a rating (*Very likely / Possible / Unlikely*), the SKU, the ResellingKori price, and which words of your name are *not* in the candidate's name. **You choose** – nothing in this second group is linked automatically.
3. **Your own search.** Search by name, SKU or paste a ResellingKori ID.

Variable products: choose the exact variation. A link marked **needs review** is not used for orders until you confirm it. If a linked product disappears or goes out of stock at ResellingKori you will see it on the dashboard and in the product list ("unavailable").

Products you do not link stay yours: ResellingKori never sees them.

## 4. Sending an order

1. Open the order (*WooCommerce → Orders → the order*). Orders are offered for sending only in the statuses you allowed (default: *Processing* and *On hold*).
2. In the **ResellingKori** box press **Review and send**.
3. The **Review before sending** panel shows, line by line:
   * your product, quantity and **You sell at** price,
   * the **ResellingKori price** and your **Profit** (or **Loss**),
   * the **ResellingKori product** that will be used,
   and then the money:
   * **Items at your selling prices**
   * **Delivery charge** – the amount the customer pays for delivery. You can change it here (BDT).
   * **Fees or adjustments** and **Rounding** – only when your order has a fee, a credit or a discount that is neither an item nor delivery. A tick-box lets you decide: *Also collect X of fees or adjustments from the customer*, or *The customer already received a credit or discount of X. Subtract it, so the courier does not collect it.* ResellingKori has no separate field for such a fee, so it is added to the delivery charge it receives
   * **Order total**
   * **Already paid or credited** – for example a prepaid order, a coupon or a credit
   * **Cash the courier collects** – this is exactly what the courier will ask the customer for.
   * **Your profit on the items**
   * **Delivery to** – the recipient's name, phone and address that will be sent.
4. Check everything again and press **Confirm and send to ResellingKori**.

What the panel is telling you:

| You see | Meaning |
|---|---|
| *This order cannot be sent yet:* + reasons | Fix the reason (an item without a link, a missing phone number or address, a currency other than BDT, ...) and open the panel again |
| *Please check:* + warnings | You may continue, but read them |
| A **Loss** on a line | Your selling price is below ResellingKori's price. By default ResellingKori refuses orders priced below its base price |
| "you need 3, ResellingKori has 1" | Not enough stock at ResellingKori. Reduce the quantity or wait for stock |
| "ResellingKori price changed from … to …" | The price changed between your review and the send. Open the review again to see the new figures |

**How the price travels.** Your product list prices stay in your shop. For every line, the **price you charge** goes to ResellingKori as the sales agent's *set price* and ResellingKori's **own price** is recorded as the *original price*. ResellingKori calculates its base total and your margin from **its own catalogue**, never from numbers typed in your browser. The plug-in does not try to change prices, and cannot be used to get a product below ResellingKori's price unless ResellingKori allows it.

**Cash on delivery.** Payment methods listed under *Settings → Payment → Payment methods that mean "cash on delivery"* (default `cod`) are sent as cash on delivery. Orders paid with any other method are sent as already paid: the courier collects only the delivery charge you confirm, if any. Amounts are in whole BDT and paisa; they are always recomputed and compared on both sides, so a total that does not add up is refused instead of guessed.

### 4.1 Orders with products from other suppliers (mixed orders)

If an order contains some ResellingKori products and some products that are **not** linked, the box says *"This order also contains products from other suppliers. You will choose which items to send."* In the review you:

* tick the items you want ResellingKori to deliver (items that are not linked to a ResellingKori product are shown with a dash and cannot be ticked),
* see the delivery charge suggested for that parcel – set by *Orders with products from other suppliers → Suggest no delivery charge / Suggest the order's shipping charge*; you can edit it,
* and confirm.

Only those items are sent. For such an order **your own order status is never changed** by ResellingKori (the box says *Selected items only*), because the rest of the order is yours to fulfil. The status and tracking of the ResellingKori parcel are shown in the box.

### 4.2 After sending

The box now shows the **ResellingKori order** number, a status badge, the **Tracking** number and link (when the courier has one), **Cash to collect**, and when it was **Last updated**.

* **Refresh status** – asks ResellingKori right now. Live updates normally arrive by themselves; if your site cannot receive them, the plug-in asks every 15 minutes for orders that are still open.
* Your own order status can follow ResellingKori's (*Preferences → Status updates from ResellingKori*). By default only **Delivered** completes your order; everything else is only recorded as a note/badge.
* Optionally the plug-in can change your order's status right after sending (*After sending → Change the order status to*).

### 4.3 If the answer is lost

Sometimes the internet drops after the order was sent. The box then shows *"ResellingKori did not confirm this order, so it may or may not have arrived."* **Do not send it again** – press **Check status**. The plug-in asks ResellingKori what it received; if nothing arrived it sends the same order again safely (the same order can never be created twice). It also checks by itself with growing pauses and e-mails you if it cannot settle it.

### 4.4 Cancelling

While ResellingKori has not shipped the order (statuses *pending, processing, on hold*) and no tracking number exists, **Cancel at ResellingKori** is available. Give an optional reason. ResellingKori stops fulfilling the order, stock is released, and you can send a corrected order afterwards (**Review and send again**; it is recorded as a new attempt, and the earlier cancelled attempt stays in the history). After shipping, cancelling is not possible – contact ResellingKori.

### 4.5 When ResellingKori refuses an order

The box shows the reason in plain words and the lines concerned (for example *price below the allowed minimum*, *not enough stock*, *address missing*). Correct the cause and use **Review and send again**. Nothing was created at ResellingKori for a refused order.

If you change the items of an order **after** it was sent, the box warns *"The items of this order changed after it was sent. ResellingKori still has the original. Cancel it there and send the corrected order."* The plug-in never edits an order that ResellingKori already accepted.

## 5. The shop's Dashboard, Orders and Logs screens

* **Dashboard** – connection status, how many products are linked, the state of the agent dashboard (section 9), and a list of things that need your attention (orders waiting for confirmation or refused, links to review, linked products that are unavailable at ResellingKori).
* **Orders** – every order you sent, with state and ResellingKori status.
* **Logs & diagnostics** – a technical log (kept 30 days by default) and a **Copy diagnostics** button. Send the diagnostics to ResellingKori support when you ask for help; they never contain your secret. You can filter the log and **Export CSV**.

## 6. Preferences (WooCommerce → ResellingKori → Settings)

| Group | What it does |
|---|---|
| Which orders may be sent | The *Review and send* button appears only for these statuses |
| Payment | Which payment methods mean cash on delivery |
| After sending | Optionally change the order status |
| Orders with products from other suppliers | Suggested delivery charge for a partial parcel |
| Status updates from ResellingKori | How your order status follows ResellingKori's (Pending, Processing, On hold, Shipped, Delivered, Returned, Cancelled) |
| Sales agent check (optional) | Type the e-mail of the agent you expect; orders are blocked if ResellingKori reports a different agent for your key. ResellingKori never receives this address except to compare it |
| Notifications | E-mail when an order cannot be confirmed or ResellingKori reports a problem |
| ResellingKori agent dashboard | Whether this shop reports its ResellingKori orders and products to your agent dashboard, and whether the dashboard may also send, cancel and link on your behalf or only look (section 9) |
| Product matching | Do not link automatically when the SKU matches but the names look completely different |
| Logs and data | Log detail, how long entries are kept, and whether to delete everything when the plug-in is deleted |

Under **Connection** you can *Test connection*, *Replace the key and secret* (when ResellingKori rotates or re-issues your key) and *Disconnect*.

## 7. Keeping your key safe

* The secret is stored encrypted and is never displayed again. Only administrators can see the key area.
* If you think the secret was seen by someone else: tell ResellingKori at once. They can revoke the key immediately and issue a new one; enter it under *Settings → Connection → Replace the key and secret*.
* A person who can edit your site's files or database can still reach the stored key – use strong passwords and two-step login for administrators.

## 8. Your customers' data

When you press *Confirm and send*, the recipient's **name, phone number, delivery address, e-mail (if the order has one) and note** are sent to ResellingKori so that it can deliver the order. Mention this in your privacy policy (WordPress adds a suggested paragraph under *Settings → Privacy → Policy Guide*). More in `PRIVACY.md`. If you switch on the agent dashboard (section 9), a shorter summary of your orders is also shown there; `PRIVACY.md` lists it field by field.

## 9. The agent dashboard at ResellingKori (optional)

**What it is.** Your sales agent's dashboard at ResellingKori gets one extra menu entry, named **ResellingKori**. It is a separate page with three tabs, so that the agent does not have to open WooCommerce again and again to confirm orders. Only agents whose shop is connected through this plug-in see the entry; other agents do not. It has:

* **Orders** – the orders of your shop that contain at least one ResellingKori product. Each order is a card with your order number, date and status, the customer's name, city and a partly hidden phone number, and every item with **"ResellingKori has this product"**, ResellingKori's price next to your selling price, and the profit. Buttons: **Send to ResellingKori** (a review window shows the same figures as the order screen – items, delivery charge, already paid, **cash the courier collects** – and the agent confirms), **Cancel at ResellingKori**, **Check status**, and **Skip** (hides an order in the dashboard only; nothing changes in your shop). For an order that also contains products from other suppliers, the agent ticks the items to send, exactly as you would in section 4.1. A link opens the order in your WooCommerce.
* **Products** – your shop's products and whether each is linked. The agent can link a product to a ResellingKori product (search by name or SKU, or one click when the SKU is exactly the same), unlink it, confirm a link that *needs review*, or match everything by SKU.
* **Overview** – the numbers, what needs attention, and whether your shop is in step with the dashboard.

**How it works.** The dashboard never reaches into your shop. Your shop reports a short summary of those orders and products to ResellingKori by itself – about once a minute, and straight away after a change. When the agent presses a button, ResellingKori writes down an *instruction*. Your shop collects it the next time it asks (about once a minute, or at once when the agent's browser nudges it) and **checks it again with its own rules** before it does anything. It carries the instruction out only if all of this is still true:

* the order has not changed since the agent looked at it, and the amounts and items the agent confirmed are exactly what the shop calculates now (including ResellingKori's current price, so the profit the agent saw is real);
* the order is allowed to be sent (an allowed status, linked products, a valid recipient, BDT) and has not been sent already;
* you have not switched off sending from the dashboard (see *Your controls* below);
* the instruction is recent (by default 15 minutes; an old one expires and nothing happens).

Otherwise nothing is done and the dashboard tells the agent why in plain words (for example *The order changed after it was confirmed in the dashboard, so nothing was sent*). When it is done the order carries the note *Sending was requested from the ResellingKori agent dashboard*, so you can always see where a send came from. The result is the same as pressing the button on the order screen: the same duplicate protection, the same recovery if the answer is lost, the same status updates.

**What ResellingKori's dashboard receives, and what it does not.** Your order number, date, status, the items (name, SKU, quantity, prices), the amounts, the customer's **name, city and state**, a **partly hidden phone number** (for example `017•••••678`) and, once sent, the ResellingKori order, status and tracking. It does **not** receive the e-mail address, the street address or the full phone number: those leave your shop only when an order is sent, as before. Only orders that contain a product you linked to ResellingKori are included (all items of such an order are shown, so that the agent can pick the right ones); your other orders stay in your shop. For the **Products** tab the dashboard also receives the name, SKU, price, picture address and link state of your published products, so that the agent can link them – no stock levels, costs or customer data. Details and retention are in `PRIVACY.md`.

**Your controls** (*WooCommerce → ResellingKori → Settings → ResellingKori agent dashboard*, administrators only):

| Control | Effect |
|---|---|
| **Show this shop's ResellingKori orders and products in my ResellingKori agent dashboard** | On: the shop reports as described above. Off: nothing is reported, and your shop tells ResellingKori to **delete everything it holds from your shop** (usually within a minute of saving; if ResellingKori cannot be reached, the shop keeps trying). *Disconnect* does the same. |
| **Let the dashboard send, cancel and link on this shop's behalf (leave off to only look)** | On: the agent can use the buttons. Off: the dashboard still shows the orders, but its buttons are disabled and any instruction that arrives anyway is refused by your shop. |
| **Sync now** | Collect instructions and report changes straight away (this happens by itself about once a minute). |

The card also shows the state – **Working**, **Waiting**, **Problem** or **Off** – with the number of orders and products shown, what is waiting to be reported and how many instructions were carried out. `TROUBLESHOOTING.md` explains every message.

**Who is "the agent"?** The sales agent linked to your key – the one *Verify connection* showed you. Other agents cannot see your shop in their dashboards. How long ResellingKori keeps the summaries is stated in `PRIVACY.md`.

**How fast is it?** When the agent's browser can nudge your shop (this needs the address for live status updates to be verified – the Connector shows "Live status updates are active" – and a firewall that lets the nudge through) an instruction is usually carried out within a few seconds. Otherwise it waits for the shop's next scheduled check, about a minute. On a shop that nobody visits and that has no real cron job, WordPress may run its scheduled tasks late; see *Scheduled tasks* in `INSTALLATION.md`.

**The dashboard is optional.** Everything in sections 1–8 works without it. If ResellingKori has not switched the dashboard on for stores yet, the card says so and your shop quietly tries again later (about once an hour); nothing else is affected.

---

## ছোট্ট গাইড (বাংলা)

1. **WooCommerce → ResellingKori → Setup** খুলুন।
2. ResellingKori থেকে পাওয়া **Key ID** (`rkk_…`) ও **Secret** (`rks_…`) লিখে *Save and continue* চাপুন। Secret কাউকে দেখাবেন না।
3. **Verify connection** চাপুন — আপনার স্টোরের নাম ও Sales Agent ঠিক আছে কি না মিলিয়ে নিন।
4. **Match all by SKU now** চাপুন — SKU মিললে প্রোডাক্ট নিজে থেকে লিঙ্ক হবে। বাকিগুলো *Products* ট্যাবে নাম দেখে নিজে বেছে নিন।
5. অর্ডার এলে অর্ডার খুলে **ResellingKori** বক্সে **Review and send** চাপুন। আপনার বিক্রয়মূল্য, ResellingKori-র দাম, আপনার লাভ এবং কুরিয়ার কত টাকা সংগ্রহ করবে — সব মিলিয়ে দেখে **Confirm and send to ResellingKori** চাপুন।
6. অর্ডারে অন্য সাপ্লায়ারের পণ্যও থাকলে শুধু ResellingKori-র পণ্যগুলো টিক দিন; ওই অর্ডারের নিজের স্ট্যাটাস আপনার শপে নিজে থেকে বদলাবে না।
7. পাঠানোর পর উত্তর না এলে আবার পাঠাবেন না — **Check status** চাপুন। একই অর্ডার কখনও দু’বার তৈরি হয় না।
8. শিপ হওয়ার আগে বাতিল করতে চাইলে **Cancel at ResellingKori** চাপুন।
9. **এজেন্ট ড্যাশবোর্ড (ঐচ্ছিক):** Setup-এর ৫ম ধাপে বা *Settings → ResellingKori agent dashboard*-এ “Show this shop's ResellingKori orders and products in my ResellingKori agent dashboard” টিক দিন। তখন আপনার Sales Agent তাঁর ResellingKori ড্যাশবোর্ডের **ResellingKori** মেনুতে শপের সেই অর্ডারগুলো দেখবেন যেগুলোতে ResellingKori-র পণ্য আছে, সেখান থেকেই **Send**, **Cancel**, **Check status** করতে পারবেন এবং **Products** ট্যাবে পণ্য লিঙ্ক করতে পারবেন — বারবার WooCommerce-এ ঢুকে কনফার্ম করতে হবে না।
10. ড্যাশবোর্ডে ইমেইল, পুরো ঠিকানা বা পুরো ফোন নম্বর **যায় না**; শুধু নাম, শহর, আংশিক-ঢাকা ফোন (`017•••••678`), পণ্য ও টাকার হিসাব যায়। অর্ডার পাঠানোর সময় আগের মতোই প্রয়োজনীয় তথ্য যায়।
11. শুধু দেখার জন্য রাখতে চাইলে দ্বিতীয় বক্স (“Let the dashboard send, cancel and link…”) বন্ধ রাখুন। পুরো ফিচার বন্ধ করলে ResellingKori-তে জমা থাকা সব তথ্য মুছে ফেলার অনুরোধ নিজে থেকেই চলে যায়।
12. প্লাগইন ডিলিট করার আগে ড্যাশবোর্ড বন্ধ করে নিন; আর শপে প্রতি মিনিটে চলা একটি আসল cron job রাখলে ড্যাশবোর্ডের কাজ দ্রুত হয়।
