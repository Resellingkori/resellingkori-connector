/*
 * ResellingKori Connector - admin script. Plain JavaScript, no dependencies.
 *
 * Security notes
 *  - Text that comes from the store or from ResellingKori (product names, SKUs, customer data, error messages) is only
 *    ever put into the page with textContent / setAttribute, never as HTML. The only innerHTML uses are for markup that
 *    this plugin's own PHP produced and escaped: the order box and a refreshed product-table row.
 *  - Links and image addresses are used only when they start with http:// or https://.
 *  - No secret is available to this script. The page only gets the AJAX address, a nonce and translated strings.
 *    The Key ID and secret are typed into an ordinary password form that is posted to the server and never read here.
 */
(function () {
	'use strict';

	var cfg = window.rkcAdmin;
	if (!cfg) { return; }
	var t = cfg.i18n || {};

	/* ------------------------------------------------------------ helpers */

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	/** Replaces %s / %1$s / %2$s in a translated string. */
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str).replace(/%(?:(\d+)\$)?s/g, function (m, n) {
			var v = n ? args[parseInt(n, 10) - 1] : args[i++];
			return v === undefined ? '' : String(v);
		});
	}

	/** Creates an element. attrs: text -> textContent, class -> className; false/null/undefined are skipped. */
	function h(tag, attrs) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'text') { el.textContent = v; }
			else if (k === 'class') { el.className = v; }
			else { el.setAttribute(k, v === true ? '' : v); }
		});
		for (var i = 2; i < arguments.length; i++) {
			var c = arguments[i];
			if (c === null || c === undefined || c === false) { continue; }
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		}
		return el;
	}

	function safeUrl(u) { return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : ''; }
	function safeKey(k) { return (typeof k === 'string' && /^[A-Za-z0-9_-]+$/.test(k)) ? k : ''; }

	function badge(text, kind) { return h('span', { 'class': 'rkc-badge rkc-badge--' + (kind || 'neutral'), text: text }); }

	function post(action, data) {
		var body = new FormData();
		body.append('action', 'rkc_' + action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); }
			else if (v !== undefined && v !== null) { body.append(k, v); }
		});
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.catch(function () { return { success: false, data: { message: t.error } }; });
	}

	function message(res, fallback) {
		return (res && res.data && res.data.message) ? res.data.message : (fallback || t.error);
	}

	function notice(kind, text, list) {
		var box = h('div', { 'class': 'rkc-notice rkc-notice--' + kind, role: kind === 'error' ? 'alert' : 'status' });
		box.appendChild(h('p', { text: text }));
		if (list && list.length) {
			var ul = h('ul');
			list.forEach(function (x) { ul.appendChild(h('li', { text: String(x) })); });
			box.appendChild(ul);
		}
		return box;
	}

	function show(target, node) {
		if (!target) { return; }
		target.textContent = '';
		if (node) { target.appendChild(node); }
	}

	/** A notice near the top of the page, for results of actions outside a specific box. */
	function flashTop(kind, text) {
		var nav = $('.rkc-wrap .rkc-tabs');
		var old = document.getElementById('rkc-flash');
		if (old) { old.remove(); }
		var n = notice(kind, text);
		n.id = 'rkc-flash';
		if (nav) { nav.after(n); }
		else {
			// No tab bar on this screen: put the notice at the top of the plugin's page (or of the body), never in a blocking dialog.
			var host = $('.rkc-wrap') || document.body;
			host.insertBefore(n, host.firstChild);
		}
		n.scrollIntoView({ block: 'nearest' });
	}

	function busy(btn, on) {
		if (!btn || btn.tagName !== 'BUTTON') { return; }
		if (on) {
			btn.setAttribute('disabled', 'disabled');
			btn.setAttribute('aria-busy', 'true');
			if (!btn.dataset.label) { btn.dataset.label = btn.textContent; }
			btn.textContent = t.working;
		} else {
			btn.removeAttribute('disabled');
			btn.removeAttribute('aria-busy');
			if (btn.dataset.label) { btn.textContent = btn.dataset.label; delete btn.dataset.label; }
		}
	}

	/* --------------------------------------------------- connection test */

	function renderVerify(target, d) {
		var ok = d.status === 'connected';
		var box = h('div', { 'class': 'rkc-notice rkc-notice--' + (ok ? 'success' : 'warning'), role: 'status' });
		box.appendChild(h('p', null, h('strong', { text: ok ? t.verifyOk : t.verifyNot })));
		var dl = h('dl', { 'class': 'rkc-dl rkc-dl--compact' });
		function row(label, value) { dl.appendChild(h('div', { 'class': 'rkc-dl__row' }, h('dt', { text: label }), h('dd', { text: value || '—' }))); }
		row(t.lblStore, (d.store_name || '—') + (d.store_url ? ' (' + d.store_url + ')' : ''));
		row(t.lblAgent, (d.agent_name || '—') + (d.agent_hint ? ' (' + d.agent_hint + ')' : ''));
		row(t.lblLive, d.webhook === 'verified' ? t.liveOn : t.liveOff);
		box.appendChild(dl);
		if (d.error) { box.appendChild(h('p', { text: d.error })); }
		(d.warnings || []).forEach(function (w) { box.appendChild(h('p', { 'class': 'rkc-muted', text: w })); });
		show(target, box);
	}

	/* ------------------------------------------------------ progress loop */

	/**
	 * Calls a batch endpoint again and again until it says it is done. The server keeps no state: the cursor travels
	 * back and forth. Stops on an error, when a round makes no progress, or after 500 rounds.
	 */
	function loop(action, target, summary, summaryDone) {
		var bar = h('span');
		var txt = h('p', { 'class': 'rkc-muted', text: t.working });
		show(target, null);
		target.appendChild(h('div', { 'class': 'rkc-progress', role: 'progressbar', 'aria-label': t.working }, bar));
		target.appendChild(txt);

		var totals = { linked: 0, review: 0, not_found: 0, checked: 0, unavailable: 0 };
		var cursor = 0, rounds = 0;

		function finish() {
			bar.style.width = '100%';
			var n = notice('success', summaryDone(totals));
			var again = h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--small', text: t.reload });
			again.addEventListener('click', function () { window.location.reload(); });
			n.appendChild(h('p', null, again));
			show(target, n);
		}

		function step() {
			post(action, { mode: 'all', cursor: cursor }).then(function (res) {
				if (!res.success) { show(target, notice('error', message(res))); return; }
				var d = res.data;
				Object.keys(totals).forEach(function (k) { totals[k] += (parseInt(d[k], 10) || 0); });
				rounds++;
				bar.style.width = Math.min(95, rounds * 8) + '%';
				txt.textContent = summary(totals);
				var progressed = (d.cursor !== cursor) || (parseInt(d.linked, 10) || 0) > 0 || (parseInt(d.checked, 10) || 0) > 0;
				cursor = d.cursor;
				if (d.done || rounds >= 500 || !progressed) { finish(); } else { step(); }
			});
		}
		step();
	}

	/* ------------------------------------------------------------ mapping */

	function selectedIds() { return $$('.rkc-sel:checked').map(function (c) { return c.value; }); }

	function syncSelectAll() {
		var all = document.getElementById('rkc-all');
		if (!all) { return; }
		var boxes = $$('.rkc-sel');
		var n = boxes.filter(function (b) { return b.checked; }).length;
		all.checked = boxes.length > 0 && n === boxes.length;
		all.indeterminate = n > 0 && n < boxes.length;
	}

	function candidate(item, wooId, source, row, msg) {
		var card = h('div', { 'class': 'rkc-cand' });
		var img = safeUrl(item.image);
		card.appendChild(img ? h('img', { 'class': 'rkc-thumb', src: img, alt: '', width: 48, height: 48, loading: 'lazy' }) : h('span', { 'class': 'rkc-thumb rkc-thumb--empty', 'aria-hidden': 'true' }));

		var info = h('div');
		var link = safeUrl(item.url);
		info.appendChild(link ? h('a', { 'class': 'rkc-cand__name', href: link, target: '_blank', rel: 'noopener noreferrer', text: item.name }) : h('span', { 'class': 'rkc-cand__name', text: item.name }));
		info.appendChild(h('div', { 'class': 'rkc-meta', text: fmt(t.candMeta, item.sku || '—', item.id) + (item.base ? ' · ' + fmt(t.candBase, item.base) : '') }));
		var tags = h('div', { 'class': 'rkc-badges' });
		if (item.type === 'variable') { tags.appendChild(badge(t.hasVariations, 'info')); }
		else { tags.appendChild(badge(item.ok ? t.inStock : t.outOfStock, item.ok ? 'good' : 'warn')); }
		if (item.confidence) { tags.appendChild(badge((t.match && t.match[item.confidence]) || item.confidence, item.confidence === 'high' ? 'good' : (item.confidence === 'medium' ? 'warn' : 'neutral'))); }
		info.appendChild(tags);
		if (item.missing && item.missing.length) { info.appendChild(h('div', { 'class': 'rkc-meta', text: fmt(t.notInName, item.missing.join(', ')) })); }
		card.appendChild(info);

		var select = null;
		var btn = h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--small rkc-btn--primary', text: t.useThis });
		card.appendChild(btn);
		if (item.type === 'variable') {
			select = h('select', { 'class': 'rkc-input', 'aria-label': t.chooseVariation });
			select.appendChild(h('option', { value: '', text: t.chooseVariation }));
			(item.variations || []).forEach(function (v) {
				var label = (v.label || ('#' + v.id)) + (v.sku ? ' · ' + v.sku : '') + (v.base ? ' · ' + v.base : '') + (v.ok ? '' : ' · ' + t.outOfStock);
				var o = h('option', { value: v.id, text: label });
				if (item.matched_vid && item.matched_vid === v.id) { o.selected = true; }
				select.appendChild(o);
			});
			btn.disabled = !select.value;
			select.addEventListener('change', function () { btn.disabled = !select.value; });
			card.appendChild(h('div', { 'class': 'rkc-cand__vars' }, select));
		}

		btn.addEventListener('click', function () {
			busy(btn, true);
			post('map_link', { woo_id: wooId, rk_product_id: item.id, rk_variation_id: select ? select.value : 0, source: source }).then(function (res) {
				busy(btn, false);
				if (!res.success) { show(msg, notice('error', message(res))); return; }
				var tmp = document.createElement('tbody');
				tmp.innerHTML = res.data.html; // Markup produced and escaped by the plugin's PHP.
				var fresh = tmp.firstElementChild;
				if (!fresh) { window.location.reload(); return; }
				var after = row.nextElementSibling;
				if (after && after.classList.contains('rkc-linkrow')) { after.remove(); }
				row.replaceWith(fresh);
				var cell = $('.rkc-map', fresh);
				if (cell) { cell.appendChild(notice('success', res.data.message)); }
				var again = $('[data-rkc="open-link"]', fresh);
				if (again) { again.focus(); }
			});
		});
		return card;
	}

	function fillCandidates(col, items, wooId, source, row, msg, emptyText) {
		var list = $('.rkc-lp__list', col);
		list.textContent = '';
		if (!items || !items.length) { list.appendChild(h('p', { 'class': 'rkc-muted', text: emptyText })); return; }
		items.forEach(function (it) { list.appendChild(candidate(it, wooId, source, row, msg)); });
	}

	function closeLinkPanel(row, btn) {
		var next = row && row.nextElementSibling;
		if (next && next.classList.contains('rkc-linkrow')) { next.remove(); }
		if (row) { row.classList.remove('is-linking'); }
		if (btn) { btn.setAttribute('aria-expanded', 'false'); }
	}

	function openLinkPanel(row, btn) {
		var next = row.nextElementSibling;
		if (next && next.classList.contains('rkc-linkrow')) { closeLinkPanel(row, btn); return; }
		var wooId = row.dataset.id;
		row.classList.add('is-linking');
		btn.setAttribute('aria-expanded', 'true');

		var msg = h('div', { 'class': 'rkc-lp__msg', 'aria-live': 'polite' });

		function column(title, withForm, placeholder) {
			var col = h('div', { 'class': 'rkc-lp__col' }, h('h4', { text: title }));
			var input = null, go = null;
			if (withForm) {
				input = h('input', { type: 'search', 'class': 'rkc-input', placeholder: placeholder, 'aria-label': title });
				go = h('button', { type: 'button', 'class': 'rkc-btn', text: t.search });
				col.appendChild(h('div', { 'class': 'rkc-lp__form' }, input, go));
			}
			col.appendChild(h('div', { 'class': 'rkc-lp__list', 'aria-live': 'polite' }, h('p', { 'class': 'rkc-muted', text: t.working })));
			return { el: col, input: input, go: go };
		}

		var cSku = column(t.colExact, false);
		var cName = column(t.colSimilar, true, t.keywords);
		var cFind = column(t.colFind, true, t.findHint);
		var panel = h('div', { 'class': 'rkc-lp' }, cSku.el, cName.el, cFind.el);
		row.after(h('tr', { 'class': 'rkc-linkrow' }, h('td', { colspan: 4 }, panel, msg)));

		// 1. Exact SKU.
		post('map_sku', { woo_id: wooId }).then(function (res) {
			var list = $('.rkc-lp__list', cSku.el);
			list.textContent = '';
			if (!res.success) { list.appendChild(notice('error', message(res))); return; }
			if (res.data.state === 'no_sku') { list.appendChild(h('p', { 'class': 'rkc-muted', text: t.noSku })); return; }
			if (res.data.state !== 'exact') { list.appendChild(h('p', { 'class': 'rkc-muted', text: t.noSkuMatch })); return; }
			fillCandidates(cSku.el, [res.data.item], wooId, 'sku', row, msg, t.noSkuMatch);
			if (res.data.review) { list.insertBefore(notice('warning', t.skuMismatch), list.firstChild); }
		});

		// 2. Similar names (keywords come from the product name; the person can edit them).
		function runSuggest() {
			var list = $('.rkc-lp__list', cName.el);
			list.textContent = '';
			list.appendChild(h('p', { 'class': 'rkc-muted', text: t.working }));
			post('map_suggest', { woo_id: wooId, keywords: cName.input.value }).then(function (res) {
				if (!res.success) { list.textContent = ''; list.appendChild(notice('error', message(res))); return; }
				if (!cName.input.value) { cName.input.value = res.data.keywords; }
				fillCandidates(cName.el, res.data.items, wooId, 'keyword', row, msg, t.noResults);
			});
		}
		cName.go.addEventListener('click', runSuggest);
		cName.input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSuggest(); } });
		runSuggest();

		// 3. Free search, a ResellingKori ID or an exact SKU.
		function runSearch() {
			var list = $('.rkc-lp__list', cFind.el);
			if (cFind.input.value.trim().length < 2) { return; }
			list.textContent = '';
			list.appendChild(h('p', { 'class': 'rkc-muted', text: t.working }));
			post('map_search', { woo_id: wooId, q: cFind.input.value.trim() }).then(function (res) {
				if (!res.success) { list.textContent = ''; list.appendChild(notice('error', message(res))); return; }
				fillCandidates(cFind.el, res.data.items, wooId, 'manual', row, msg, t.noResults);
			});
		}
		cFind.go.addEventListener('click', runSearch);
		cFind.input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
		$('.rkc-lp__list', cFind.el).textContent = '';
	}

	/** unlink / confirm / etc. on one or more rows; the server leaves a flash message, the page reloads to show it. */
	function bulkRequest(action, ids, btn, extra) {
		if (!ids.length) { return; }
		busy(btn, true);
		post(action, Object.assign({ ids: ids }, extra || {})).then(function (res) {
			if (!res.success) { busy(btn, false); flashTop('error', message(res)); return; }
			window.location.reload();
		});
	}

	/* -------------------------------------------------------- order panel */

	var reviewSeq = 0;

	function panelWrap(el) { return el.closest('.rkc-panel'); }
	function panelHost(el) { return el.closest('#rkc-panel-wrap') || panelWrap(el).parentNode; }
	function orderId(el) { return panelWrap(el).dataset.orderId; }
	function msgBox(el) { return $('.rkc-panel__msg', panelWrap(el)); }

	function replacePanel(el, html, kind, text) {
		var host = panelHost(el);
		if (html) { host.innerHTML = html; } // Markup produced and escaped by the plugin's PHP.
		var m = $('.rkc-panel__msg', host);
		if (m && text) { show(m, notice(kind, text)); }
	}

	function closeReview(box) {
		var panel = panelWrap(box);
		reviewSeq++;
		box.hidden = true;
		box.textContent = '';
		box.removeAttribute('aria-busy');
		var first = $('[data-rkc="order-review"], [data-rkc="order-cancel"]', panel);
		if (first) { first.focus(); }
	}

	/** The person's choices. A partial order sends the ticked items; "nothing ticked" must reach the server as an empty list. */
	function collectOpts(review) {
		var o = {};
		var boxes = $$('.rkc-rv-sel', review);
		if (boxes.length) {
			o.item_ids = boxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
			if (!o.item_ids.length) { o.item_ids = ['0']; }
		}
		var d = $('.rkc-rv-delivery', review);
		if (d) { o.delivery = d.value.trim(); }
		var oc = $('.rkc-rv-other', review);
		if (oc) { o.include_other = oc.checked ? '1' : '0'; }
		return o;
	}

	/** Asks the server for the proposal. refresh=true re-reads prices and stock from ResellingKori. */
	function reviewOrder(el, refresh, firstTime) {
		var panel = panelWrap(el);
		var review = $('.rkc-review', panel);
		var opts = firstTime ? {} : collectOpts(review);
		var mine = ++reviewSeq;
		if (!firstTime) {
			review.setAttribute('aria-busy', 'true');
			var s = $('[data-rkc="order-send"]', review);
			if (s) { s.disabled = true; }
		}
		if (el.tagName === 'BUTTON') { busy(el, true); }
		post('order_review', Object.assign({ order_id: orderId(el), refresh: refresh ? '1' : '0' }, opts)).then(function (res) {
			busy(el, false);
			if (mine !== reviewSeq) { return; } // A newer request is on its way; ignore this answer.
			review.removeAttribute('aria-busy');
			if (!res.success) { show($('.rkc-panel__msg', panel), notice('error', message(res))); return; }
			show($('.rkc-panel__msg', panel), null);
			renderReview(panel, review, res.data.draft, firstTime);
		});
	}

	function renderReview(panel, box, d, focusTitle) {
		var active = document.activeElement;
		var key = (!focusTitle && active && box.contains(active)) ? safeKey(active.getAttribute('data-key')) : '';
		box.hidden = false;
		box.textContent = '';

		var title = h('h4', { text: t.rvTitle, tabindex: -1 });
		box.appendChild(title);
		if (d.errors.length) { box.appendChild(notice('error', t.rvErrors, d.errors)); }
		if (d.warnings.length) { box.appendChild(notice('warning', t.rvWarnings, d.warnings)); }
		if (d.partial) { box.appendChild(h('p', { 'class': 'rkc-muted', text: t.rvPartial })); }

		d.lines.forEach(function (l) {
			var row = h('div', { 'class': 'rkc-rv-line' + (l.selected ? '' : ' is-off') });
			if (d.partial && l.mapped) {
				var cb = h('input', { type: 'checkbox', 'class': 'rkc-rv-sel', value: l.item_id, 'data-key': 'sel' + l.item_id, 'aria-label': l.name });
				cb.checked = l.selected;
				row.appendChild(cb);
			} else {
				row.appendChild(h('span', { 'aria-hidden': 'true', text: l.mapped ? '✔' : '–' }));
			}
			var name = h('span', null, h('strong', { text: l.name }), ' × ' + l.qty + ' ');
			if (!l.mapped) { name.appendChild(badge(l.needs_review ? t.rvNeedsReview : t.rvNotRk, l.needs_review ? 'warn' : 'neutral')); }
			if (l.available === false) { name.appendChild(badge(t.outOfStock, 'bad')); }
			row.appendChild(name);
			if (l.mapped) {
				var sub = h('span', { 'class': 'rkc-rv-line__sub' });
				var bits = [fmt(t.rvSellAt, l.sell_unit)];
				if (l.base_unit) { bits.push(fmt(t.rvRkPrice, l.base_unit)); }
				sub.appendChild(document.createTextNode(bits.join(' · ')));
				if (l.profit) {
					sub.appendChild(document.createTextNode(' · '));
					sub.appendChild(h('span', { 'class': l.loss ? 'rkc-rv-line__loss' : 'rkc-rv-line__gain', text: fmt(l.loss ? t.rvLoss : t.rvProfit, l.profit) }));
				}
				row.appendChild(sub);
				if (l.rk_name) { row.appendChild(h('span', { 'class': 'rkc-rv-line__sub', text: fmt(t.rvRkName, l.rk_name) })); }
			}
			box.appendChild(row);
		});

		var m = d.money;

		// Delivery charge: the one amount the person may change. The server validates it again.
		box.appendChild(h('div', { 'class': 'rkc-rv-field' },
			h('label', { 'for': 'rkc-rv-delivery', text: t.rvDelivery }),
			h('br'),
			h('input', { type: 'text', inputmode: 'decimal', id: 'rkc-rv-delivery', 'class': 'rkc-input rkc-rv-delivery', value: m.delivery_input, 'data-key': 'delivery', autocomplete: 'off' })
		));

		if (m.other_shown) {
			var oc = h('input', { type: 'checkbox', 'class': 'rkc-rv-other', 'data-key': 'other' });
			oc.checked = m.include_other;
			box.appendChild(h('label', { 'class': 'rkc-check' }, oc, ' ' + fmt(m.other_credit ? t.rvOtherCredit : t.rvOtherCollect, m.other_amount)));
			if (!m.other_credit) { box.appendChild(h('p', { 'class': 'rkc-muted', text: t.rvOtherNote })); }
		}

		var tbl = h('table', { 'class': 'rkc-rv-table' });
		function line(label, value, cls) { tbl.appendChild(h('tr', { 'class': cls || '' }, h('td', { text: label }), h('td', { text: value }))); }
		line(t.rvSelling, m.selling);
		line(t.rvDeliveryRow, m.delivery);
		if (m.other_charge) { line(t.rvOtherRow, m.other_charge); }
		if (m.rounding) { line(t.rvRoundingRow, m.rounding); }
		line(t.rvTotal, m.order_total, 'rkc-rv-total');
		line(m.method === 'prepaid' ? t.rvPaidPrepaid : t.rvPaid, m.prepaid);
		line(t.rvCod, m.method === 'prepaid' ? t.none : m.cod, 'rkc-rv-cod');
		if (m.profit) { line(t.rvProfitRow, m.profit); }
		box.appendChild(tbl);

		var c = d.customer;
		box.appendChild(h('h4', { text: t.rvDeliverTo }));
		box.appendChild(h('p', null, h('strong', { text: c.name }), h('br'), c.phone, h('br'), c.address));

		box.appendChild(h('p', { 'class': 'rkc-muted', text: t.rvHint }));
		var send = h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--primary', 'data-rkc': 'order-send', 'data-fp': d.fingerprint, 'data-key': 'send', text: t.rvSend });
		if (!d.can_send) { send.disabled = true; }
		var close = h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--ghost', 'data-rkc': 'order-close', 'data-key': 'close', text: t.close });
		box.appendChild(h('div', { 'class': 'rkc-panel__actions' }, send, ' ', close));

		var target = key ? $('[data-key="' + key + '"]', box) : null;
		if (target && !target.disabled) { target.focus(); } else if (focusTitle || key) { title.focus(); }
	}

	/** Inline cancellation form (no browser prompt): the reason is optional, nothing happens until the button is pressed. */
	function renderCancel(el) {
		var panel = panelWrap(el);
		var box = $('.rkc-review', panel);
		reviewSeq++;
		box.hidden = false;
		box.textContent = '';
		var title = h('h4', { text: t.cancelTitle, tabindex: -1 });
		box.appendChild(title);
		box.appendChild(h('p', { 'class': 'rkc-muted', text: t.cancelHelp }));
		box.appendChild(h('p', null,
			h('label', { 'for': 'rkc-cancel-reason', text: t.cancelWhy }),
			h('br'),
			h('input', { type: 'text', id: 'rkc-cancel-reason', 'class': 'rkc-input', maxlength: 200, autocomplete: 'off' })
		));
		box.appendChild(h('div', { 'class': 'rkc-panel__actions' },
			h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--danger', 'data-rkc': 'order-cancel-do', text: t.cancelDo }),
			' ',
			h('button', { type: 'button', 'class': 'rkc-btn rkc-btn--ghost', 'data-rkc': 'order-close', text: t.cancelKeep })
		));
		title.focus();
	}

	/* ------------------------------------------------------ click handling */

	// Confirmation prompts on forms and buttons that carry data-confirm (capture phase, so they win over everything else).
	document.addEventListener('click', function (e) {
		var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
		if (el && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
	}, true);

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-rkc]') : null;
		if (!btn) { return; }
		var act = btn.getAttribute('data-rkc');
		var row, ids;

		switch (act) {
			case 'verify': {
				var target = document.getElementById(btn.dataset.target);
				var pill = $('.rkc-header__pill');
				var before = pill ? pill.getAttribute('data-status') : '';
				busy(btn, true);
				post('verify').then(function (res) {
					busy(btn, false);
					if (!res.success) { show(target, notice('error', message(res))); return; }
					renderVerify(target, res.data.result);
					// The header pill, and the wizard's Continue button, follow the new state after a short pause.
					if ((btn.dataset.next && res.data.result.status === 'connected') || (pill && before !== res.data.pill)) {
						window.setTimeout(function () { window.location.reload(); }, 1400);
					}
				});
				break;
			}
			case 'copy': {
				var ta = document.getElementById(btn.dataset.copy);
				var ok = function () { btn.textContent = t.copied; };
				var bad = function () { btn.textContent = t.copyFailed; };
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(ta.value).then(ok, bad); }
				else { try { ta.removeAttribute('aria-hidden'); ta.select(); document.execCommand('copy') ? ok() : bad(); } catch (err) { bad(); } }
				break;
			}

			case 'open-link':
				openLinkPanel(btn.closest('tr'), btn);
				break;
			case 'unlink':
				row = btn.closest('tr');
				if (window.confirm(t.confirmUnlink)) { bulkRequest('map_unlink', [row.dataset.id], btn); }
				break;
			case 'confirm':
				bulkRequest('map_confirm', [btn.closest('tr').dataset.id], btn);
				break;
			case 'bulk-unlink':
				ids = selectedIds();
				if (ids.length && window.confirm(t.confirmUnlink)) { bulkRequest('map_unlink', ids, btn); }
				break;
			case 'bulk-confirm':
				bulkRequest('map_confirm', selectedIds(), btn);
				break;
			case 'bulk-sku-selected':
				bulkRequest('map_bulk_sku', selectedIds(), btn, { mode: 'ids' });
				break;
			case 'bulk-sku-all':
				loop('map_bulk_sku', document.getElementById(btn.dataset.target),
					function (n) { return fmt(t.loopSku, n.linked, n.review, n.not_found); },
					function (n) { return fmt(t.loopSkuDone, n.linked, n.review, n.not_found); });
				break;
			case 'refresh-availability':
				loop('map_refresh', document.getElementById(btn.dataset.target),
					function (n) { return fmt(t.loopCheck, n.checked, n.unavailable); },
					function (n) { return fmt(t.loopCheckDone, n.checked, n.unavailable); });
				break;

			case 'order-review':
				reviewOrder(btn, true, true);
				break;
			case 'order-close':
				closeReview($('.rkc-review', panelWrap(btn)));
				break;
			case 'order-send': {
				var panel = panelWrap(btn);
				var review = $('.rkc-review', panel);
				var data = Object.assign({ order_id: orderId(btn), fingerprint: btn.dataset.fp }, collectOpts(review));
				reviewSeq++; // Any proposal request still in flight is obsolete.
				busy(btn, true);
				post('order_send', data).then(function (res) {
					if (res.success) { replacePanel(btn, res.data.panel, 'success', res.data.message); return; }
					var d = res.data || {};
					if (d.panel && (d.stage === 'unknown' || d.stage === 'rejected')) {
						replacePanel(btn, d.panel, d.stage === 'unknown' ? 'warning' : 'error', d.message);
						return;
					}
					busy(btn, false);
					show($('.rkc-panel__msg', panel), notice('error', message(res), d.errors));
					if (d.code === 'rkc_changed') { reviewOrder(btn, true, false); } // Something moved: show the new amounts.
				});
				break;
			}
			case 'order-recover':
			case 'order-refresh':
				busy(btn, true);
				post(act === 'order-recover' ? 'order_recover' : 'order_refresh', { order_id: orderId(btn) }).then(function (res) {
					busy(btn, false);
					if (!res.success) { show(msgBox(btn), notice('error', message(res))); return; }
					replacePanel(btn, res.data.panel, 'success', res.data.message);
				});
				break;
			case 'order-cancel':
				renderCancel(btn);
				break;
			case 'order-cancel-do': {
				var cpanel = panelWrap(btn);
				var reason = $('#rkc-cancel-reason', cpanel);
				busy(btn, true);
				post('order_cancel', { order_id: orderId(btn), reason: reason ? reason.value : '' }).then(function (res) {
					if (!res.success) {
						busy(btn, false);
						var d = res.data || {};
						if (d.panel) { replacePanel(btn, d.panel, 'error', d.message); } else { show(msgBox(btn), notice('error', message(res))); }
						return;
					}
					replacePanel(btn, res.data.panel, 'success', res.data.message);
				});
				break;
			}
		}
	});

	document.addEventListener('change', function (e) {
		var el = e.target;
		if (el.id === 'rkc-all') {
			$$('.rkc-sel').forEach(function (c) { c.checked = el.checked; });
			el.indeterminate = false;
			return;
		}
		if (el.classList && el.classList.contains('rkc-sel')) { syncSelectAll(); return; }
		// A choice in the order review: ask the server for the new amounts.
		if (el.closest && el.closest('.rkc-review') && el.classList && (el.classList.contains('rkc-rv-sel') || el.classList.contains('rkc-rv-delivery') || el.classList.contains('rkc-rv-other'))) {
			reviewOrder(el, false, false);
		}
	});

	// Enter inside the delivery box updates the amounts instead of doing nothing; Escape closes the open box.
	document.addEventListener('keydown', function (e) {
		var el = e.target;
		if (e.key === 'Enter' && el.classList && el.classList.contains('rkc-rv-delivery')) { e.preventDefault(); reviewOrder(el, false, false); return; }
		if (e.key === 'Escape' && el.closest) {
			var rv = el.closest('.rkc-review');
			if (rv && !rv.hidden) { e.preventDefault(); closeReview(rv); return; }
			var lp = el.closest('.rkc-linkrow');
			if (lp) {
				e.preventDefault();
				var prev = lp.previousElementSibling;
				var toggle = prev ? $('[data-rkc="open-link"]', prev) : null;
				closeLinkPanel(prev, toggle);
				if (toggle) { toggle.focus(); }
			}
		}
	});
})();
