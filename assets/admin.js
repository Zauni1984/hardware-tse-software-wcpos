/* Hardware-TSE admin helpers: REST client and the small forms (Kassenbuch, Abschluss, DSFinV-K, Journal). */
(function () {
	'use strict';

	var data = window.HWTSE_DATA || {};

	function api(method, path, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': data.nonce, 'Accept': 'application/json' }
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(data.rest + path, opts).then(function (res) {
			return res.text().then(function (text) {
				var json = {};
				try { json = text ? JSON.parse(text) : {}; } catch (e) { json = { message: text }; }
				if (!res.ok) {
					var err = new Error(json.message || ('HTTP ' + res.status));
					err.status = res.status;
					err.code = json.code || '';
					throw err;
				}
				return json;
			});
		});
	}
	window.HWTSE_API = api;

	function result(form, text, ok) {
		var el = form.querySelector('.hwtse-result') || (form.parentNode && form.parentNode.querySelector('.hwtse-result'));
		if (el) {
			el.textContent = text;
			el.className = 'hwtse-result ' + (ok ? 'ok' : 'err');
		}
	}

	function money(v) {
		return Number(v || 0).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
	}

	document.addEventListener('DOMContentLoaded', function () {
		var cash = document.getElementById('hwtse-cashbook');
		if (cash) {
			cash.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var fd = new FormData(cash);
				var btn = cash.querySelector('button');
				btn.disabled = true;
				api('POST', '/cashbook', { reason: fd.get('reason'), amount: fd.get('amount'), text: fd.get('text') })
					.then(function (r) {
						result(cash, 'Gebucht (Bon-Nr. ' + r.row.id + ', ' + money(r.row.total) + '). Die TSE signiert, sobald der TSE-Agent läuft.', true);
						cash.reset();
						setTimeout(function () { location.reload(); }, 1500);
					})
					.catch(function (e) { result(cash, e.message, false); })
					.finally(function () { btn.disabled = false; });
			});
		}

		var closing = document.getElementById('hwtse-closing');
		if (closing) {
			closing.addEventListener('click', function () {
				if (!window.confirm('Kassenabschluss jetzt erstellen? Alle abgeschlossenen Vorgänge seit dem letzten Abschluss werden festgeschrieben.')) {
					return;
				}
				closing.disabled = true;
				api('POST', '/closing', {})
					.then(function (r) {
						result(closing.parentNode, r.closing ? 'Z-Abschluss Nr. ' + r.closing.z_nr + ' erstellt.' : r.message, true);
						if (r.closing) { setTimeout(function () { location.reload(); }, 1200); }
					})
					.catch(function (e) { result(closing.parentNode, e.message, false); })
					.finally(function () { closing.disabled = false; });
			});
		}

		var dsfinvk = document.getElementById('hwtse-dsfinvk');
		if (dsfinvk) {
			dsfinvk.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var fd = new FormData(dsfinvk);
				var btn = dsfinvk.querySelector('button');
				btn.disabled = true;
				result(dsfinvk, 'Export wird erstellt …', true);
				api('POST', '/dsfinvk', { from: fd.get('from'), to: fd.get('to') })
					.then(function (r) {
						result(dsfinvk, 'Erstellt: ' + r.file + ' (' + r.closings + ' Abschlüsse).', true);
						setTimeout(function () { location.reload(); }, 1200);
					})
					.catch(function (e) { result(dsfinvk, e.message, false); })
					.finally(function () { btn.disabled = false; });
			});
		}

		document.querySelectorAll('.hwtse-give-up').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!window.confirm('Vorgang ' + btn.dataset.id + ' als TSE-Ausfall abschließen? Er wird danach nicht mehr signiert.')) {
					return;
				}
				btn.disabled = true;
				api('POST', '/journal/' + btn.dataset.id + '/give-up', {})
					.then(function () { location.reload(); })
					.catch(function (e) { window.alert(e.message); btn.disabled = false; });
			});
		});
	});
})();
