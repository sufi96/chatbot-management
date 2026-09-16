/**
 * The small Markdown renderer the chat widget uses.
 *
 * It exists instead of a library because the widget is embedded on sites we
 * do not control, where a CDN dependency is both a network risk and a content
 * security policy problem. It covers what a support answer actually uses:
 * emphasis, code, lists, and tables.
 *
 * Everything is escaped before a single tag is produced, so the only markup
 * that ever reaches a host page is markup this file wrote. Model output and
 * anything a visitor quoted back are treated as text throughout.
 */
(function (root, factory) {
    if (typeof module === "object" && module.exports) { module.exports = factory(); }
    else { root.__ChatbotMarkdown = factory(); }
})(typeof globalThis !== "undefined" ? globalThis : this, function () {

    var ITEM = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/;
    var FENCE = /^\s*```([A-Za-z0-9_+-]*)\s*$/;
    var HEADING = /^(#{1,6})\s+(.*)$/;
    var RULE = /^\s*(-{3,}|\*{3,}|_{3,})\s*$/;
    var QUOTE = /^\s*>\s?(.*)$/;
    var SEPARATOR = /^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/;
    var SAFE_URL = /^(https?:\/\/|mailto:|tel:|\/|#)/i;

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    /** Inline formatting for one run of text. */
    function inline(src) {
        var codes = [];

        // Code spans are lifted out first so nothing inside them is treated
        // as formatting, then put back escaped at the end.
        var s = String(src).replace(/`([^`]+)`/g, function (_, code) {
            codes.push(code);
            return "\u0000C" + (codes.length - 1) + "\u0000";
        });

        s = escapeHtml(s);

        s = s.replace(/\[([^\]]*)\]\(([^)\s]*)\)/g, function (_, label, url) {
            // An unsafe scheme loses the link entirely rather than becoming
            // visible text, so a javascript: payload leaves no trace at all.
            if (!SAFE_URL.test(url)) return label;
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + "</a>";
        });

        s = s.replace(/\*\*\*([^*]+)\*\*\*/g, "<strong><em>$1</em></strong>");
        s = s.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
        s = s.replace(/(^|[^*\w])\*([^*\n]+)\*/g, "$1<em>$2</em>");
        s = s.replace(/~~([^~]+)~~/g, "<del>$1</del>");
        // A leading and trailing word character means an identifier such as
        // user_name_field, not emphasis.
        s = s.replace(/(^|[^_\w])_([^_\n]+)_(?!\w)/g, "$1<em>$2</em>");

        return s.replace(/\u0000C(\d+)\u0000/g, function (_, n) {
            return "<code>" + escapeHtml(codes[Number(n)]) + "</code>";
        });
    }

    function cells(line) {
        var trimmed = line.trim().replace(/^\|/, "").replace(/\|$/, "");
        return trimmed.split("|").map(function (c) { return c.trim(); });
    }

    function alignments(line) {
        return cells(line).map(function (c) {
            if (/^:-+:$/.test(c)) return "center";
            if (/^-+:$/.test(c)) return "right";
            if (/^:-+$/.test(c)) return "left";
            return "";
        });
    }

    function cell(tag, text, align) {
        var style = align ? ' style="text-align:' + align + '"' : "";
        return "<" + tag + style + ">" + inline(text) + "</" + tag + ">";
    }

    function isTableAt(lines, i) {
        return i + 1 < lines.length &&
            lines[i].indexOf("|") !== -1 &&
            SEPARATOR.test(lines[i + 1]) &&
            lines[i + 1].indexOf("-") !== -1;
    }

    function startsBlock(lines, i) {
        var line = lines[i];
        return line.trim() === "" || FENCE.test(line) || HEADING.test(line) ||
            RULE.test(line) || QUOTE.test(line) || ITEM.test(line) || isTableAt(lines, i);
    }

    function parseList(lines, i, baseIndent) {
        var ordered = /^\d/.test(lines[i].match(ITEM)[2]);
        var out = ordered ? "<ol>" : "<ul>";
        var open = false;

        while (i < lines.length) {
            var m = lines[i].match(ITEM);
            if (!m) break;

            var indent = m[1].length;
            if (indent < baseIndent) break;

            if (indent > baseIndent) {
                // A deeper marker belongs inside the item still being written.
                var nested = parseList(lines, i, indent);
                out += nested.html;
                i = nested.next;
                continue;
            }

            if (/^\d/.test(m[2]) !== ordered) break;
            if (open) out += "</li>";
            out += "<li>" + inline(m[3]);
            open = true;
            i++;
        }

        if (open) out += "</li>";
        return { html: out + (ordered ? "</ol>" : "</ul>"), next: i };
    }

    function render(text) {
        if (!text) return "";

        var lines = String(text).replace(/\r\n?/g, "\n").split("\n");
        var out = "";
        var i = 0;

        while (i < lines.length) {
            var line = lines[i];

            if (line.trim() === "") { i++; continue; }

            var fence = line.match(FENCE);
            if (fence) {
                var code = [];
                i++;
                while (i < lines.length && !FENCE.test(lines[i])) { code.push(lines[i]); i++; }
                i++;  // the closing fence, or past the end of a stream still arriving
                var lang = fence[1] ? ' class="language-' + fence[1] + '"' : "";
                out += "<pre><code" + lang + ">" + escapeHtml(code.join("\n")) + "</code></pre>";
                continue;
            }

            if (RULE.test(line)) { out += "<hr>"; i++; continue; }

            var heading = line.match(HEADING);
            if (heading) {
                var level = heading[1].length;
                out += "<h" + level + ">" + inline(heading[2]) + "</h" + level + ">";
                i++;
                continue;
            }

            if (QUOTE.test(line)) {
                var quoted = [];
                while (i < lines.length && QUOTE.test(lines[i])) {
                    quoted.push(lines[i].match(QUOTE)[1]);
                    i++;
                }
                out += "<blockquote>" + render(quoted.join("\n")) + "</blockquote>";
                continue;
            }

            if (isTableAt(lines, i)) {
                var align = alignments(lines[i + 1]);
                var head = cells(lines[i]).map(function (c, n) { return cell("th", c, align[n]); });
                // Wrapped so a wide table scrolls inside the bubble
                // rather than stretching it past the chat panel.
                out += '<div class="md-table"><table><thead><tr>' + head.join("") + "</tr></thead><tbody>";
                i += 2;
                while (i < lines.length && lines[i].indexOf("|") !== -1 && lines[i].trim() !== "") {
                    var row = cells(lines[i]).map(function (c, n) { return cell("td", c, align[n]); });
                    out += "<tr>" + row.join("") + "</tr>";
                    i++;
                }
                out += "</tbody></table></div>";
                continue;
            }

            if (ITEM.test(line)) {
                var list = parseList(lines, i, line.match(ITEM)[1].length);
                out += list.html;
                i = list.next;
                continue;
            }

            var para = [line];
            i++;
            while (i < lines.length && !startsBlock(lines, i)) { para.push(lines[i]); i++; }
            out += "<p>" + para.map(inline).join("<br>") + "</p>";
        }

        return out;
    }

    return { render: render };
});
