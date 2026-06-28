/**
 * NDV Reviews documentation site.
 * Loads Markdown content files, renders them, and provides client-side search.
 * No build step, no framework — keep it dependency-free.
 */
(function () {
	'use strict';

	// Manifest of doc pages. Add an entry here when a new content/*.md is created.
	var DOCS = [
		{ id: 'overview', title: 'Overview', group: 'Getting Started', file: 'content/overview.md' },
		{ id: 'architecture', title: 'Architecture', group: 'Getting Started', file: 'content/architecture.md' },
		{ id: 'free-vs-pro', title: 'Free vs Pro', group: 'Getting Started', file: 'content/free-vs-pro.md' },
		{ id: 'phase-0', title: 'Phase 0 — Scaffolding', group: 'Build Phases', file: 'content/phase-0.md' },
		{ id: 'phase-1', title: 'Phase 1 — Core Reviews', group: 'Build Phases', file: 'content/phase-1.md' },
		{ id: 'phase-2', title: 'Phase 2 — Moderation + Display', group: 'Build Phases', file: 'content/phase-2.md' },
		{ id: 'phase-3', title: 'Phase 3 — Requests + Collection Link', group: 'Build Phases', file: 'content/phase-3.md' },
		{ id: 'phase-4', title: 'Phase 4 — Integrations + Importers', group: 'Build Phases', file: 'content/phase-4.md' },
		{ id: 'phase-5', title: 'Phase 5 — Pro Foundation', group: 'Build Phases', file: 'content/phase-5.md' },
		{ id: 'phase-6', title: 'Phase 6 — Automation + Incentives', group: 'Build Phases', file: 'content/phase-6.md' },
		{ id: 'phase-7', title: 'Phase 7 — Q&A + AI', group: 'Build Phases', file: 'content/phase-7.md' },
		{ id: 'data-model', title: 'Data Model', group: 'Reference', file: 'content/data-model.md' },
		{ id: 'hooks', title: 'Hooks & Filters', group: 'Reference', file: 'content/hooks.md' }
	];

	var cache = {};
	var navEl = document.getElementById('nav');
	var docEl = document.getElementById('doc');
	var resultsEl = document.getElementById('search-results');
	var searchEl = document.getElementById('search');

	function escapeHtml(s) {
		return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/* Minimal, safe-enough Markdown renderer for our controlled content. */
	function renderMarkdown(md) {
		var lines = md.replace(/\r\n/g, '\n').split('\n');
		var html = '';
		var i = 0;
		var inCode = false;
		var codeBuf = [];
		var listType = null;
		var tableBuf = [];

		function closeList() {
			if (listType) { html += '</' + listType + '>'; listType = null; }
		}
		function flushTable() {
			if (!tableBuf.length) return;
			var rows = tableBuf.filter(function (r) { return !/^\s*\|?[\s:|-]+\|?\s*$/.test(r); });
			html += '<table>';
			rows.forEach(function (row, idx) {
				var cells = row.replace(/^\||\|$/g, '').split('|');
				var tag = idx === 0 ? 'th' : 'td';
				html += '<tr>' + cells.map(function (c) { return '<' + tag + '>' + inline(c.trim()) + '</' + tag + '>'; }).join('') + '</tr>';
			});
			html += '</table>';
			tableBuf = [];
		}

		function inline(t) {
			t = escapeHtml(t);
			t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
			t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			t = t.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
			t = t.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
			t = t.replace(/\{badge:(\w+):([^}]+)\}/g, '<span class="badge $1">$2</span>');
			return t;
		}

		for (; i < lines.length; i++) {
			var line = lines[i];

			if (/^```/.test(line)) {
				if (inCode) { html += '<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>'; codeBuf = []; inCode = false; }
				else { closeList(); flushTable(); inCode = true; }
				continue;
			}
			if (inCode) { codeBuf.push(line); continue; }

			if (/^\s*\|.*\|\s*$/.test(line)) { closeList(); tableBuf.push(line); continue; }
			else if (tableBuf.length) { flushTable(); }

			if (/^###\s+/.test(line)) { closeList(); html += '<h3>' + inline(line.replace(/^###\s+/, '')) + '</h3>'; }
			else if (/^##\s+/.test(line)) { closeList(); html += '<h2>' + inline(line.replace(/^##\s+/, '')) + '</h2>'; }
			else if (/^#\s+/.test(line)) { closeList(); html += '<h1>' + inline(line.replace(/^#\s+/, '')) + '</h1>'; }
			else if (/^>\s?/.test(line)) { closeList(); html += '<blockquote>' + inline(line.replace(/^>\s?/, '')) + '</blockquote>'; }
			else if (/^---\s*$/.test(line)) { closeList(); html += '<hr>'; }
			else if (/^\s*[-*]\s+/.test(line)) {
				if (listType !== 'ul') { closeList(); html += '<ul>'; listType = 'ul'; }
				html += '<li>' + inline(line.replace(/^\s*[-*]\s+/, '')) + '</li>';
			}
			else if (/^\s*\d+\.\s+/.test(line)) {
				if (listType !== 'ol') { closeList(); html += '<ol>'; listType = 'ol'; }
				html += '<li>' + inline(line.replace(/^\s*\d+\.\s+/, '')) + '</li>';
			}
			else if (/^\s*$/.test(line)) { closeList(); }
			else { closeList(); html += '<p>' + inline(line) + '</p>'; }
		}
		closeList();
		flushTable();
		if (inCode) { html += '<pre><code>' + escapeHtml(codeBuf.join('\n')) + '</code></pre>'; }
		return html;
	}

	function fetchDoc(doc) {
		if (cache[doc.id]) return Promise.resolve(cache[doc.id]);
		return fetch(doc.file).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.text();
		}).then(function (text) { cache[doc.id] = text; return text; });
	}

	function buildNav() {
		var groups = {};
		DOCS.forEach(function (d) { (groups[d.group] = groups[d.group] || []).push(d); });
		var html = '';
		Object.keys(groups).forEach(function (g) {
			html += '<div class="group-title">' + g + '</div>';
			groups[g].forEach(function (d) {
				html += '<a href="#' + d.id + '" data-id="' + d.id + '">' + d.title + '</a>';
			});
		});
		navEl.innerHTML = html;
	}

	function setActive(id) {
		Array.prototype.forEach.call(navEl.querySelectorAll('a'), function (a) {
			a.classList.toggle('active', a.getAttribute('data-id') === id);
		});
	}

	function showDoc(id) {
		var doc = DOCS.filter(function (d) { return d.id === id; })[0] || DOCS[0];
		resultsEl.hidden = true;
		docEl.hidden = false;
		docEl.innerHTML = '<p class="loading">Loading…</p>';
		fetchDoc(doc).then(function (md) {
			docEl.innerHTML = renderMarkdown(md);
			setActive(doc.id);
			window.scrollTo(0, 0);
		}).catch(function () {
			docEl.innerHTML = '<h1>' + doc.title + '</h1><p class="loading">Could not load this page. If you opened the file directly, serve the <code>docs/</code> folder over HTTP (browsers block <code>fetch</code> on <code>file://</code>).</p>';
		});
	}

	function runSearch(q) {
		q = q.trim().toLowerCase();
		if (q.length < 2) { resultsEl.hidden = true; docEl.hidden = false; return; }
		Promise.all(DOCS.map(fetchDoc)).then(function (texts) {
			var hits = [];
			DOCS.forEach(function (d, i) {
				var text = texts[i];
				var idx = text.toLowerCase().indexOf(q);
				if (idx !== -1) {
					var start = Math.max(0, idx - 50);
					var snippet = text.substring(start, idx + 90).replace(/[#>*`|]/g, '');
					var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
					hits.push({ id: d.id, title: d.title, snippet: escapeHtml(snippet).replace(re, '<mark>$1</mark>') });
				}
			});
			docEl.hidden = true;
			resultsEl.hidden = false;
			resultsEl.innerHTML = hits.length
				? hits.map(function (h) { return '<div class="hit" data-id="' + h.id + '"><h4>' + h.title + '</h4><p>…' + h.snippet + '…</p></div>'; }).join('')
				: '<p class="loading">No matches for “' + escapeHtml(q) + '”.</p>';
		});
	}

	// Events.
	window.addEventListener('hashchange', function () { showDoc(location.hash.replace('#', '')); });
	searchEl.addEventListener('input', function () { runSearch(searchEl.value); });
	resultsEl.addEventListener('click', function (e) {
		var hit = e.target.closest('.hit');
		if (hit) { searchEl.value = ''; location.hash = '#' + hit.getAttribute('data-id'); }
	});
	document.querySelectorAll('[data-doc]').forEach(function (el) {
		el.addEventListener('click', function (e) { e.preventDefault(); location.hash = '#' + el.getAttribute('data-doc'); });
	});

	// Init.
	buildNav();
	showDoc(location.hash.replace('#', '') || 'overview');
})();
