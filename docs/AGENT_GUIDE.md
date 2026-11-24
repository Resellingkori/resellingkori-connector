# Agent guide – the ResellingKori page in your agent dashboard

This guide is for the **sales agent** (reseller) who works in the ResellingKori agent dashboard. It describes the extra menu entry **ResellingKori** that appears when your WooCommerce shop is connected to ResellingKori with the *ResellingKori Fulfillment Connector* and the agent dashboard is switched on in the shop (see `RESELLER_SETUP.md`, section 9).

**What it is for.** You no longer have to open WooCommerce for every order. In the dashboard you see the shop's orders that contain ResellingKori products, you see ResellingKori's price next to your selling price and what you earn, and you can **send** the order to ResellingKori, **cancel** it, **check** it and **link** your products – all from here.

**What it is not.** It is not a second shop and it does not change prices. Your shop stays in charge: it reports a short summary to this page, and when you press a button it does the work itself, after checking everything again.

---

## 1. Finding the page

Open your agent dashboard and click **ResellingKori** in the left menu. The entry is shown to agents whose shop has connected to ResellingKori with its key. If the entry is missing:

| Why | What to do |
|---|---|
| Your shop has not used its ResellingKori key yet (the Connector is not installed, or *Verify connection* was never pressed) | Finish the Connector's setup in your shop (*WooCommerce → ResellingKori → Setup*) |
| You are not the agent the shop's key belongs to | The page belongs to the agent linked to the key; ask ResellingKori which agent that is |
| ResellingKori has not switched the feature on, or limits it to shops that have already reported | Ask ResellingKori |

At the top of the page you see the **state of your shop**:

| It says | Meaning |
|---|---|
| **Shop connected · synced 2 min ago** | All is well |
| **Shop not responding · last seen …** | Your shop has not checked in for a while. What you ask for waits until it does (and is dropped if it is not picked up in time). Look at the shop: is it online, is WordPress' scheduler running? (`TROUBLESHOOTING.md`, 1.8) |
| **Waiting for your shop** | Your shop has not connected to the dashboard yet. Install ResellingKori Connector 1.1.0 or newer on the shop, then open *WooCommerce → ResellingKori* and switch the agent dashboard on |
| **Finish the setup in your shop** | The shop is connected to ResellingKori but is not sharing its orders with this dashboard yet. In the shop open *WooCommerce → ResellingKori* and switch on "Show this shop's ResellingKori orders and products in my ResellingKori agent dashboard"; if you cannot find that option, update the Connector to 1.1.0 or newer |
| **Dashboard sync is off in your shop** | The shop owner switched it off (*WooCommerce → ResellingKori → Settings*) |

**Sync now** asks your shop to send all its orders and products again. Use it when something looks outdated.

If a banner says *Changes from this page are switched off by ResellingKori*, you can look but not send or change anything. If your shop owner switched off "Let the dashboard send, cancel and link", the buttons are disabled and a note says where in the shop to allow it (*WooCommerce → ResellingKori → Settings*).

## 2. The Orders tab

The tab shows the shop's orders that contain at least one ResellingKori product, sorted into groups:

| Group | What is in it |
|---|---|
| **To send** | Orders that are ready to be sent to ResellingKori |
| **Not ready** | Orders your shop cannot send yet (for example the payment is pending, or the status is not one your shop allows) – the card says why |
| **Needs attention** | The last attempt failed or the result is not known |
| **Sent** | Already sent and still on their way |
| **Finished** | Delivered, returned or cancelled |
| **Skipped** | Orders you hid from the to-send list |

Search by order number or customer name.

### What an order card shows

* the order number, date and status in the shop; **Cash on delivery** or **Paid**;
* the customer's **name, city and a partly hidden phone number** (the e-mail, street address and full phone number are not available on this page – they go to ResellingKori only when the order is sent);
* for each item: the name, SKU, quantity, **Your price**, and, when the product is linked, **"ResellingKori has this product"** with the **ResellingKori price** and **Your profit**. Other badges: *Out of stock at ResellingKori*, *No longer at ResellingKori*, *A ResellingKori match is waiting for your confirmation* (go to Products and confirm it), *Not on ResellingKori. This item stays with you.*;
* the money: Products, **Delivery charge**, Already paid / discount, **Order total**, **Cash to collect**, ResellingKori price and **Your profit on the products**;
* **Open in my shop ↗**, which opens the order in WooCommerce.

### Sending an order

