(function () {
	'use strict';

	var state = { lastCapture: null, lastError: '' };

	function escText(value) { return String(value == null ? '' : value); }
	function utf8(value) { return new TextEncoder().encode(escText(value)); }
	function concat(parts) {
		var length = parts.reduce(function (sum, part) { return sum + part.length; }, 0);
		var out = new Uint8Array(length), offset = 0;
		parts.forEach(function (part) { out.set(part, offset); offset += part.length; });
		return out;
	}
	var crcTable = null;
	function crc32(bytes) {
		if (!crcTable) {
			crcTable = new Uint32Array(256);
			for (var n = 0; n < 256; n++) {
				var c = n;
				for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
				crcTable[n] = c >>> 0;
			}
		var crc = 0xFFFFFFFF;
		for (var i = 0; i < bytes.length; i++) crc = crcTable[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8);
		return (crc ^ 0xFFFFFFFF) >>> 0;
	}
	function u16(value) { var out = new Uint8Array(2); new DataView(out.buffer).setUint16(0, value, true); return out; }
	function u32(value) { var out = new Uint8Array(4); new DataView(out.buffer).setUint32(0, value >>> 0, true); return out; }
	function zip(files) {
		var locals = [], centrals = [], offset = 0;
		files.forEach(function (file) {
			var name = utf8(file.name), data = file.data instanceof Uint8Array ? file.data : utf8(file.data), crc = crc32(data);
			var local = concat([u32(0x04034b50), u16(20), u16(0), u16(0), u16(0), u16(0), u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), name, data]);
			var central = concat([u32(0x02014b50), u16(20), u16(20), u16(0), u16(0), u16(0), u16(0), u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset), name]);
			locals.push(local); centrals.push(central); offset += local.length;
		});
		var centralBytes = concat(centrals);
		var end = concat([u32(0x06054b50), u16(0), u16(0), u16(files.length), u16(files.length), u32(centralBytes.length), u32(offset), u16(0)]);
		return new Blob([concat(locals), centralBytes, end], { type: 'application/zip' });
	}
	function downloadBlob(name, blob) {
		var url = URL.createObjectURL(blob), a = document.createElement('a');
		a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
	}
	function previewDocument() {
		var frame = document.querySelector('#elementor-preview-iframe,iframe[name="elementor-preview-iframe"],iframe[src*="elementor-preview"]');
		try { return frame && frame.contentDocument ? frame.contentDocument : null; } catch (e) { return null; }
	}
	function targetNode(targetId) {
		var doc = previewDocument(); if (!doc || !targetId) return null;
		try { return doc.querySelector('[data-id="' + CSS.escape(String(targetId)) + '"],.elementor-element-' + CSS.escape(String(targetId))); } catch (e) { return null; }
	}
	function styleString(win, node) {
		var css = win.getComputedStyle(node), out = '';
		for (var i = 0; i < css.length; i++) { var key = css[i]; if (key.indexOf('-webkit-') === 0) continue; out += key + ':' + css.getPropertyValue(key) + ';'; }
		return out;
	}
	function cloneWithStyles(source, win) {
		var clone = source.cloneNode(true), sourceNodes = [source].concat(Array.prototype.slice.call(source.querySelectorAll('*'))), cloneNodes = [clone].concat(Array.prototype.slice.call(clone.querySelectorAll('*')));
		sourceNodes.forEach(function (node, i) { if (!cloneNodes[i] || node.nodeType !== 1) return; try { cloneNodes[i].setAttribute('style', styleString(win, node)); } catch (e) {} });
		return clone;
	}
	function rasterizeTarget(targetId) {
		return new Promise(function (resolve) {
			var node = targetNode(targetId); if (!node) { resolve(null); return; }
			var doc = node.ownerDocument, win = doc.defaultView || window, rect = node.getBoundingClientRect();
			var width = Math.max(1, Math.ceil(rect.width)), height = Math.max(1, Math.ceil(rect.height));
			if (width * height > 16000000 || width > 5000 || height > 5000) { resolve(null); return; }
			var clone = cloneWithStyles(node, win), serialized = new XMLSerializer().serializeToString(clone);
			var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '"><foreignObject width="100%" height="100%"><div xmlns="http://www.w3.org/1999/xhtml">' + serialized + '</div></foreignObject></svg>';
			var blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }), url = URL.createObjectURL(blob), img = new Image();
			img.onload = function () {
				try {
					var canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height; var ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0); URL.revokeObjectURL(url);
					canvas.toBlob(function (png) { if (!png) { resolve(null); return; } png.arrayBuffer().then(function (buffer) { resolve({ name: 'current-desktop.png', data: new Uint8Array(buffer), width: width, height: height, source: 'elementor-preview-iframe-foreignobject' }); }).catch(function () { resolve(null); }); }, 'image/png');
				} catch (e) { URL.revokeObjectURL(url); resolve(null); }
			};
			img.onerror = function () { URL.revokeObjectURL(url); resolve(null); }; img.src = url;
		});
	}
	function taskMarkdown(pkg, raster) {
		var target = pkg.target || {}, task = pkg.task || {}, placement = pkg.placementContext || {}, output = pkg.outputContract || {}, design = pkg.designIntelligence || {};
		var dials = design.designDials || {};
		return [
			'# Cresco AI Task', '',
			'Goal:', task.request || '(No explicit request supplied)', '',
			'Target:', 'Post ' + (target.postId || 0), 'Element ' + (target.id || ''), 'Scope ' + (target.scope || ''), '',
			'Design intelligence:', 'Product: ' + (design.productArchetype || 'general-web'), 'Variance: ' + ((dials.variance || {}).tier || 'auto'), 'Motion: ' + ((dials.motion || {}).tier || 'auto'), 'Density: ' + ((dials.density || {}).tier || 'auto'), '',
			'Widget policy:', 'Use widget-guide.json and runtime-proven widget types only.', 'Use semantic layout/style intent before raw Elementor setting keys when possible.', '',
			'Placement:', JSON.stringify(placement.allowedPlacements || []), '',
			'IDs:', 'Do not invent final Elementor IDs. Omit id or use unique $new:<name> refs.', '',
			'Output:', 'Preferred schema: ' + (output.preferredSchema || 'cresco-ai-mutation/v3'), 'Return only the intended mutation; do not echo source context.', '',
			'Quality:', 'Accessibility and interaction safety outrank decorative styling. Reuse Active Kit globals and preserve behavioral bindings.', '',
			'Visual:', raster ? 'current-desktop.png is a best-effort raster capture of the selected Elementor target.' : 'No trustworthy raster capture was produced. Use structured visualSnapshot/layoutGraph and attach the original reference image separately if needed.'
		].join('\n');
	}
	function referenceBytes(file) {
		if (!file || typeof file.arrayBuffer !== 'function') return Promise.resolve(null);
		return file.arrayBuffer().then(function (buffer) { var name = String(file.name || 'reference-image').replace(/[^A-Za-z0-9._-]+/g, '-'); return { name: 'reference-' + name, data: new Uint8Array(buffer) }; }).catch(function () { return null; });
	}
	function build(pkg, referenceFile) {
		if (!pkg || pkg.schema !== 'cresco-ai-context/v3') return Promise.reject(new Error('Prepare Cresco AI Context v3 first.'));
		var targetId = pkg.target && pkg.target.id || '';
		return Promise.all([rasterizeTarget(targetId), referenceBytes(referenceFile)]).then(function (parts) {
			var raster = parts[0], reference = parts[1]; state.lastCapture = raster ? { width: raster.width, height: raster.height, source: raster.source } : null;
			var guide = { schema: 'cresco-widget-guide/v2', widgetIntelligence: pkg.widgetIntelligence || {}, constructionPlan: pkg.constructionPlan || {}, semanticBindings: pkg.semanticBindings || {}, structureGrammar: pkg.structureGrammar || {}, controlExamples: pkg.controlExamples || {}, semanticDesignIntent: pkg.semanticDesignIntent || {} };
			var contract = pkg.outputContract || {};
			var design = { schema: 'cresco-design-brief/v1', designIntelligence: pkg.designIntelligence || {}, designSystem: pkg.designSystem || {}, responsive: pkg.responsive || {}, mutationBoundary: pkg.mutationBoundary || {} };
			var manifest = { schema: 'cresco-ai-bundle/v2', pluginVersion: (window.crescoLayerEditor || {}).version || '', createdAt: new Date().toISOString(), target: pkg.target || {}, contextQuality: pkg.contextQuality || {}, preferredOutputSchema: contract.preferredSchema || 'cresco-ai-mutation/v3', raster: raster ? { file: raster.name, width: raster.width, height: raster.height, source: raster.source } : { file: null, status: 'unavailable' }, reference: reference ? { file: reference.name } : { file: null }, files: [] };
			var files = [
				{ name: '01-TASK.md', data: taskMarkdown(pkg, raster) },
				{ name: '02-context.json', data: JSON.stringify(pkg, null, 2) },
				{ name: '03-widget-guide.json', data: JSON.stringify(guide, null, 2) },
				{ name: '04-output-contract.json', data: JSON.stringify(contract, null, 2) },
				{ name: '05-design-intelligence.json', data: JSON.stringify(design, null, 2) }
			];
			if (raster) files.push({ name: raster.name, data: raster.data }); if (reference) files.push(reference);
			manifest.files = files.map(function (file) { return file.name; }).concat(['manifest.json']); files.push({ name: 'manifest.json', data: JSON.stringify(manifest, null, 2) });
			return { blob: zip(files), manifest: manifest };
		});
	}
	function exportBundle(pkg, referenceFile) {
		return build(pkg, referenceFile).then(function (result) { var target = pkg.target && pkg.target.id || 'target'; downloadBlob('cresco-ai-bundle-' + target + '.zip', result.blob); return result.manifest; }).catch(function (error) { state.lastError = error && error.message ? error.message : String(error); throw error; });
	}

	window.CrescoLayerAIBundle = { version: '2.0.0', build: build, export: exportBundle, getDiagnostics: function () { return { lastCapture: state.lastCapture, lastError: state.lastError }; } };
}());
