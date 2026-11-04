/**
 * Visuals in answers, drawn by the widget instead of by an image model.
 *
 * A bot with visuals on may answer with a fenced block:
 *   ```chart     {"type":"bar|line|pie","title":"","labels":[],"series":[{"name":"","values":[]}]}
 *   ```diagram   {"nodes":[{"id":"","label":""}],"links":[{"from":"","to":"","label":""}]}
 *   ```timeline  {"items":[{"when":"","label":""}]}
 *   ```svg       <svg>…</svg>
 * The data kinds are laid out here and every value is escaped as it is
 * written, so the markup is ours alone. An svg block is the model's own
 * drawing: it is taken apart into tags and rebuilt from an allowlist, so a
 * script, an event handler, a foreign object or a link cannot survive. Anything
 * that will not draw returns null and the answer shows the block as code.
 */
(function (root, factory) {
    if (typeof module === "object" && module.exports) { module.exports = factory(); }
    else { root.__ChatbotVisuals = factory(); }
})(typeof globalThis !== "undefined" ? globalThis : this, function () {

    var KINDS = { chart: 1, diagram: 1, timeline: 1, svg: 1 };
    var COLOURS = ["#2563eb", "#f59e0b", "#10b981", "#ef4444", "#8b5cf6", "#06b6d4", "#ec4899", "#84cc16"];
    var MAX_SVG = 50000;

    function esc(text) {
        return String(text == null ? "" : text)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function num(v) { return typeof v === "number" && isFinite(v) ? v : null; }
    function r1(v) { return Math.round(v * 10) / 10; }
    function clip(text, n) { text = String(text == null ? "" : text); return text.length > n ? text.slice(0, n - 1) + "…" : text; }

    function open(w, h, label) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + " " + h + '" width="100%" role="img" aria-label="' +
            esc(label || "drawing") + '" font-family="inherit" font-size="13" fill="currentColor">';
    }

    // --- charts -------------------------------------------------------------

    function chart(spec) {
        if (!spec || !Array.isArray(spec.labels) || !Array.isArray(spec.series) || !spec.labels.length) return null;
        var labels = spec.labels.slice(0, 50).map(function (l) { return clip(l, 18); });
        var series = spec.series.filter(function (s) { return s && Array.isArray(s.values); }).slice(0, 8);
        if (!series.length) return null;
        var type = spec.type === "line" || spec.type === "pie" ? spec.type : "bar";
        return type === "pie" ? pie(spec, labels, series[0]) : axes(spec, labels, series, type);
    }

    function legend(series, y) {
        if (series.length < 2) return "";
        return series.map(function (s, k) {
            var x = 40 + k * 90;
            return '<rect x="' + x + '" y="' + y + '" width="9" height="9" fill="' + COLOURS[k % COLOURS.length] + '"/>' +
                '<text x="' + (x + 13) + '" y="' + (y + 8) + '">' + esc(clip(s.name, 12)) + "</text>";
        }).join("");
    }

    function axes(spec, labels, series, type) {
        // Sized for a chat bubble (about 250-320px wide), so text stays readable.
        var W = 320, H = 240, L = 40, T = spec.title ? 28 : 12, B = 46, R = 8;
        var values = [];
        series.forEach(function (s) { s.values.forEach(function (v) { if (num(v) !== null) values.push(v); }); });
        if (!values.length) return null;
        var top = Math.max.apply(null, values.concat([0])), low = Math.min.apply(null, values.concat([0]));
        if (top === low) top = low + 1;
        var plotW = W - L - R, plotH = H - T - B;
        var y = function (v) { return T + (top - v) / (top - low) * plotH; };
        var out = open(W, H, spec.title || "chart");
        if (spec.title) out += '<text x="' + W / 2 + '" y="16" text-anchor="middle" font-size="13" font-weight="600">' + esc(clip(spec.title, 60)) + "</text>";
        for (var g = 0; g <= 4; g++) {
            var v = low + (top - low) * g / 4, gy = r1(y(v));
            out += '<line x1="' + L + '" y1="' + gy + '" x2="' + (W - R) + '" y2="' + gy + '" stroke="currentColor" stroke-opacity="0.15"/>' +
                '<text x="' + (L - 4) + '" y="' + (gy + 3) + '" text-anchor="end" fill-opacity="0.7">' + esc(r1(v)) + "</text>";
        }
        var slot = plotW / labels.length;
        labels.forEach(function (label, i) {
            out += '<text x="' + r1(L + slot * (i + 0.5)) + '" y="' + (H - B + 14) + '" text-anchor="middle">' + esc(label) + "</text>";
        });
        series.forEach(function (s, k) {
            var colour = COLOURS[k % COLOURS.length];
            if (type === "bar") {
                var bw = slot * 0.8 / series.length;
                labels.forEach(function (_, i) {
                    var val = num(s.values[i]);
                    if (val === null) return;
                    var x = L + slot * i + slot * 0.1 + bw * k, y0 = y(Math.max(val, 0)), h = Math.abs(y(val) - y(0));
                    out += '<rect class="bar" x="' + r1(x) + '" y="' + r1(y0) + '" width="' + r1(bw) + '" height="' + r1(Math.max(h, 0.5)) +
                        '" fill="' + colour + '"><title>' + esc(labels[i] + ": " + val) + "</title></rect>";
                });
            } else {
                var points = [];
                labels.forEach(function (_, i) {
                    var val = num(s.values[i]);
                    if (val !== null) points.push(r1(L + slot * (i + 0.5)) + "," + r1(y(val)));
                });
                out += '<polyline points="' + points.join(" ") + '" fill="none" stroke="' + colour + '" stroke-width="2"/>';
            }
        });
        return out + legend(series, H - 18) + "</svg>";
    }

    function pie(spec, labels, s) {
        var W = 320, H = 240, cx = 80, cy = 128, rad = 70;
        var parts = labels.map(function (l, i) { return { label: l, value: Math.max(num(s.values[i]) || 0, 0) }; })
            .filter(function (p) { return p.value > 0; });
        var total = parts.reduce(function (a, p) { return a + p.value; }, 0);
        if (!total) return null;
        var out = open(W, H, spec.title || "chart"), angle = -Math.PI / 2;
        if (spec.title) out += '<text x="' + W / 2 + '" y="16" text-anchor="middle" font-size="13" font-weight="600">' + esc(clip(spec.title, 60)) + "</text>";
        parts.forEach(function (p, i) {
            var sweep = p.value / total * Math.PI * 2, end = angle + sweep, colour = COLOURS[i % COLOURS.length];
            var d = parts.length === 1
                ? "M" + cx + "," + (cy - rad) + " A" + rad + "," + rad + " 0 1,1 " + (cx - 0.01) + "," + (cy - rad) + " Z"
                : "M" + cx + "," + cy + " L" + r1(cx + rad * Math.cos(angle)) + "," + r1(cy + rad * Math.sin(angle)) +
                  " A" + rad + "," + rad + " 0 " + (sweep > Math.PI ? 1 : 0) + ",1 " + r1(cx + rad * Math.cos(end)) + "," + r1(cy + rad * Math.sin(end)) + " Z";
            out += '<path d="' + d + '" fill="' + colour + '"><title>' + esc(p.label + ": " + p.value) + "</title></path>" +
                '<rect x="162" y="' + (40 + i * 20) + '" width="10" height="10" fill="' + colour + '"/>' +
                '<text x="177" y="' + (50 + i * 20) + '">' + esc(clip(p.label, 12) + " " + Math.round(p.value / total * 100) + "%") + "</text>";
            angle = end;
        });
        return out + "</svg>";
    }

    // --- diagrams and timelines ---------------------------------------------------

    var markers = 0;

    function diagram(spec) {
        if (!spec || !Array.isArray(spec.nodes) || !spec.nodes.length) return null;
        var nodes = spec.nodes.filter(function (n) { return n && n.id != null; }).slice(0, 40);
        var byId = {};
        nodes.forEach(function (n) { byId[String(n.id)] = { id: String(n.id), label: clip(n.label == null ? n.id : n.label, 22), layer: 0 }; });
        var links = (Array.isArray(spec.links) ? spec.links : []).slice(0, 60).filter(function (l) {
            return l && byId[String(l.from)] && byId[String(l.to)] && String(l.from) !== String(l.to);
        });
        // Longest path from the starts, capped so a loop cannot run forever.
        for (var pass = 0; pass < nodes.length; pass++) {
            var moved = false;
            links.forEach(function (l) {
                var a = byId[String(l.from)], b = byId[String(l.to)];
                if (b.layer < a.layer + 1 && a.layer + 1 < nodes.length) { b.layer = a.layer + 1; moved = true; }
            });
            if (!moved) break;
        }
        var layers = [];
        Object.keys(byId).forEach(function (k) { var n = byId[k]; (layers[n.layer] = layers[n.layer] || []).push(n); });
        layers = layers.filter(Boolean);
        var BW = 120, BH = 34, GX = 16, GY = 44;
        var widest = Math.max.apply(null, layers.map(function (l) { return l.length; }));
        var W = Math.max(widest * (BW + GX) + GX, 200), H = layers.length * (BH + GY) + GY - 10;
        layers.forEach(function (layer, row) {
            var offset = (W - layer.length * (BW + GX) + GX) / 2;
            layer.forEach(function (n, i) { n.x = offset + i * (BW + GX); n.y = 20 + row * (BH + GY); });
        });
        var id = "c4arrow" + (++markers);
        var out = open(W, H, "diagram") + '<defs><marker id="' + id + '" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">' +
            '<path d="M0,0 L10,5 L0,10 Z" fill="currentColor"/></marker></defs>';
        links.forEach(function (l) {
            var a = byId[String(l.from)], b = byId[String(l.to)];
            var x1 = a.x + BW / 2, y1 = b.y > a.y ? a.y + BH : a.y, x2 = b.x + BW / 2, y2 = b.y > a.y ? b.y : b.y + BH;
            out += '<line class="link" x1="' + r1(x1) + '" y1="' + r1(y1) + '" x2="' + r1(x2) + '" y2="' + r1(y2) +
                '" stroke="currentColor" stroke-opacity="0.6" marker-end="url(#' + id + ')"/>';
            if (l.label) out += '<text x="' + r1((x1 + x2) / 2 + 4) + '" y="' + r1((y1 + y2) / 2) + '" fill-opacity="0.8">' + esc(clip(l.label, 20)) + "</text>";
        });
        Object.keys(byId).forEach(function (k) {
            var n = byId[k];
            out += '<rect class="node" x="' + r1(n.x) + '" y="' + r1(n.y) + '" width="' + BW + '" height="' + BH + '" rx="7" fill="#2563eb" fill-opacity="0.12" stroke="#2563eb"/>' +
                '<text x="' + r1(n.x + BW / 2) + '" y="' + r1(n.y + BH / 2 + 4) + '" text-anchor="middle">' + esc(n.label) + "</text>";
        });
        return out + "</svg>";
    }

    function timeline(spec) {
        if (!spec || !Array.isArray(spec.items) || !spec.items.length) return null;
        var items = spec.items.filter(Boolean).slice(0, 40), H = items.length * 34 + 16;
        var out = open(300, H, "timeline") + '<line x1="86" y1="8" x2="86" y2="' + (H - 8) + '" stroke="currentColor" stroke-opacity="0.3" stroke-width="2"/>';
        items.forEach(function (item, i) {
            var y = 22 + i * 34;
            out += '<text x="76" y="' + (y + 5) + '" text-anchor="end" font-weight="600">' + esc(clip(item.when, 10)) + "</text>" +
                '<circle cx="86" cy="' + y + '" r="5" fill="#2563eb"/>' +
                '<text x="100" y="' + (y + 5) + '">' + esc(clip(item.label, 24)) + "</text>";
        });
        return out + "</svg>";
    }

    // --- svg written by the model -----------------------------------------------------

    var ELEMENTS = {};
    ["svg", "g", "path", "rect", "circle", "ellipse", "line", "polyline", "polygon", "text", "tspan",
     "title", "desc", "defs", "linearGradient", "radialGradient", "stop", "marker"].forEach(function (n) {
        ELEMENTS[n.toLowerCase()] = n;
    });
    // Dropped with everything inside them.
    var SWALLOW = { script: 1, style: 1, foreignobject: 1, iframe: 1, object: 1, embed: 1 };
    var ATTRIBUTES = {};
    ["viewBox", "width", "height", "x", "y", "x1", "y1", "x2", "y2", "cx", "cy", "r", "rx", "ry", "fx", "fy",
     "d", "points", "fill", "stroke", "stroke-width", "stroke-dasharray", "stroke-linecap", "stroke-linejoin",
     "opacity", "fill-opacity", "stroke-opacity", "transform", "font-size", "font-family", "font-weight",
     "font-style", "text-anchor", "dominant-baseline", "id", "offset", "stop-color", "stop-opacity",
     "gradientUnits", "gradientTransform", "marker-end", "marker-start", "marker-mid", "markerWidth",
     "markerHeight", "refX", "refY", "orient", "dx", "dy", "preserveAspectRatio", "xmlns"].forEach(function (n) {
        ATTRIBUTES[n.toLowerCase()] = n;
    });
    var BAD_VALUE = /javascript:|vbscript:|data:|expression\s*\(|[<>]/i;
    var URL_VALUE = /url\s*\(/i;
    var LOCAL_URL = /^\s*url\(\s*#[\w-]+\s*\)\s*$/;
    var TOKEN = /<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<![^>]*>|<\?[\s\S]*?\?>|<(\/?)([A-Za-z][\w:.-]*)((?:\s+[^\s=\/>]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*(\/?)>|[^<]+|</g;
    var ATTR = /([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;

    function decode(text) {
        return String(text).replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"')
            .replace(/&#39;|&apos;/g, "'").replace(/&amp;/g, "&");
    }

    function attributes(raw) {
        var out = "", m;
        ATTR.lastIndex = 0;
        while ((m = ATTR.exec(raw))) {
            var name = ATTRIBUTES[m[1].toLowerCase()];
            if (!name) continue;
            var value = decode(m[2] != null ? m[2] : m[3] != null ? m[3] : m[4] != null ? m[4] : "");
            if (BAD_VALUE.test(value) || (URL_VALUE.test(value) && !LOCAL_URL.test(value))) continue;
            out += " " + name + '="' + esc(value) + '"';
        }
        return out;
    }

    function sanitizeSvg(source) {
        if (typeof source !== "string" || source.length > MAX_SVG) return null;
        var out = "", stack = [], swallow = null, m, sawRoot = false;
        TOKEN.lastIndex = 0;
        while ((m = TOKEN.exec(source))) {
            var token = m[0];
            if (!m[2]) {
                if (swallow || token.charAt(0) === "<") continue;  // comments, doctype, stray "<"
                if (stack.length) out += esc(decode(token));
                continue;
            }
            var closing = m[1] === "/", lower = m[2].toLowerCase(), selfClosing = m[4] === "/";
            if (swallow) {
                if (closing && lower === swallow) swallow = null;
                continue;
            }
            if (SWALLOW[lower] || lower.indexOf(":") !== -1 && !ELEMENTS[lower]) {
                if (!closing && !selfClosing) swallow = lower;
                continue;
            }
            var name = ELEMENTS[lower];
            if (!name) continue;
            if (closing) {
                var at = stack.lastIndexOf(name);
                if (at === -1) continue;
                while (stack.length > at) out += "</" + stack.pop() + ">";
                continue;
            }
            if (!stack.length && name !== "svg") continue;
            if (name === "svg" && !stack.length) {
                if (sawRoot) break;
                sawRoot = true;
            }
            out += "<" + name + attributes(m[3] || "") + (name === "svg" && !stack.length ? ' width="100%"' : "") + (selfClosing ? "/>" : ">");
            if (!selfClosing) stack.push(name);
        }
        while (stack.length) out += "</" + stack.pop() + ">";
        if (!sawRoot) return null;
        return out.replace(/^<svg([^>]*?) width="([^"]*)"([^>]*?) width="100%"/, '<svg$1$3 width="100%"');
    }

    function render(lang, source) {
        if (!KINDS[lang]) return null;
        if (lang === "svg") return sanitizeSvg(source);
        var spec;
        try { spec = JSON.parse(source); } catch (e) { return null; }
        if (!spec || typeof spec !== "object") return null;
        try {
            return lang === "chart" ? chart(spec) : lang === "diagram" ? diagram(spec) : timeline(spec);
        } catch (e) {
            return null;
        }
    }

    return { render: render, sanitizeSvg: sanitizeSvg, KINDS: KINDS };
});