1. Open the card. If the order also has products that are **not** on ResellingKori, tick the items you want ResellingKori to send. The others stay with you, and your own order status is not changed by ResellingKori.
2. Optionally open **Change the delivery charge** and type the amount (leave it empty to use the shop's). If your shop's order has extra charges or a discount, two tick-boxes let you decide how they count.
3. Press **Send to ResellingKori**. A window "Send order #…" repeats the items and the money, including the **cash to collect**. Read it.
4. Press **Send now**. The card changes to *Waiting for your shop to send this order…*. While it is still waiting you can press **Take back**; once the shop has picked it up it can no longer be taken back.
5. Your shop checks the order once more and sends it. The card then says **Sent to ResellingKori · order …**, and later shows the status and the **tracking** number with **Track parcel**.

**If your shop refuses**, nothing is sent and the card tells you why, in plain words. Typical reasons: the order was edited after you opened it (reload and confirm again), ResellingKori's price changed (check your profit), the order has already been sent, or a rule of the order screen applies (a missing phone number, a currency other than BDT). `TROUBLESHOOTING.md` (1.8) lists them all.

**If the result is not known** ("The result of the last attempt is not known…"), **do not send again**. Press **Check status**: your shop asks ResellingKori whether the order arrived. The same order is never created twice.

### Other buttons

| Button | What it does |
|---|---|
| **Check status** | Asks ResellingKori for the current status and tracking |
| **Cancel at ResellingKori** | Stops the order while ResellingKori has not dispatched it. You can send a corrected order afterwards (**Send again**). After dispatch, contact ResellingKori |
| **Skip** / **Restore** | Hides an order from your to-send list (and brings it back). Nothing is told to your shop or to ResellingKori |
| **Reload** | Asks the shop for the latest version of this one order |

Statuses you may see for a sent order: Pending, Confirmed, Processing, On hold, Shipped, Delivered, Completed, Returned, Cancelled, Refunded, Failed.

## 3. The Products tab

Only linked products show their orders here, so link your products first.

* Filters: **Not linked**, **Needs review**, **Linked**, **Without SKU**, **All**; search by name or SKU.
* **Match all by SKU** – your shop links every unlinked product whose SKU is exactly the same at ResellingKori. If the names look very different the product is not linked automatically; you can link it yourself.
* For one product: if ResellingKori has a product with the **same SKU**, press **Link to this**. Otherwise press **Choose ResellingKori product**, search by name or SKU (at least two letters) and press **Select**. For a product with variations, choose the exact variation.
* **Needs review** means your shop guessed the match (for example from the name). Orders do not use it until you press **Confirm match**.
* **Unlink** removes the link; **Change** replaces it.

Linking, unlinking and confirming are carried out by your shop (like sending), so the result appears after a few seconds.

### Stock (needs Connector 1.2.0 in your shop)

* Every linked product shows ResellingKori's live stock: **ResellingKori stock: 12**, or **Out of stock at ResellingKori since …**.
* **Recently out of stock at ResellingKori** – the red box at the top lists the newest stock-outs first. **See all** opens the filter **Out of stock at ResellingKori**. Under *Linked* and *All*, stock-outs come first.
* **Stock monitor** (tick box on each linked product): when ResellingKori runs out, the product goes **out of stock in your shop** too, and comes back when ResellingKori has it again. Untick it and your shop is not changed. **Stock monitor for all linked products** switches it on for everything at once.
* "Still for sale in your shop" next to a stock-out means the monitor is off for that product: turn it on, or take the product off sale yourself.

## 3a. The Add products tab (needs Connector 1.2.0 in your shop)

1. Search by name or SKU, choose a category, keep **In stock only** ticked to hide what cannot be ordered. Each product shows what you pay (**You pay**), the stock, and **Already in your shop** if you have it.
2. Tick **Select** on the products you want. Enter **Your selling price** (for a product with variations: per variation, or type one amount in **Same price for all** and press **Apply**; untick a variation you do not want). ResellingKori's own shop price is filled in to start with – change it as you like. The page shows your profit per piece and refuses a price below what you pay.
3. Keep **Turn on stock monitor** ticked (recommended) and press **Add to my shop**, then **Add products**.
4. Your shop creates each product **published**, already **linked**, with its descriptions, SKU, categories and pictures (pictures arrive within a few minutes). A product that is already in your shop, or whose SKU your shop already uses, is not added twice – link the existing product instead.

## 4. The Overview tab

Shows the connection (shop, status, last contact, the Connector's version), how many requests are waiting for your shop, whether instant delivery works, and the number of orders and products. It also repeats how the page works.

## 5. How fast is it?

When you press a button the page asks your shop, from your browser, to look at once; when your shop's address for live updates is verified and no firewall is in the way, this works within a few seconds. If your browser cannot reach the shop directly you will see a short note ("Your browser could not reach your shop directly…"); that is fine – the shop picks the request up by itself within about a minute. If a request is not picked up in time (15 minutes) it expires and nothing happens.

## 6. Privacy in one paragraph

This page shows your shop's orders that contain ResellingKori products: number, status, items, amounts, the customer's name and city and a partly hidden phone number. The e-mail address, street address and complete phone number are never sent here. The shop owner can switch the page off at any time, which deletes the data at ResellingKori. Details: `PRIVACY.md`.

---

## ছোট গাইড (বাংলা)

1. এজেন্ট ড্যাশবোর্ডের বাম মেনুতে **ResellingKori** চাপুন। মেনু না থাকলে আগে শপে Connector-এর Setup শেষ করুন এবং *WooCommerce → ResellingKori → Settings → ResellingKori agent dashboard*-এ প্রথম বক্স টিক দিন।
2. উপরে লেখা দেখুন: **Shop connected · synced …** মানে সব ঠিক। **Shop not responding** মানে শপ অনেকক্ষণ সাড়া দেয়নি — আপনার অনুরোধ অপেক্ষায় থাকবে।
3. **Orders** ট্যাবে শপের সেই অর্ডারগুলো আসে যেগুলোতে ResellingKori-র পণ্য আছে: **To send**, **Not ready**, **Needs attention**, **Sent**, **Finished**, **Skipped**।
4. প্রতিটি অর্ডারে দেখবেন “**ResellingKori has this product**”, ResellingKori-র দাম, **Your price**, **Your profit** এবং কুরিয়ার কত টাকা সংগ্রহ করবে (**Cash to collect**)।
5. পাঠাতে: কার্ড খুলুন → (অন্য সাপ্লায়ারের পণ্য থাকলে শুধু ResellingKori-র পণ্য টিক দিন) → চাইলে **Change the delivery charge** → **Send to ResellingKori** → উইন্ডোর হিসাব পড়ে **Send now**। শপ নিজে আবার সব যাচাই করে তবেই পাঠায়; কিছু বদলে গেলে কিছুই পাঠায় না এবং কারণ লেখে।
6. ফলাফল “not known” হলে **আবার পাঠাবেন না** — **Check status** চাপুন।
7. শিপ হওয়ার আগে **Cancel at ResellingKori** দিয়ে বাতিল করা যায়। **Skip** শুধু আপনার তালিকা থেকে অর্ডার লুকায়, কাউকে কিছু জানায় না।
8. **Products** ট্যাবে: **Match all by SKU** চাপলে একই SKU-র পণ্য নিজে লিঙ্ক হয়; বাকিগুলো **Choose ResellingKori product** দিয়ে নিজে বেছে নিন; অনুমানে লিঙ্ক হওয়া পণ্য **Confirm match** না করলে অর্ডারে ব্যবহার হয় না।
9. **Products** ট্যাবের উপরে লাল বক্সে **Recently out of stock at ResellingKori** — সম্প্রতি ResellingKori-তে স্টক শেষ হওয়া লিঙ্ক করা পণ্য, নতুনগুলো আগে। প্রতিটি লিঙ্ক করা পণ্যে ResellingKori-র স্টক (**ResellingKori stock: 12**) দেখা যায়।
10. **Stock monitor** টিক দিলে: ResellingKori-তে স্টক শেষ হলে আপনার শপেও পণ্যটি *Out of stock* হবে, আবার স্টক এলে ফিরে আসবে। টিক না দিলে শপে কিছু বদলাবে না। **Stock monitor for all linked products** দিয়ে সবগুলোতে একসাথে চালু করা যায়।
11. **Add products** ট্যাব: নাম/SKU দিয়ে খুঁজুন, ক্যাটাগরি বাছুন → পণ্যে **Select** টিক দিন → **Your selling price** লিখুন (ভ্যারিয়েশন থাকলে প্রতিটির দাম, অথবা **Same price for all**) → **Turn on stock monitor** টিক রাখুন → **Add to my shop**। পণ্যটি আপনার শপে সরাসরি **Published** হয়ে, লিঙ্ক করা অবস্থায় তৈরি হবে; ছবি কয়েক মিনিটে আসবে। যা কিনছেন তার চেয়ে কম দাম দেওয়া যায় না। (দুটোর জন্যই শপে Connector 1.2.0 লাগবে।)
12. কিছু পুরোনো মনে হলে **Sync now** চাপুন। ইমেইল, পুরো ঠিকানা ও পুরো ফোন নম্বর এই পেজে আসে না।
