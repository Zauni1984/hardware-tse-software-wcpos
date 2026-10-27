/*
 * Hardware-TSE agent.
 *
 * Runs in the browser at the cash register, talks to the TSE inside the Epson
 * printer through the Epson ePOS SDK for JavaScript (ePOS-Device, device
 * "local_TSE", JSON commands of the "Germany Fiscal Element JSON
 * Specification") and works through the jobs the plugin queues on the server.
 */
(function () {
	'use strict';

	var DATA = window.HWTSE_DATA || {};
	var api = window.HWTSE_API;
	var root = document.getElementById('hwtse-agent');
	if (!root || !api) {
		return;
	}

	/* ---------- helpers ---------- */

	var AUTH_ERRORS = [
		'OTHER_ERROR_UNAUTHENTICATED_TIME_ADMIN_USER',
		'OTHER_ERROR_UNAUTHENTICATED_USER',
		'TSE1_ERROR_AUTHENTICATION_USER_NOT_LOGGED_IN',
		'TSE1_ERROR_NOT_AUTHORIZED'
	];
	var ADMIN_AUTH_ERRORS = ['OTHER_ERROR_UNAUTHENTICATED_ADMIN_USER'];
	var TIME_ERRORS = ['TSE1_ERROR_NO_TIME_SET'];
	var SELFTEST_ERRORS = ['TSE1_ERROR_WRONG_STATE_NEEDS_SELF_TEST', 'TSE1_ERROR_WRONG_STATE_NEEDS_SELF_TEST_PASSED'];
	var UNLOCK_ERRORS = ['TSE1_ERROR_WRONG_STATE_NEEDS_TSE_UNLOCK'];

	function storageGet(key, fallback) {
		try {
			var v = window.localStorage.getItem(key);
			return v === null ? fallback : v;
		} catch (e) {
			return fallback;
		}
	}

	function storageSet(key, value) {
		try {
			if (value === null) {
				window.localStorage.removeItem(key);
			} else {
				window.localStorage.setItem(key, value);
			}
		} catch (e) { /* private mode */ }
	}

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return 'a' + Date.now().toString(16) + Math.random().toString(16).slice(2);
	}

	var AGENT_ID = storageGet('hwtse_agent_id', '');
	if (!AGENT_ID) {
		AGENT_ID = uuid();
		storageSet('hwtse_agent_id', AGENT_ID);
	}

	function sleep(ms) {
		return new Promise(function (r) { setTimeout(r, ms); });
	}

	function nowIso() {
		return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
	}

	function utf8ToBase64(s) {
		var bytes = new TextEncoder().encode(s);
		var bin = '';
		for (var i = 0; i < bytes.length; i++) {
			bin += String.fromCharCode(bytes[i]);
		}
		return window.btoa(bin);
	}

	function sha256Base64(text) {
		return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
			var bytes = new Uint8Array(buf);
			var bin = '';
			for (var i = 0; i < bytes.length; i++) {
				bin += String.fromCharCode(bytes[i]);
			}
			return window.btoa(bin);
		});
	}

	function TseError(code, message, detail) {
		this.name = 'TseError';
		this.code = code || 'UNKNOWN';
		this.message = message || this.code;
		this.detail = detail || null;
	}
	TseError.prototype = Object.create(Error.prototype);

	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* ---------- log ---------- */

	var logLines = [];
	function renderLog() {
		var el = root.querySelector('.hwtse-log');
		if (el) {
			el.innerHTML = logLines.map(function (l) { return '<div class="' + l.cls + '">' + esc(l.text) + '</div>'; }).join('');
		}
	}

	function log(text, cls) {
		var line = new Date().toLocaleTimeString('de-DE') + '  ' + text;
		logLines.unshift({ text: line, cls: cls || '' });
		logLines = logLines.slice(0, 300);
		renderLog();
		if (window.console) {
			window.console[cls === 'err' ? 'warn' : 'log']('[HWTSE] ' + text);
		}
	}

	/* ---------- Epson ePOS-Device connection ---------- */

	function EposTse() {
		this.dev = null;
		this.gfe = null;
		this.vendor = '';
		this.queue = Promise.resolve();
		this.pending = null;
		this.connected = false;
		this.onstate = function () {};
	}

	EposTse.prototype.connect = function (host, port, deviceId) {
		var self = this;
		if (typeof window.epson === 'undefined' || !window.epson.ePOSDevice) {
			return Promise.reject(new TseError('NO_SDK', 'Epson ePOS SDK nicht geladen (Einstellungen → SDK-Datei hochladen).'));
		}
		this.disconnect();
		return new Promise(function (resolve, reject) {
			var dev = new window.epson.ePOSDevice();
			self.dev = dev;
			dev.onreconnecting = function () { self.connected = false; self.onstate('reconnecting'); };
			dev.onreconnect = function () { self.connected = true; self.onstate('reconnected'); };
			dev.ondisconnect = function () {
				self.connected = false;
				self.gfe = null;
				self.failPending(new TseError('DISCONNECTED', 'Verbindung zum Drucker getrennt.'));
				self.onstate('disconnected');
			};
			dev.connect(host, port, function (result) {
				if (result !== 'OK' && result !== 'SSL_CONNECT_OK') {
					reject(new TseError('CONNECT_' + result, 'Keine Verbindung zum Drucker ' + host + ':' + port + ' (' + result + '). Zertifikat akzeptiert? Drucker im selben Netz?'));
					return;
				}
				dev.createDevice(deviceId, dev.DEVICE_TYPE_GFE, { crypto: false, buffer: false }, function (obj, code) {
					if (code !== 'OK' || !obj) {
						var msg = code === 'DEVICE_IN_USE' ? 'Die TSE wird bereits von einem anderen Browser/Programm benutzt.' : 'TSE-Gerät „' + deviceId + '“ nicht verfügbar (' + code + ').';
						reject(new TseError('DEVICE_' + code, msg));
						return;
					}
					self.gfe = obj;
					obj.ongfereceive = function (res) { self.receive(res); };
					obj.onerror = function (err) {
						self.failPending(new TseError('SDK_ERROR', String(err && err.message ? err.message : err)));
					};
					self.connected = true;
					self.onstate('connected');
					resolve();
				});
			});
		});
	};

	EposTse.prototype.disconnect = function () {
		if (this.dev) {
			try {
				if (this.gfe) {
					this.dev.deleteDevice(this.gfe, function () {});
				}
				this.dev.disconnect();
			} catch (e) { /* ignore */ }
		}
		this.dev = null;
		this.gfe = null;
		this.connected = false;
	};

	EposTse.prototype.failPending = function (err) {
		if (this.pending) {
			clearTimeout(this.pending.timer);
			var p = this.pending;
			this.pending = null;
			p.reject(err);
		}
	};

	EposTse.prototype.receive = function (res) {
		if (!this.pending) {
			return;
		}
		var p = this.pending;
		this.pending = null;
		clearTimeout(p.timer);
		if (!res || !res.success) {
			p.reject(new TseError((res && res.code) || 'DEVICE_ERROR', 'Druckerfehler bei ' + p.fn + ': ' + ((res && res.code) || 'unbekannt')));
			return;
		}
		var json;
		try {
			json = typeof res.resultdata === 'string' ? JSON.parse(res.resultdata) : res.resultdata;
		} catch (e) {
			p.reject(new TseError('BAD_RESPONSE', 'Unlesbare Antwort der TSE bei ' + p.fn));
			return;
		}
		if (!json || json.result !== 'EXECUTION_OK') {
			var code = (json && json.result) || 'UNKNOWN';
			p.reject(new TseError(code, p.fn + ': ' + code + (json && json.error && json.error.fact ? ' (' + json.error.fact + ')' : ''), json));
			return;
		}
		p.resolve(json.output && typeof json.output === 'object' ? json.output : {});
	};

	/** Send one JSON command; commands are strictly serialised. */
	EposTse.prototype.send = function (fn, input, timeoutMs) {
		var self = this;
		timeoutMs = timeoutMs || 30000;
		var run = function () {
			return new Promise(function (resolve, reject) {
				if (!self.gfe || !self.connected) {
					reject(new TseError('NOT_CONNECTED', 'Nicht mit der TSE verbunden.'));
					return;
				}
				var common = fn === 'GetStorageInfo' && !self.vendor;
				var request = {
					storage: { type: common ? 'COMMON' : 'TSE', vendor: common ? '' : self.vendor },
					'function': fn,
					input: input || {},
					compress: { required: false, type: '' }
				};
				self.pending = {
					fn: fn,
					resolve: resolve,
					reject: reject,
					timer: setTimeout(function () {
						self.failPending(new TseError('TIMEOUT', 'Zeitüberschreitung bei ' + fn));
					}, timeoutMs + 5000)
				};
				try {
					self.gfe.operate(JSON.stringify(request), timeoutMs);
				} catch (e) {
					self.failPending(new TseError('SDK_ERROR', String(e && e.message ? e.message : e)));
				}
			});
		};
		var result = this.queue.then(run, run);
		this.queue = result.catch(function () {});
		return result;
	};

	/* ---------- TSE operations ---------- */

	function Tse(config) {
		this.cfg = config;
		this.epos = new EposTse();
		this.storage = null;
	}

	Tse.prototype.info = function () {
		var self = this;
		return this.epos.send('GetStorageInfo', {}, 20000).then(function (out) {
			if (out.vendorType) {
				self.epos.vendor = out.vendorType;
			}
			self.storage = out.tseInformation || {};
			self.storage.vendorType = out.vendorType || self.epos.vendor;
			return self.storage;
		});
	};

	Tse.prototype.hash = function (userId) {
		var self = this;
		return this.epos.send('GetChallenge', { userId: userId }).then(function (out) {
			return sha256Base64(String(out.challenge || '') + self.cfg.secret_key);
		});
	};

	Tse.prototype.authTimeAdmin = function () {
		var self = this;
		if (!this.cfg.time_admin_pin) {
			return Promise.reject(new TseError('NO_PIN', 'TimeAdmin-PIN fehlt (TSE einrichten oder PIN in den Einstellungen eintragen).'));
		}
		return this.hash(this.cfg.client_id).then(function (hash) {
			return self.epos.send('AuthenticateUserForTimeAdmin', { clientId: self.cfg.client_id, pin: self.cfg.time_admin_pin, hash: hash });
		}).then(function () {
			log('Als TimeAdmin angemeldet (' + self.cfg.client_id + ').', 'ok');
		});
	};

	Tse.prototype.authAdmin = function () {
		var self = this;
		if (!this.cfg.admin_pin) {
			return Promise.reject(new TseError('NO_PIN', 'Admin-PIN fehlt.'));
		}
		return this.hash('Administrator').then(function (hash) {
			return self.epos.send('AuthenticateUserForAdmin', { userId: 'Administrator', pin: self.cfg.admin_pin, hash: hash });
		}).then(function () {
			log('Als Admin angemeldet.', 'ok');
		});
	};

	Tse.prototype.logoutAdmin = function () {
		return this.epos.send('LogOutForAdmin', {}).catch(function () {});
	};

	Tse.prototype.updateTime = function (first, asAdmin) {
		var fn = first ? 'UpdateTimeForFirst' : 'UpdateTime';
		return this.epos.send(fn, {
			userId: asAdmin ? 'Administrator' : this.cfg.client_id,
			newDateTime: nowIso(),
			useTimeSync: false
		}).then(function () {
			log('TSE-Uhrzeit gesetzt (' + fn + ').', 'ok');
		});
	};

	Tse.prototype.selfTest = function () {
		log('TSE-Selbsttest läuft (bis zu 60 Sekunden) …');
		return this.epos.send('RunTSESelfTest', {}, 120000).then(function () {
			log('TSE-Selbsttest bestanden.', 'ok');
		});
	};

	Tse.prototype.unlock = function () {
		var self = this;
		return this.authAdmin().then(function () {
			return self.epos.send('UnlockTSE', {});
		}).then(function () {
			log('TSE entsperrt.', 'ok');
			return self.logoutAdmin();
		});
	};

	/** Run a TimeAdmin command and fix the usual preconditions (login, time, self-test, lock) on the way. */
	Tse.prototype.withRetry = function (op) {
		var self = this;
		var tries = 0;
		var attempt = function () {
			return op().catch(function (err) {
				if (++tries > 3 || !(err instanceof TseError)) {
					throw err;
				}
				if (AUTH_ERRORS.indexOf(err.code) !== -1) {
					return self.authTimeAdmin().then(attempt);
				}
				if (TIME_ERRORS.indexOf(err.code) !== -1) {
					return self.updateTime(true).then(attempt);
				}
				if (SELFTEST_ERRORS.indexOf(err.code) !== -1) {
					return self.selfTest().then(attempt);
				}
				if (UNLOCK_ERRORS.indexOf(err.code) !== -1) {
					return self.unlock().then(attempt);
				}
				throw err;
			});
		};
		return attempt();
	};

	/** Get the TSE ready for transactions after connecting. */
	Tse.prototype.prepare = function () {
		var self = this;
		return this.info().then(function (s) {
			if (s.tseInitializationState !== 'INITIALIZED') {
				throw new TseError('NOT_INITIALIZED', 'Die TSE ist nicht eingerichtet (Status ' + (s.tseInitializationState || '?') + '). Bitte „Einrichtung“ ausführen.');
			}
			var steps = Promise.resolve();
			if (!s.hasPassedSelfTest) {
				steps = steps.then(function () { return self.selfTest(); });
			}
			if (s.isTSEUnlocked === false) {
				steps = steps.then(function () { return self.unlock(); });
			}
			return steps.then(function () {
				return self.withRetry(function () { return self.updateTime(true); });
			}).then(function () {
				return self.info();
			});
		});
	};

	Tse.prototype.start = function () {
		var self = this;
		return this.withRetry(function () {
			return self.epos.send('StartTransaction', {
				clientId: self.cfg.client_id,
				processData: '',
				processType: '',
				additionalData: ''
			});
		});
	};

	Tse.prototype.finish = function (txNumber, processType, processData) {
		var self = this;
		return this.withRetry(function () {
			return self.epos.send('FinishTransaction', {
				clientId: self.cfg.client_id,
				transactionNumber: Number(txNumber),
				processData: utf8ToBase64(processData),
				processType: processType,
				additionalData: ''
			});
		});
	};

	Tse.prototype.startedList = function () {
		var self = this;
		return this.epos.send('GetStartedTransactionList', { clientId: self.cfg.client_id }).then(function (out) {
			return out.startedTransactionNumberList || [];
		});
	};

	Tse.prototype.certificate = function () {
		return this.epos.send('GetLogMessageCertificate', {}).then(function (out) {
			var pem = '';
			try { pem = window.atob(out.logMessageCertificate || ''); } catch (e) { pem = ''; }
			var m = pem.match(/-----BEGIN CERTIFICATE-----([\s\S]*?)-----END CERTIFICATE-----/);
			return m ? m[1].replace(/\s+/g, '') : '';
		});
	};

	/* ---------- server-side TSE info ---------- */

	function tseInfoPayload(s) {
		var hex = '';
		try {
			var bin = window.atob(s.serialNumber || '');
			for (var i = 0; i < bin.length; i++) {
				hex += ('0' + bin.charCodeAt(i).toString(16)).slice(-2);
			}
		} catch (e) { hex = ''; }
		return {
			vendor_type: s.vendorType || '',
			serial_b64: s.serialNumber || '',
			serial_hex: hex,
			public_key: s.tsePublicKey || '',
			signature_algorithm: s.signatureAlgorithm || '',
			certificate_expiration: s.certificateExpirationDate || '',
			tse_description: s.tseDescription || '',
			initialization_state: s.tseInitializationState || '',
			created_signatures: s.createdSignatures,
			remaining_signatures: s.remainingSignatures,
			max_signatures: s.maxSignatures,
			capacity_blocks: s.tseCapacity,
			used_blocks: s.tseCurrentSize,
			started_transactions: s.startedTransactions,
			max_started_transactions: s.maxStartedTransactions,
			has_valid_time: !!s.hasValidTime,
			has_passed_self_test: !!s.hasPassedSelfTest,
			time_until_next_self_test: s.timeUntilNextSelfTest,
			is_unlocked: !!s.isTSEUnlocked,
			tse_health: s.tseHealth || '',
			last_export: s.lastExportExecutedDate || '',
			software_version: s.softwareVersion,
			hardware_version: s.hardwareVersion,
			cdc_id: s.cdcId || ''
		};
	}

	/* ---------- agent ---------- */

	var state = {
		cfg: null,
		tse: null,
		status: 'idle', // idle | connecting | ready | error | setup
		error: '',
		busy: false,
		exporting: false,
		conflict: false,
		lastJobs: 0,
		signed: 0,
		lastInfo: 0,
		pollAgain: false
	};

	var OUTBOX_KEY = 'hwtse_outbox';
	function outbox() {
		try { return JSON.parse(storageGet(OUTBOX_KEY, '[]')) || []; } catch (e) { return []; }
	}
	function saveOutbox(list) {
		storageSet(OUTBOX_KEY, list.length ? JSON.stringify(list) : null);
	}

	/**
	 * Reports to the server survive reloads: a signed transaction must never get lost.
	 * Resolves with the server answer; rejects with err.rejected (server refused it)
	 * or err.reportFailed (server unreachable, the report stays queued).
	 */
	function report(path, body) {
		var id = uuid();
		var list = outbox();
		list.push({ id: id, path: path, body: body });
		saveOutbox(list);
		return flushOutbox({}).then(function (results) {
			var r = results[id];
			if (r && r.rejected) {
				var e = new Error(r.message);
				e.rejected = true;
				throw e;
			}
			return r ? r.value : undefined;
		}, function (err) {
			err.reportFailed = true;
			throw err;
		});
	}

	function removeFromOutbox(id) {
		saveOutbox(outbox().filter(function (x) { return x.id !== id; }));
	}

	function flushOutbox(results) {
		results = results || {};
		var list = outbox();
		if (!list.length) {
			return Promise.resolve(results);
		}
		var item = list[0];
		return api('POST', item.path, item.body).then(function (res) {
			results[item.id] = { value: res };
			removeFromOutbox(item.id);
			return flushOutbox(results);
		}, function (err) {
			if (err.status && err.status >= 400 && err.status < 500 && err.status !== 401 && err.status !== 403) {
				log('Server lehnt Meldung ab (' + item.path + '): ' + err.message, 'err');
				results[item.id] = { rejected: true, message: err.message };
				removeFromOutbox(item.id);
				return flushOutbox(results);
			}
			throw err;
		});
	}

	function setStatus(status, error) {
		state.status = status;
		state.error = error || '';
		render();
	}

	function connect() {
		if (!state.cfg.printer_host) {
			setStatus('error', 'Drucker-IP fehlt (Einstellungen).');
			return Promise.resolve();
		}
		setStatus('connecting');
		state.tse = new Tse(state.cfg);
		state.tse.epos.onstate = function (s) {
			if (s === 'disconnected') {
				setStatus('error', 'Verbindung zum Drucker getrennt – neuer Versuch …');
				scheduleReconnect();
			} else if (s === 'reconnecting') {
				log('Verbindung unterbrochen, ePOS verbindet neu …');
			} else if (s === 'reconnected') {
				log('Verbindung wieder da.', 'ok');
				setStatus('ready');
			}
		};
		return state.tse.epos.connect(state.cfg.printer_host, state.cfg.printer_port, state.cfg.device_id)
			.then(function () {
				log('Mit Drucker ' + state.cfg.printer_host + ':' + state.cfg.printer_port + ' verbunden.', 'ok');
				return state.tse.info();
			})
			.then(function (s) {
				if (s.tseInitializationState !== 'INITIALIZED') {
					setStatus('setup', 'TSE nicht eingerichtet (' + (s.tseInitializationState || '?') + ').');
					return pushInfo();
				}
				return state.tse.prepare().then(function () {
					setStatus('ready');
					return pushInfo(true);
				}).then(poll);
			})
			.catch(function (err) {
				log(err.message, 'err');
				setStatus('error', err.message);
				scheduleReconnect();
			});
	}

	var reconnectTimer = null;
	var reconnectDelay = 5000;
	function scheduleReconnect() {
		if (reconnectTimer || state.exporting) {
			return;
		}
		reconnectTimer = setTimeout(function () {
			reconnectTimer = null;
			if (state.status !== 'ready' && state.status !== 'setup') {
				connect();
			}
		}, reconnectDelay);
		reconnectDelay = Math.min(60000, reconnectDelay * 2);
	}

	function pushInfo(withCertificate) {
		if (!state.tse || !state.tse.storage) {
			return Promise.resolve();
		}
		var payload = tseInfoPayload(state.tse.storage);
		var cert = withCertificate && !(state.cfg.tse && state.cfg.tse.certificate) ? state.tse.certificate().catch(function () { return ''; }) : Promise.resolve('');
		return cert.then(function (c) {
			if (c) {
				payload.certificate = c;
			}
			state.lastInfo = Date.now();
			return api('POST', '/agent/tse-info', payload);
		}).then(function (res) {
			state.cfg.tse = res.tse || state.cfg.tse;
			reconnectDelay = 5000;
			render();
		}).catch(function (err) {
			log('TSE-Info nicht gespeichert: ' + err.message, 'err');
		});
	}

	function heartbeat(force) {
		var s = state.tse && state.tse.storage ? state.tse.storage : {};
		return api('POST', '/agent/heartbeat', {
			agent_id: AGENT_ID,
			force: !!force,
			connected: state.status === 'ready',
			error: state.error,
			tse: {
				state: s.tseInitializationState || '',
				valid_time: !!s.hasValidTime,
				signatures: s.createdSignatures,
				started: s.startedTransactions,
				exporting: state.exporting
			}
		}).then(function (res) {
			state.conflict = !!res.conflict;
			if (res.conflict) {
				render();
			}
			return res;
		}).catch(function (err) {
			log('Server nicht erreichbar: ' + err.message, 'err');
		});
	}

	function poll() {
		if (state.busy) {
			state.pollAgain = true;
			return Promise.resolve();
		}
		if (state.status !== 'ready' || state.exporting || state.conflict || !state.cfg.enabled) {
			return Promise.resolve();
		}
		state.busy = true;
		return flushOutbox()
			.then(function () { return api('GET', '/agent/jobs?agent_id=' + encodeURIComponent(AGENT_ID)); })
			.then(function (res) {
				if (res.conflict) {
					state.conflict = true;
					render();
					return;
				}
				state.lastJobs = (res.jobs || []).length;
				return (res.jobs || []).reduce(function (chain, job) {
					return chain.then(function () { return runJob(job); });
				}, Promise.resolve());
			})
			.catch(function (err) {
				log('Auftragsabruf: ' + err.message, 'err');
			})
			.then(function () {
				state.busy = false;
				render();
				if (state.pollAgain) {
					state.pollAgain = false;
					return poll();
				}
			});
	}

	function failJob(job, phase, err) {
		log('Bon ' + job.id + ' (' + phase + '): ' + err.message, 'err');
		return api('POST', '/agent/jobs/' + job.id + '/failed', {
			agent_id: AGENT_ID,
			phase: phase,
			code: err.code || '',
			error: err.message || String(err)
		}).catch(function () {});
	}

	function runJob(job) {
		var started = null;
		var doStart = function () {
			return state.tse.start().then(function (out) {
				started = out;
				log('Bon ' + job.id + ': TSE-Transaktion ' + out.transactionNumber + ' gestartet.');
				return report('/agent/jobs/' + job.id + '/started', {
					agent_id: AGENT_ID,
					tx_number: out.transactionNumber,
					log_time: out.logTime,
					serial_b64: out.serialNumber,
					signature_counter: out.signatureCounter,
					signature: out.signature
				});
			});
		};
		var doFinish = function (tx, processType, processData) {
			return state.tse.finish(tx, processType, processData).then(function (out) {
				state.signed++;
				log('Bon ' + job.id + ': signiert (TSE-Nr. ' + tx + ', Signaturzähler ' + out.signatureCounter + ').', 'ok');
				return report('/agent/jobs/' + job.id + '/finished', {
					agent_id: AGENT_ID,
					tx_number: tx,
					log_time: out.logTime,
					signature_counter: out.signatureCounter,
					signature: out.signature,
					process_data: processData
				});
			});
		};

		var handle = function (phase) {
			return function (err) {
				if (err.reportFailed) {
					// Server unreachable: the report waits in the outbox, nothing is retried at the TSE.
					log('Bon ' + job.id + ': Meldung an den Server ausstehend (' + err.message + ').', 'err');
					throw err;
				}
				if (err.rejected && phase === 'start' && started) {
					// The server no longer wants this transaction: close it at the TSE so it does not stay open.
					return state.tse.finish(started.transactionNumber, 'Kassenbeleg-V1', 'AVBelegabbruch^0.00_0.00_0.00_0.00_0.00^').then(function () {
						log('Bon ' + job.id + ': TSE-Transaktion ' + started.transactionNumber + ' als Abbruch geschlossen (' + err.message + ').');
					}, function (e2) {
						log('Bon ' + job.id + ': ' + e2.message, 'err');
					});
				}
				if (err.rejected) {
					return;
				}
				return failJob(job, phase, err);
			};
		};

		if (job.action === 'finish') {
			return doFinish(job.tx_number, job.process_type, job.process_data).catch(handle('finish'));
		}
		var startedReported = false;
		return doStart().then(function (res) {
			startedReported = true;
			var tx = started.transactionNumber;
			if (res && res.aborted) {
				return; // Server turned it into an abort job; it comes back as "finish".
			}
			if (job.action === 'start_finish') {
				return doFinish(tx, job.process_type, job.process_data);
			}
			if (res && res.finish_requested) {
				return doFinish(tx, res.process_type, res.process_data); // Paid while we were starting.
			}
		}).catch(function (err) {
			return handle(startedReported ? 'finish' : 'start')(err);
		});
	}

	/* ---------- periodic work ---------- */

	function maintenance() {
		if (state.status !== 'ready' || state.busy || state.exporting) {
			return;
		}
		if (Date.now() - state.lastInfo < 10 * 60 * 1000) {
			return;
		}
		state.busy = true;
		state.tse.info().then(function (s) {
			if (typeof s.timeUntilNextSelfTest === 'number' && s.timeUntilNextSelfTest > 0 && s.timeUntilNextSelfTest < 3600) {
				return state.tse.selfTest().then(function () { return state.tse.withRetry(function () { return state.tse.updateTime(false); }); });
			}
			if (!s.hasValidTime) {
				return state.tse.withRetry(function () { return state.tse.updateTime(true); });
			}
		}).then(function () {
			return pushInfo(false);
		}).catch(function (err) {
			log('Wartung: ' + err.message, 'err');
		}).then(function () {
			state.busy = false;
		});
	}

	/** Timers in a worker keep ticking when the browser throttles a background tab. */
	function startTicker(ms, fn) {
		try {
			var blob = new Blob(['setInterval(function(){postMessage(1)},' + ms + ');'], { type: 'text/javascript' });
			var worker = new Worker(URL.createObjectURL(blob));
			worker.onmessage = fn;
			return;
		} catch (e) { /* CSP may forbid blob workers */ }
		setInterval(fn, ms);
	}

	/* ---------- set-up ---------- */

	function setupDialog() {
		waitIdle().then(function () { return state.tse.info(); }).then(showSetupDialog, function (err) {
			log(err.message, 'err');
		});
	}

	function showSetupDialog() {
		var s = state.tse && state.tse.storage ? state.tse.storage : {};
		var uninitialized = s.tseInitializationState === 'UNINITIALIZED';
		var dlg = document.createElement('dialog');
		dlg.className = 'hwtse-dialog';
		dlg.innerHTML = uninitialized
			? '<h2>TSE einrichten</h2><p>Die TSE ist neu. Die Einrichtung setzt PUK, Admin-PIN und TimeAdmin-PIN, ändert den Secret Key, stellt die Uhr und registriert die Kasse <strong>' + esc(state.cfg.client_id) + '</strong>.</p>' +
				'<p><strong>Die Zugangsdaten unbedingt ausdrucken oder notieren und sicher aufbewahren.</strong> Ohne PUK ist die TSE bei gesperrter PIN unbrauchbar.</p>' +
				'<div class="hwtse-secret">wird erzeugt …</div><p><label><input type="checkbox" class="hwtse-ack"> Ich habe die Zugangsdaten notiert.</label></p>' +
				'<p><button class="button button-primary hwtse-go" disabled>TSE jetzt einrichten</button> <button class="button hwtse-close">Abbrechen</button></p>'
			: '<h2>Kasse an der TSE registrieren</h2><p>Die TSE ist bereits eingerichtet (' + esc(s.tseInitializationState || '?') + '). Mit den in den Einstellungen hinterlegten Admin-Zugangsdaten wird die Kasse <strong>' + esc(state.cfg.client_id) + '</strong> registriert und die Uhr gestellt.' +
				(state.cfg.secret_key === 'EPSONKEY' ? ' Der Secret Key wird dabei vom Werkswert auf einen neuen Wert geändert.' : '') + '</p>' +
				'<div class="hwtse-secret" hidden></div>' +
				'<p><button class="button button-primary hwtse-go" disabled>Registrieren</button> <button class="button hwtse-close">Abbrechen</button></p>';
		document.body.appendChild(dlg);
		dlg.showModal();
		var creds = null;
		var box = dlg.querySelector('.hwtse-secret');
		var go = dlg.querySelector('.hwtse-go');
		dlg.querySelector('.hwtse-close').onclick = function () { dlg.close(); dlg.remove(); };

		var pendingSecret = storageGet('hwtse_pending_secret', '');
		api('POST', '/agent/generate-credentials', {}).then(function (c) {
			creds = c;
			if (pendingSecret) {
				creds.secret_key = pendingSecret; // A previous run may have registered it already.
			}
			if (uninitialized) {
				box.textContent = 'PUK: ' + c.puk + '   Admin-PIN: ' + c.admin_pin + '   TimeAdmin-PIN: ' + c.time_admin_pin + '   Secret Key: ' + creds.secret_key;
			} else {
				if (state.cfg.secret_key === 'EPSONKEY') {
					box.hidden = false;
					box.textContent = 'Neuer Secret Key: ' + creds.secret_key + ' (bitte notieren)';
				}
				go.disabled = false;
			}
		}).catch(function (err) {
			log('Zugangsdaten nicht erzeugt: ' + err.message, 'err');
		});
		var ack = dlg.querySelector('.hwtse-ack');
		if (ack) {
			ack.onchange = function () { go.disabled = !ack.checked || !creds; };
		}
		go.onclick = function () {
			go.disabled = true;
			(uninitialized ? runInitialSetup(creds) : runRegistration(creds)).then(function () {
				log('Einrichtung abgeschlossen.', 'ok');
				dlg.close();
				dlg.remove();
				return reloadConfig().then(connect);
			}).catch(function (err) {
				log('Einrichtung: ' + err.message, 'err');
				window.alert('Einrichtung fehlgeschlagen: ' + err.message);
				go.disabled = false;
			});
		};
	}

	function runInitialSetup(creds) {
		var tse = state.tse;
		// Store PUK and PINs before the TSE gets them, so they can never get lost.
		return api('POST', '/agent/credentials', { puk: creds.puk, admin_pin: creds.admin_pin, time_admin_pin: creds.time_admin_pin, secret_key: 'EPSONKEY' })
			.then(function () {
				tse.cfg.puk = creds.puk;
				tse.cfg.admin_pin = creds.admin_pin;
				tse.cfg.time_admin_pin = creds.time_admin_pin;
				tse.cfg.secret_key = 'EPSONKEY';
				log('TSE-Einrichtung (SetUp) läuft, bis zu 60 Sekunden …');
				return tse.epos.send('SetUp', { puk: creds.puk, adminPIN: creds.admin_pin, timeAdminPin: creds.time_admin_pin }, 120000);
			})
			.then(function () { return runRegistration(creds); });
	}

	function runRegistration(creds) {
		var tse = state.tse;
		var newSecret = '';
		var pending = storageGet('hwtse_pending_secret', '');
		return tse.authAdmin()
			.catch(function (err) {
				// An earlier run changed the secret key but could not store it on the server.
				if (pending && err.code === 'OTHER_ERROR_HOST_AUTHENTICATION_FAILED' && pending !== tse.cfg.secret_key) {
					tse.cfg.secret_key = pending;
					return api('POST', '/agent/credentials', { secret_key: pending }).then(function () {
						storageSet('hwtse_pending_secret', null);
						log('Zwischengespeicherten Secret Key übernommen.', 'ok');
						return tse.authAdmin();
					});
				}
				throw err;
			})
			.then(function () {
				newSecret = creds && tse.cfg.secret_key === 'EPSONKEY' ? creds.secret_key : '';
				if (!newSecret) {
					return;
				}
				storageSet('hwtse_pending_secret', newSecret);
				return tse.epos.send('RegisterSecretKey', { secretKey: newSecret }).then(function () {
					tse.cfg.secret_key = newSecret;
					return api('POST', '/agent/credentials', { secret_key: newSecret });
				}).then(function () {
					storageSet('hwtse_pending_secret', null);
					log('Secret Key geändert.', 'ok');
				});
			})
			.then(function () { return tse.updateTime(false, true); })
			.then(function () { return tse.epos.send('GetRegisteredClientList', {}); })
			.then(function (out) {
				var list = out.registeredClientIdList || [];
				if (list.indexOf(tse.cfg.client_id) !== -1) {
					log('Kasse ' + tse.cfg.client_id + ' ist bereits registriert.');
					return;
				}
				return tse.epos.send('RegisterClient', { clientId: tse.cfg.client_id }).then(function () {
					log('Kasse ' + tse.cfg.client_id + ' registriert.', 'ok');
				});
			})
			.then(function () {
				// TimeAdmin stays logged in for 24 h instead of 8 h.
				return tse.epos.send('SetTimeOutInterval', { timeoutIntervalForTimeAdmin: 86400 }).catch(function (e) {
					log('Timeout nicht geändert: ' + e.message);
				});
			})
			.then(function () { return tse.logoutAdmin(); })
			.then(function () { return tse.authTimeAdmin(); });
	}

	function reloadConfig() {
		return api('GET', '/agent/config').then(function (cfg) {
			state.cfg = cfg;
			if (state.tse) {
				state.tse.cfg = cfg;
			}
		});
	}

	/* ---------- export ---------- */

	function exportDialog() {
		var dlg = document.createElement('dialog');
		dlg.className = 'hwtse-dialog';
		var today = new Date().toISOString().slice(0, 10);
		dlg.innerHTML = '<h2>TSE-Export</h2><p>Lädt die signierten Protokolle aus der TSE (TAR-Datei, z. B. für eine Kassen-Nachschau) und speichert sie unter „Abschlüsse &amp; Export“. Während des Exports signiert die TSE nicht, also außerhalb der Verkaufszeit starten.</p>' +
			'<p><label><input type="radio" name="kind" value="period" checked> Zeitraum von <input type="date" name="from" value="' + today.slice(0, 8) + '01"> bis <input type="date" name="to" value="' + today + '"></label></p>' +
			'<p><label><input type="radio" name="kind" value="archive"> Gesamter TSE-Speicher (Archiv-Export, löscht nichts)</label></p>' +
			'<p><button class="button button-primary hwtse-go">Export starten</button> <button class="button hwtse-close">Schließen</button></p><p class="hwtse-progress"></p>';
		document.body.appendChild(dlg);
		dlg.showModal();
		dlg.querySelector('.hwtse-close').onclick = function () { if (!state.exporting) { dlg.close(); dlg.remove(); } };
		dlg.querySelector('.hwtse-go').onclick = function () {
			var kind = dlg.querySelector('input[name=kind]:checked').value;
			var from = dlg.querySelector('input[name=from]').value;
			var to = dlg.querySelector('input[name=to]').value;
			var progress = dlg.querySelector('.hwtse-progress');
			this.disabled = true;
			runExport(kind, from, to, function (text) { progress.textContent = text; }).then(function (res) {
				progress.textContent = 'Fertig: ' + res.file + ' (' + Math.round(res.bytes / 1024) + ' KB).';
			}).catch(function (err) {
				progress.textContent = 'Fehler: ' + err.message;
			});
		};
	}

	function waitIdle() {
		return state.busy ? sleep(300).then(waitIdle) : Promise.resolve();
	}

	function runExport(kind, from, to, progress) {
		var tse = state.tse;
		var exportId = null;
		var seq = 0;
		var bytes = 0;
		state.exporting = true;
		render();
		var startCmd = kind === 'archive'
			? function () { return tse.epos.send('ArchiveExport', {}, 120000); }
			: function () {
				return tse.epos.send('ExportFilteredByPeriodOfTime', {
					clientId: '',
					startDate: new Date(from + 'T00:00:00').toISOString().replace(/\.\d{3}Z$/, 'Z'),
					endDate: new Date(to + 'T23:59:59').toISOString().replace(/\.\d{3}Z$/, 'Z')
				}, 120000);
			};
		var fetchLoop = function () {
			return tse.epos.send('GetExportData', {}, 120000).then(function (out) {
				var data = out.exportData || '';
				var next = data
					? api('POST', '/agent/export/' + exportId + '/chunk', { seq: seq++, data: data }).then(function (r) {
						bytes = r.bytes;
						progress('Übertragen: ' + Math.round(bytes / 1024) + ' KB …');
					})
					: sleep(500);
				return next.then(function () {
					if (out.exportStatus !== 'EXPORT_COMPLETE') {
						return fetchLoop();
					}
				});
			});
		};
		return waitIdle()
			.then(function () { return tse.authAdmin(); })
			.then(function () { return tse.updateTime(false, true); })
			.then(function () { return api('POST', '/agent/export/start', { kind: kind }); })
			.then(function (r) { exportId = r.id; progress('Export läuft …'); return startCmd(); })
			.then(fetchLoop)
			.then(function () { return tse.epos.send('FinalizeExport', { deleteData: false }, 120000); })
			.then(function () { return api('POST', '/agent/export/' + exportId + '/finish', { complete: true }); })
			.then(function (res) {
				log('TSE-Export gespeichert: ' + res.file, 'ok');
				return res;
			})
			.catch(function (err) {
				log('TSE-Export: ' + err.message, 'err');
				var cancel = tse.epos.send('CancelExport', {}).catch(function () {});
				return cancel.then(function () {
					if (exportId) {
						return api('POST', '/agent/export/' + exportId + '/finish', { complete: false }).catch(function () {});
					}
				}).then(function () { throw err; });
			})
			.finally(function () {
				tse.logoutAdmin();
				state.exporting = false;
				render();
			});
	}

	/* ---------- open transactions ---------- */

	function checkOpenTransactions() {
		var tse = state.tse;
		return waitIdle().then(function () {
			state.busy = true;
			return tse.withRetry(function () { return tse.startedList(); });
		}).then(function (numbers) {
			if (!numbers.length) {
				window.alert('Keine offenen TSE-Transaktionen.');
				return;
			}
			return api('POST', '/agent/open-transactions', { numbers: numbers }).then(function (res) {
				var orphans = (res.transactions || []).filter(function (t) { return t.orphan; });
				var text = numbers.length + ' offene TSE-Transaktion(en): ' + numbers.join(', ') + '.';
				if (!orphans.length) {
					window.alert(text + ' Alle gehören zu offenen Kassenvorgängen.');
					return;
				}
				if (!window.confirm(text + '\n\n' + orphans.length + ' davon gehören zu keinem offenen Vorgang (' + orphans.map(function (t) { return t.tx_number; }).join(', ') + '). Als Vorgangsabbruch abschließen?')) {
					return;
				}
				return orphans.reduce(function (chain, t) {
					return chain.then(function () {
						return tse.finish(t.tx_number, 'Kassenbeleg-V1', 'AVBelegabbruch^0.00_0.00_0.00_0.00_0.00^').then(function () {
							log('Verwaiste TSE-Transaktion ' + t.tx_number + ' als Abbruch abgeschlossen.', 'ok');
						});
					});
				}, Promise.resolve());
			});
		}).catch(function (err) {
			log('Offene Transaktionen: ' + err.message, 'err');
		}).then(function () {
			state.busy = false;
		});
	}

	/* ---------- UI ---------- */

	function pill(text, cls) {
		return '<span class="hwtse-pill ' + cls + '">' + esc(text) + '</span>';
	}

	function render() {
		var s = state.tse && state.tse.storage ? state.tse.storage : {};
		var statusPill = {
			idle: pill('nicht verbunden', ''),
			connecting: pill('verbinde …', 'warn'),
			ready: pill('bereit', 'ok'),
			setup: pill('Einrichtung nötig', 'warn'),
			error: pill('Fehler', 'err')
		}[state.status];
		var html = '<div class="hwtse-status">' +
			'<div>Agent: ' + statusPill + (state.exporting ? ' ' + pill('Export läuft', 'warn') : '') + (state.conflict ? ' ' + pill('anderer Agent aktiv', 'err') : '') + '</div>' +
			'<div>TSE: ' + pill(s.tseInitializationState || '–', s.tseInitializationState === 'INITIALIZED' ? 'ok' : 'warn') + '</div>' +
			'<div>Uhr: ' + pill(s.hasValidTime ? 'gültig' : 'nicht gesetzt', s.hasValidTime ? 'ok' : 'warn') + '</div>' +
			'<div>Signiert in dieser Sitzung: <strong>' + state.signed + '</strong></div>' +
			'</div>';
		if (state.error) {
			html += '<div class="notice notice-error inline"><p>' + esc(state.error) + '</p></div>';
		}
		if (state.conflict) {
			html += '<div class="notice notice-warning inline"><p>Ein anderer TSE-Agent (anderer Browser/PC) war in der letzten Minute aktiv. Es darf nur einer laufen. <button class="button hwtse-force">Diesen Agenten verwenden</button></p></div>';
		}
		if (document.hidden) {
			html += '<div class="notice notice-info inline"><p>Dieser Tab ist im Hintergrund. Der Agent läuft weiter, das Fenster aber bitte nicht schließen.</p></div>';
		}
		html += '<table class="widefat striped" style="max-width:900px"><tbody>' +
			'<tr><th>Drucker</th><td>' + esc(state.cfg ? state.cfg.printer_host + ':' + state.cfg.printer_port : '') + '</td></tr>' +
			'<tr><th>Kassen-ID</th><td>' + esc(state.cfg ? state.cfg.client_id : '') + '</td></tr>' +
			'<tr><th>TSE-Modell</th><td>' + esc(s.vendorType || '') + ' ' + esc(s.tseDescription || '') + '</td></tr>' +
			'<tr><th>Signaturen</th><td>' + esc(s.createdSignatures !== undefined ? s.createdSignatures + ' erstellt, ' + s.remainingSignatures + ' verbleibend' : '') + '</td></tr>' +
			'<tr><th>Offene TSE-Transaktionen</th><td>' + esc(s.startedTransactions !== undefined ? s.startedTransactions + ' von max. ' + s.maxStartedTransactions : '') + '</td></tr>' +
			'<tr><th>Nächster Selbsttest</th><td>' + esc(typeof s.timeUntilNextSelfTest === 'number' ? 'in ' + Math.round(s.timeUntilNextSelfTest / 3600) + ' h' : '') + '</td></tr>' +
			'<tr><th>Zertifikat gültig bis</th><td>' + esc(s.certificateExpirationDate || '') + '</td></tr>' +
			'<tr><th>Zustand</th><td>' + esc(s.tseHealth || '') + '</td></tr>' +
			'</tbody></table>' +
			'<div class="hwtse-actions">' +
			'<button class="button button-primary hwtse-connect">' + (state.status === 'ready' ? 'Neu verbinden' : 'Verbinden') + '</button>' +
			'<button class="button hwtse-refresh"' + (state.status !== 'ready' && state.status !== 'setup' ? ' disabled' : '') + '>Status aktualisieren</button>' +
			'<button class="button hwtse-setup"' + (!state.tse || !state.tse.epos.connected ? ' disabled' : '') + '>Einrichtung / Kasse registrieren</button>' +
			'<button class="button hwtse-time"' + (state.status !== 'ready' ? ' disabled' : '') + '>Uhrzeit setzen</button>' +
			'<button class="button hwtse-selftest"' + (state.status !== 'ready' ? ' disabled' : '') + '>Selbsttest</button>' +
			'<button class="button hwtse-open"' + (state.status !== 'ready' ? ' disabled' : '') + '>Offene TSE-Transaktionen prüfen</button>' +
			'<button class="button hwtse-export"' + (state.status !== 'ready' ? ' disabled' : '') + '>TSE-Export …</button>' +
			'</div><h3>Protokoll</h3><div class="hwtse-log"></div>';
		root.innerHTML = html;
		renderLog();
		bind();
	}

	function bind() {
		var on = function (sel, fn) {
			var el = root.querySelector(sel);
			if (el) {
				el.onclick = fn;
			}
		};
		on('.hwtse-connect', function () { reloadConfig().then(connect); });
		on('.hwtse-refresh', function () {
			waitIdle().then(function () { return state.tse.info(); }).then(function () { return pushInfo(true); }).catch(function (e) { log(e.message, 'err'); });
		});
		on('.hwtse-setup', setupDialog);
		on('.hwtse-time', function () {
			waitIdle().then(function () { return state.tse.withRetry(function () { return state.tse.updateTime(false); }); }).catch(function (e) { log(e.message, 'err'); });
		});
		on('.hwtse-selftest', function () {
			waitIdle().then(function () { state.busy = true; return state.tse.selfTest(); })
				.then(function () { return state.tse.withRetry(function () { return state.tse.updateTime(false); }); })
				.catch(function (e) { log(e.message, 'err'); })
				.then(function () { state.busy = false; });
		});
		on('.hwtse-open', checkOpenTransactions);
		on('.hwtse-export', exportDialog);
		on('.hwtse-force', function () {
			heartbeat(true).then(function () { state.conflict = false; render(); poll(); });
		});
	}

	/* ---------- start ---------- */

	function start() {
		reloadConfig().then(function () {
			render();
			if (!DATA.sdk) {
				setStatus('error', 'Epson ePOS SDK fehlt (Einstellungen → SDK-Datei hochladen).');
				return;
			}
			log('TSE-Agent ' + (DATA.version || '') + ' gestartet (ID ' + AGENT_ID.slice(0, 8) + ').');
			return heartbeat(false).then(connect);
		}).catch(function (err) {
			setStatus('error', err.message);
		});

		var ticks = 0;
		startTicker(3000, function () {
			ticks++;
			poll();
			if (ticks % 10 === 0) {
				heartbeat(false);
				maintenance();
			}
		});

		try {
			var channel = new BroadcastChannel('hwtse');
			channel.onmessage = function (ev) {
				if (ev.data && ev.data.type === 'wake') {
					poll();
				}
			};
		} catch (e) { /* not supported */ }

		document.addEventListener('visibilitychange', render);
		window.addEventListener('beforeunload', function (ev) {
			if (state.status === 'ready' || state.exporting) {
				ev.preventDefault();
				ev.returnValue = '';
			}
		});
	}

	start();
})();
