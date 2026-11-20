/**
 * D-1507: fits a postage PDF (eBay's DHL label, a Deutsche Post Internetmarke) onto the 62 mm tape of the Brother QL-810W: every page is
 * rendered, cropped to its printed area, turned upright and printed at tape width, with an optional caption in its empty corner. The PDF
 * is read in the browser only, never uploaded or stored, and printed only on a click.
 *
 * Other modules use it through the handle taxmod-label62: taxmodLabel62.run(blob, caption) → Promise<boolean>, or a button with
 * data-taxmod-label62-pick="caption" that asks for the PDF file. D-1511: the same building block chooses and reads the PDF for everyone —
 * taxmodLabel62.pick() → Promise<File|null> and taxmodLabel62.lines(blob) → Promise<list<string>>, the printed text top to bottom.
 */
(function () {
	'use strict';

	var TAPE_MM = 62;
	// The QL-810W cannot print the outer 1.5 mm of the tape; 3 mm keep clear of it.
	var PRINT_MM = 58;
	// The Brother driver's paper for endless 62 mm tape is 62 × 100 mm; a longer page is shrunk by the browser to fit
	// it (the first prints came out at half width). So the label fits into one such page.
	var PAGE_MM = 100;
	// The QL-810W leaves about 3 mm unprinted at the start and end of each label.
	var FREE_MM = PAGE_MM - 9;
	var DPI = 300;

	pdfjsLib.GlobalWorkerOptions.workerSrc = taxmodLabel62.worker;

	// Ink only: light grey (frames, cut marks, anti-aliasing) and single specks do not widen the label. Only the
	// first block of ink counts — eBay's PDF carries the postage label on top and notes further down the page.
	// gap: the blank height in pixels that ends the block.
	function crop(canvas, gap, cutMarks) {
		var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
		var ink = new Uint8Array(canvas.width * canvas.height);
		var rows = new Uint32Array(canvas.height), across = new Uint32Array(canvas.width);
		var MIN = 3;

		for (var p = 0; p < ink.length; p++) {
			if (data[p * 4] + data[p * 4 + 1] + data[p * 4 + 2] < 384) {
				ink[p] = 1;
			}
		}

		if (cutMarks) {
			dropCutMarks(ink, canvas.width, canvas.height);
		}

		for (var q = 0; q < ink.length; q++) {
			if (ink[q]) {
				across[q % canvas.width]++;
			}
		}

		// Lines through nearly the whole picture are a page frame, not the label.
		for (var y = 0; y < canvas.height; y++) {
			for (var x = 0; x < canvas.width; x++) {
				if (ink[y * canvas.width + x] && across[x] < canvas.height * 0.8) {
					rows[y]++;
				}
			}

			if (rows[y] > canvas.width * 0.8) {
				rows[y] = 0;
			}
		}

		var top = rows.findIndex(function (n) { return n >= MIN; });

		if (top < 0) {
			return null;
		}

		var bottom = top;

		for (var row = top, blank = 0; row < canvas.height && blank < gap; row++) {
			if (rows[row] >= MIN) {
				bottom = row;
				blank = 0;
			} else {
				blank++;
			}
		}

		var columns = new Uint32Array(canvas.width);

		for (var y2 = top; y2 <= bottom; y2++) {
			for (var x2 = 0; x2 < canvas.width; x2++) {
				if (ink[y2 * canvas.width + x2] && across[x2] < canvas.height * 0.8) {
					columns[x2]++;
				}
			}
		}

		var left = columns.findIndex(function (n) { return n >= MIN; });
		var right = canvas.width - 1;
		while (columns[right] < MIN) { right--; }

		var pad = Math.round(gap / 12);
		left = Math.max(0, left - pad);
		top = Math.max(0, top - pad);
		right = Math.min(canvas.width - 1, right + pad);
		bottom = Math.min(canvas.height - 1, bottom + pad);

		var width = right - left + 1, height = bottom - top + 1;
		// Long side along the tape: the short side then fills its width and the label prints large.
		var upright = width > height;
		var out = document.createElement('canvas');
		out.width = upright ? height : width;
		out.height = upright ? width : height;
		var context = out.getContext('2d');

		if (upright) {
			context.translate(out.width, 0);
			context.rotate(Math.PI / 2);
		}

		context.drawImage(canvas, left, top, width, height, 0, 0, width, height);
		context.setTransform(1, 0, 0, 1, 0, 0);
		// Pure black and white: grey edges are dithered by the thermal printer and blur the matrix code.
		var image = context.getImageData(0, 0, out.width, out.height);

		for (var k = 0; k < image.data.length; k += 4) {
			var value = image.data[k] + image.data[k + 1] + image.data[k + 2] < 540 ? 0 : 255;
			image.data[k] = image.data[k + 1] = image.data[k + 2] = value;
			image.data[k + 3] = 255;
		}

		context.putImageData(image, 0, 0);
		out.upright = upright;
		return out;
	}

	async function open(file) {
		return pdfjsLib.getDocument({ data: await file.arrayBuffer(), cMapUrl: taxmodLabel62.cmaps, cMapPacked: true, standardFontDataUrl: taxmodLabel62.fonts }).promise;
	}

	// Text items grouped into lines, page after page, each page top to bottom and each line's words left to right.
	async function lines(file) {
		var pdf = await open(file);
		var all = [];

		for (var number = 1; number <= pdf.numPages; number++) {
			var content = await (await pdf.getPage(number)).getTextContent();
			var rows = [];

			content.items.forEach(function (item) {
				if (!item.str.trim()) {
					return;
				}

				var y = item.transform[5];
				var row = rows.find(function (r) { return Math.abs(r.y - y) < 3; });

				if (!row) {
					row = { y: y, words: [] };
					rows.push(row);
				}

				row.words.push({ x: item.transform[4], text: item.str.trim() });
			});

			rows.sort(function (a, b) { return b.y - a.y; }).forEach(function (row) {
				all.push(row.words.sort(function (a, b) { return a.x - b.x; }).map(function (w) { return w.text; }).join(' ').replace(/\s+/g, ' '));
			});
		}

		return all;
	}

	// The Post shop's Internetmarke carries a small cross at each corner to cut along; counted as ink they keep the label
	// small (Stefan 20.11. «Etwas klein»). Ink is grouped in 1 mm cells; a group no larger than a cross that sits in a
	// corner of all the ink is dropped. Only for callers that ask (cutMarks), so eBay's DHL label stays as it was.
	function dropCutMarks(ink, width, height) {
		var cell = Math.round(DPI / 25.4), cols = Math.ceil(width / cell), lines = Math.ceil(height / cell);
		var grid = new Uint8Array(cols * lines), group = new Int32Array(cols * lines).fill(-1), boxes = [];

		for (var p = 0; p < ink.length; p++) {
			if (ink[p]) {
				grid[Math.floor(Math.floor(p / width) / cell) * cols + Math.floor((p % width) / cell)] = 1;
			}
		}

		for (var start = 0; start < grid.length; start++) {
			if (!grid[start] || group[start] >= 0) {
				continue;
			}

			var box = { left: cols, top: lines, right: 0, bottom: 0 }, stack = [start];
			group[start] = boxes.length;

			while (stack.length) {
				var at = stack.pop(), x = at % cols, y = Math.floor(at / cols);
				box.left = Math.min(box.left, x); box.right = Math.max(box.right, x);
				box.top = Math.min(box.top, y); box.bottom = Math.max(box.bottom, y);

				for (var dy = -1; dy <= 1; dy++) {
					for (var dx = -1; dx <= 1; dx++) {
						var nx = x + dx, ny = y + dy, next = ny * cols + nx;

						if (nx >= 0 && ny >= 0 && nx < cols && ny < lines && grid[next] && group[next] < 0) {
							group[next] = boxes.length;
							stack.push(next);
						}
					}
				}
			}

			boxes.push(box);
		}

		var all = boxes.reduce(function (sum, b) {
			return { left: Math.min(sum.left, b.left), top: Math.min(sum.top, b.top), right: Math.max(sum.right, b.right), bottom: Math.max(sum.bottom, b.bottom) };
		}, { left: cols, top: lines, right: 0, bottom: 0 });
		var SMALL = 7, NEAR = 3;
		var marks = boxes.map(function (b) {
			var small = b.right - b.left < SMALL && b.bottom - b.top < SMALL;
			var side = b.left - all.left < NEAR || all.right - b.right < NEAR;
			var end = b.top - all.top < NEAR || all.bottom - b.bottom < NEAR;
			return small && side && end;
		});

		for (var i = 0; i < ink.length; i++) {
			if (ink[i] && marks[group[Math.floor(Math.floor(i / width) / cell) * cols + Math.floor((i % width) / cell)]]) {
				ink[i] = 0;
			}
		}
	}

	async function labels(file, cutMarks) {
		var pdf = await open(file);
		var pages = [];

		for (var number = 1; number <= pdf.numPages; number++) {
			var page = await pdf.getPage(number);
			var viewport = page.getViewport({ scale: DPI / 72 });
			var canvas = document.createElement('canvas');
			canvas.width = Math.ceil(viewport.width);
			canvas.height = Math.ceil(viewport.height);
			var context = canvas.getContext('2d');
			context.fillStyle = '#fff';
			context.fillRect(0, 0, canvas.width, canvas.height);
			// Print intent with form fields drawn in: eBay's postage label keeps stamp and address in annotations.
			await page.render({ canvasContext: context, viewport: viewport, intent: 'print', annotationMode: pdfjsLib.AnnotationMode.ENABLE_STORAGE }).promise;
			var label = crop(canvas, Math.round(DPI / 25.4 * 12), cutMarks);

			if (label) {
				pages.push(label);
			}
		}

		return pages;
	}

	// Order and seller go into the empty bottom right corner of the label itself, so the label keeps the full page.
	function stamp(label, caption) {
		if (!caption) {
			return;
		}

		var context = label.getContext('2d');
		var size = Math.round(Math.min(label.width, label.height) * 0.035);
		var margin = Math.round(size * 0.6);
		context.save();
		context.fillStyle = '#000';
		context.font = size + 'px sans-serif';
		context.textAlign = 'right';

		if (label.upright) {
			context.translate(margin, label.height - margin);
			context.rotate(Math.PI / 2);
		} else {
			context.translate(label.width - margin, label.height - margin);
		}

		context.fillText(caption, 0, 0);
		context.restore();
	}

	function print(pages, caption) {
		var frame = document.createElement('iframe');
		frame.style.cssText = 'position:fixed;width:0;height:0;border:0';
		document.body.appendChild(frame);
		var doc = frame.contentDocument;
		var body = '';
		pages.forEach(function (label) {
			var width = Math.min(PRINT_MM, FREE_MM * label.width / label.height);
			stamp(label, caption);
			body += '<section><img style="width:' + width.toFixed(1) + 'mm" src="' + label.toDataURL('image/png') + '" alt=""></section>';
		});

		doc.open();
		doc.write('<!doctype html><html><head><meta charset="utf-8"><style>'
			+ '@page{size:' + TAPE_MM + 'mm ' + PAGE_MM + 'mm;margin:0}'
			+ 'body{margin:0;font:7pt/1.2 sans-serif}section{padding:4.5mm 2mm}section+section{break-before:page}'
			+ 'img{display:block;margin:0 auto;image-rendering:pixelated}</style></head><body>' + body + '</body></html>');
		doc.close();

		var images = Array.prototype.slice.call(doc.images);

		// Settled once the print dialog is closed: a page that goes on (Versand records the stamp and reloads) must not
		// leave before, or the frame and its print are gone (Stefan 20.11.: «er druckt nicht»).
		return Promise.all(images.map(function (image) {
			return image.complete ? null : new Promise(function (done) { image.onload = done; });
		})).then(function () {
			return new Promise(function (done) {
				var view = frame.contentWindow;
				view.focus();
				// Chrome holds here until the dialog is closed, so what follows runs after the print.
				view.print();
				done();
			});
		}).then(function () {
			setTimeout(function () { frame.remove(); }, 60000);
		});
	}

	// options.cutMarks: the PDF has corner crosses to cut along (the Post shop's stamp); they are left out of the label.
	function run(source, caption, options) {
		return labels(source, !!(options && options.cutMarks)).then(function (pages) {
			if (!pages.length) {
				window.alert(taxmodLabel62.empty);
				return;
			}

			return print(pages, caption).then(function () {
				return true;
			});
		}).catch(function (failure) {
			window.alert(taxmodLabel62.failed + ' ' + failure.message);
			return false;
		});
	}

	// The PDF from the owner's own disk: read here, never sent anywhere. A closed file dialog answers nothing.
	function pick() {
		return new Promise(function (done) {
			var input = document.createElement('input');
			input.type = 'file';
			input.accept = 'application/pdf,.pdf';
			input.addEventListener('change', function () {
				done(input.files && input.files.length ? input.files[0] : null);
			});
			input.click();
		});
	}

	taxmodLabel62.run = run;
	taxmodLabel62.pick = pick;
	taxmodLabel62.lines = lines;

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-taxmod-label62-pick]');

		if (!button) {
			return;
		}

		event.preventDefault();
		pick().then(function (file) {
			if (!file) {
				return;
			}

			button.disabled = true;
			run(file, button.getAttribute('data-taxmod-label62-pick'), { cutMarks: button.hasAttribute('data-taxmod-label62-cut-marks') }).finally(function () {
				button.disabled = false;
			});
		});
	});
}());
