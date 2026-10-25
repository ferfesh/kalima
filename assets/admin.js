/* global KALIMA */
/**
 * Kalima AI Multilingual — admin behaviour.
 *
 * Three jobs: validate the API key, run a bulk translation with progress, and
 * translate a single row from a list-table badge. Everything goes through
 * admin-ajax.php with the nonce localised by PHP.
 */
(function () {
	'use strict';

	if (typeof KALIMA === 'undefined') {
		return;
	}

	var S = {
		running: false,
		stop: false,
		queue: [],
		cursor: 0,
		done: 0,
		total: 0,
		stats: { calls: 0, units: 0, chars: 0 }
	};

	/* ------------------------------------------------------------------
	 * Transport
	 * ---------------------------------------------------------------- */

	function call(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', KALIMA.nonce);

		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) { body.append(key + '[]', item); });
			} else {
				body.append(key, value);
			}
		});

		return fetch(KALIMA.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (res) {
			return res.json();
		});
	}

	function num(value) {
		return (value || 0).toLocaleString();
	}

	/* ------------------------------------------------------------------
	 * Settings — test the key
	 * ---------------------------------------------------------------- */

	function bindTestKey() {
		var btn = document.getElementById('kalima-test-key');
		var out = document.getElementById('kalima-test-result');
		var key = document.getElementById('kalima_key');

		if (!btn || !out) {
			return;
		}

		btn.addEventListener('click', function () {
			out.className = 'kalima-test-result';
			out.textContent = KALIMA.strings.running;
			btn.disabled = true;

			// Push whatever is typed into a transient option so PHP sees it.
			var payload = { key: key ? key.value : '' };

			call('kalima_test_key', payload)
				.then(function (json) {
					if (json && json.success) {
						out.classList.add('is-ok');
						out.textContent = (json.data && json.data.message) || 'OK';
					} else {
						out.classList.add('is-bad');
						out.textContent = (json && json.data && json.data.message) || KALIMA.strings.fail;
					}
				})
				.catch(function () {
					out.classList.add('is-bad');
					out.textContent = KALIMA.strings.fail;
				})
				.then(function () {
					btn.disabled = false;
				});
		});
	}

	/* ------------------------------------------------------------------
	 * Bulk runner
	 * ---------------------------------------------------------------- */

	function selectedLanguages() {
		return Array.prototype.map.call(
			document.querySelectorAll('.kalima-target-lang:checked'),
			function (el) { return el.value; }
		);
	}

	function setProgress(fraction) {
		var bar = document.getElementById('kalima-bar');
		if (bar) {
			bar.style.width = Math.round(Math.max(0, Math.min(1, fraction)) * 100) + '%';
		}
	}

	function setStatus(text) {
		var el = document.getElementById('kalima-status');
		if (el) {
			el.textContent = text;
		}
	}

	function log(text, kind) {
		var list = document.getElementById('kalima-log');
		if (!list) {
			return;
		}

		var li = document.createElement('li');
		li.className = kind || '';
		li.textContent = text;
		list.appendChild(li);
		list.scrollTop = list.scrollHeight;
	}

	function mergeStats(stats) {
		if (!stats) {
			return;
		}
		S.stats.calls += stats.calls || 0;
		S.stats.units += stats.units || 0;
		S.stats.chars += stats.chars || 0;
	}

	function statsLine() {
		return KALIMA.strings.call + ': ' + num(S.stats.calls) +
			' · ' + KALIMA.strings.units + ': ' + num(S.stats.units) +
			' · ' + KALIMA.strings.chars + ': ' + num(S.stats.chars);
	}

	function bindScan() {
		var scan = document.getElementById('kalima-scan');
		var run = document.getElementById('kalima-run');
		var stop = document.getElementById('kalima-stop');

		if (!scan || !run) {
			return;
		}

		scan.addEventListener('click', function () {
			var langs = selectedLanguages();

			if (!langs.length) {
				window.alert('Pick at least one target language.');
				return;
			}

			scan.disabled = true;
			document.getElementById('kalima-progress').hidden = false;
			document.getElementById('kalima-log').innerHTML = '';
			setStatus(KALIMA.strings.running);

			call('kalima_scan', { langs: langs }).then(function (json) {
				if (!json || !json.success) {
					setStatus((json && json.data && json.data.message) || KALIMA.strings.fail);
					return;
				}

				var missing = json.data.missing || { posts: [], terms: [] };
				var targets = json.data.targets || langs;

				S.queue = [];
				S.cursor = 0;
				S.done = 0;
				S.stats = { calls: 0, units: 0, chars: 0 };

				// Group work as [language][items], smallest chunks first.
				targets.forEach(function (lang) {
					var posts = (missing.posts || []).filter(function (item) {
						return item.missing.indexOf(lang) !== -1;
					});
					var terms = (missing.terms || []).filter(function (item) {
						return item.missing.indexOf(lang) !== -1;
					});

					chunk(posts, 3).forEach(function (group) {
						S.queue.push({ kind: 'posts', lang: lang, ids: group.map(function (p) { return p.id; }), labels: group.map(function (p) { return p.title; }) });
					});

					chunk(terms, 15).forEach(function (group) {
						S.queue.push({ kind: 'terms', lang: lang, ids: group.map(function (t) { return t.id; }), labels: group.map(function (t) { return t.name; }) });
					});
				});

				S.total = S.queue.length;

				log('Found ' + json.data.totals.posts + ' posts and ' + json.data.totals.terms + ' terms missing a translation.', '');

				if (!S.total) {
					setStatus(KALIMA.strings.done);
					run.disabled = true;
					return;
				}

				setStatus(S.total + ' batches ready.');
				run.disabled = false;
			}).catch(function () {
				setStatus(KALIMA.strings.fail);
			}).then(function () {
				scan.disabled = false;
			});
		});

		if (stop) {
			stop.addEventListener('click', function () {
				S.stop = true;
			});
		}

		run.addEventListener('click', function () {
			if (S.cursor >= S.total) {
				return;
			}

			S.running = true;
			S.stop = false;
			run.disabled = true;
			stop.disabled = false;
			document.getElementById('kalima-progress').hidden = false;
			step();
		});
	}

	function chunk(list, size) {
		var out = [];
		for (var i = 0; i < list.length; i += size) {
			out.push(list.slice(i, i + size));
		}
		return out;
	}

	function step() {
		if (S.stop) {
			finish('Stopped after ' + S.done + '/' + S.total + ' batches.');
			return;
		}

		if (S.cursor >= S.total) {
			finish(KALIMA.strings.done);
			return;
		}

		var job = S.queue[S.cursor];
		setStatus('Batch ' + (S.cursor + 1) + '/' + S.total + ' — ' + job.kind + ' → ' + job.lang);

		var action = job.kind === 'terms' ? 'kalima_translate_terms' : 'kalima_translate_posts';
		var param = job.kind === 'terms' ? 'term_ids' : 'post_ids';

		var payload = { lang: job.lang };
		payload[param] = job.ids;

		call(action, payload).then(function (json) {
			if (!json || !json.success) {
				log((json && json.data && json.data.message) || 'Batch failed.', 'is-bad');
			} else {
				var results = json.data.results || {};
				var labels = job.labels;
				var hitLimit = false;

				job.ids.forEach(function (id, i) {
					var outcome = results[id] || 'unknown';
					var label = labels[i] || ('#' + id);

					if (outcome === 'ok') {
						log(label + ' → ' + job.lang + ' ✓', 'is-ok');
					} else if (outcome === 'limit') {
						hitLimit = true;
					} else if (outcome === 'exists' || outcome === 'source' || outcome === 'skipped') {
						log(label + ' — ' + outcome, 'is-skip');
					} else {
						log(label + ' — ' + outcome, 'is-bad');
					}
				});

				mergeStats(json.data.stats);

				if (hitLimit) {
					finish('Reached the API-call limit for this run. Raise it in Settings or run again.');
					return;
				}

				if (json.data.error) {
					log('Provider note: ' + json.data.error, 'is-bad');
				}
			}

			S.cursor += 1;
			S.done += 1;
			setProgress(S.done / S.total);
			setStatus(statsLine());
			window.setTimeout(step, 120);
		}).catch(function () {
			log('Network error on batch ' + (S.cursor + 1), 'is-bad');
			S.cursor += 1;
			S.done += 1;
			window.setTimeout(step, 400);
		});
	}

	function finish(message) {
		S.running = false;
		var run = document.getElementById('kalima-run');
		var stop = document.getElementById('kalima-stop');
		if (run) { run.disabled = false; }
		if (stop) { stop.disabled = true; }
		setStatus(message + '  ' + statsLine());
		log(message + '  ' + statsLine(), '');
	}

	/* ------------------------------------------------------------------
	 * Single-row buttons in list tables
	 * ---------------------------------------------------------------- */

	function bindBadges() {
		document.addEventListener('click', function (event) {
			var post = event.target.closest('[data-kalima-translate]');
			var term = event.target.closest('[data-kalima-term]');

			if (post) {
				runOne(post, 'kalima_translate_posts', 'post_ids', post.getAttribute('data-kalima-translate'), post.getAttribute('data-kalima-lang'));
			} else if (term) {
				runOne(term, 'kalima_translate_terms', 'term_ids', term.getAttribute('data-kalima-term'), term.getAttribute('data-kalima-lang'));
			}
		});
	}

	function runOne(btn, action, param, id, lang) {
		btn.classList.add('kalima-badge--busy');
		btn.disabled = true;

		var payload = { lang: lang };
		payload[param] = [id];

		call(action, payload).then(function (json) {
			if (json && json.success) {
				var outcome = (json.data.results || {})[id];

				if (outcome === 'ok') {
					// Reload so the badge flips from "+" to a link.
					window.location.reload();
					return;
				}

				btn.classList.remove('kalima-badge--busy');
				btn.title = outcome || 'failed';
				btn.textContent = '!';
				btn.disabled = false;
			} else {
				btn.classList.remove('kalima-badge--busy');
				btn.title = (json && json.data && json.data.message) || 'failed';
				btn.textContent = '!';
				btn.disabled = false;
			}
		}).catch(function () {
			btn.classList.remove('kalima-badge--busy');
			btn.title = 'network error';
			btn.textContent = '!';
			btn.disabled = false;
		});
	}

	/* ------------------------------------------------------------------
	 * Language order on the settings screen
	 * ---------------------------------------------------------------- */

	function bindLangOrder() {
		var grid = document.getElementById('kalima-lang-grid');
		var field = document.getElementById('kalima_lang_order');
		var form = field ? field.closest('form') : null;

		if (!grid || !field) {
			return;
		}

		function refreshMoveButtons() {
			var cards = grid.querySelectorAll('.kalima-lang-card');

			Array.prototype.forEach.call(cards, function (card, i) {
				var up = card.querySelector('.kalima-move[data-dir="up"]');
				var down = card.querySelector('.kalima-move[data-dir="down"]');
				var pinned = card.classList.contains('is-source');

				if (up) { up.disabled = pinned || (i === 0); }
				if (down) { down.disabled = pinned || (i === cards.length - 1); }
			});
		}

		grid.addEventListener('click', function (event) {
			var btn = event.target.closest ? event.target.closest('.kalima-move') : null;

			if (!btn) {
				return;
			}

			// The button sits inside a <label>; without this the click would
			// toggle the checkbox underneath it.
			event.preventDefault();
			event.stopPropagation();

			var card = btn.closest('.kalima-lang-card');

			if (!card) {
				return;
			}

			var dir = btn.getAttribute('data-dir');
			var source = grid.querySelector('.kalima-lang-card.is-source');
			var cards = grid.querySelectorAll('.kalima-lang-card');

			// The source language is pinned first: nothing may go above it.
			if (dir === 'up' && source && cards.length > 1 && cards[1] === card) {
				return;
			}

			var sibling = (dir === 'up') ? card.previousElementSibling : card.nextElementSibling;

			if (!sibling || !sibling.classList.contains('kalima-lang-card')) {
				return;
			}

			if (dir === 'up') {
				grid.insertBefore(card, sibling);
			} else {
				grid.insertBefore(sibling, card);
			}

			refreshMoveButtons();
		});

		if (form) {
			form.addEventListener('submit', function () {
				field.value = Array.prototype.map.call(
					grid.querySelectorAll('.kalima-lang-card'),
					function (card) { return card.getAttribute('data-code'); }
				).join(',');
			});
		}

		refreshMoveButtons();
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		bindTestKey();
		bindScan();
		bindBadges();
		bindLangOrder();
	});
}());
