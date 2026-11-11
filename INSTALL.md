# Installation Guide – ResellingKori Connector

## Requirements

- WordPress 6.0 or higher
- WooCommerce (any version that supports HPOS)
- PHP 7.4 or higher
- An active API key from ResellingKori

---

## Step 1 – Download the plugin

Go to the [**Releases**](https://github.com/Resellingkori/resellingkori-connector/releases) tab.  
Download the latest `resellingkori-connector-vX.X.X.zip` file.

---

## Step 2 – Install in WordPress

1. Log in to your WordPress admin (`https://yourshop.com/wp-admin`)
2. Go to **Plugins → Add New → Upload Plugin**
3. Click **Choose File** → select the ZIP you downloaded
4. Click **Install Now** → then **Activate Plugin**

The plugin appears as **ResellingKori Connector** in the Plugins list.

---

## Step 3 – Enter your API credentials

1. Go to **WooCommerce → ResellingKori → Settings**
2. Enter your **API Key** and **Secret** (from your ResellingKori account manager)
3. Click **Save Settings**
4. Click **Test Connection** — you should see **Connected ✅**

If the test fails:

| Error | Fix |
|---|---|
| "Invalid signature" | Re-enter the Secret carefully — no extra spaces |
| "Request timestamp outside allowed window" | Fix your server clock (enable NTP) |
| "No sales agent linked to this API key" | Ask ResellingKori to link your agent account |
| HTTP 403 / 404 on resellingkori.com | A WAF on resellingkori.com is blocking your server's IP — contact ResellingKori |

---

## Step 4 – Map your products

1. Go to **WooCommerce → ResellingKori → Product Mapping**
2. Click **Auto-match all by SKU** to automatically map products whose SKUs match
3. For unmatched products, use **Keyword Search** or enter a ResellingKori product ID manually
4. Confirm each match — no match is ever auto-confirmed without your review

---

## Step 5 – Send your first order

1. Go to **WooCommerce → Orders** and open any order that contains mapped products
2. Find the **ResellingKori** box on the right side
3. Click **Send to ResellingKori**
4. The order will show a ResellingKori order ID and status once accepted

---

## Step 6 – Configure status sync (optional)

Go to **WooCommerce → ResellingKori → Settings → Status Mapping** to choose which ResellingKori status maps to which WooCommerce status.

Default: `delivered → completed` only.

For webhooks (push updates) to work, your store must be publicly reachable at:
```
https://yourshop.com/wp-json/rkc/v1/webhook
```

If you are on localhost, use **ngrok** or a similar tunnel during development.  
See [partner-docs / SANDBOX.md](https://github.com/Resellingkori/partner-docs/blob/main/SANDBOX.md) for sandbox and local testing instructions.

---

## Updating the plugin

1. Download the new ZIP from the [Releases](https://github.com/Resellingkori/resellingkori-connector/releases) tab
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP → click **Replace current with uploaded**
4. Your product mappings, settings, and order history are preserved

---

## Uninstalling

1. Go to **Plugins** → Deactivate the plugin → Delete it

> ⚠️ Deleting the plugin removes all stored API credentials and product mappings.  
> Note your Key ID before uninstalling if you plan to reinstall later.
