const test = require("node:test");
const assert = require("node:assert");

const visuals = require("./visuals.js");
globalThis.__ChatbotVisuals = visuals;
const { render: markdown } = require("./markdown.js");

const count = (html, needle) => html.split(needle).length - 1;

// --- data blocks ----------------------------------------------------------

test("a bar chart has one bar per value and escaped labels", () => {
  const svg = visuals.render("chart", JSON.stringify({
    type: "bar", title: "Sales <b>", labels: ["Jan", "Feb", "<i>Mar"],
    series: [{ name: "RM", values: [10, 20, 5] }],
  }));
  assert.ok(svg.startsWith("<svg"));
  assert.strictEqual(count(svg, "<rect class=\"bar\""), 3);
  assert.ok(!svg.includes("<b>") && !svg.includes("<i>"));
  assert.ok(svg.includes("&lt;i&gt;Mar"));
});

test("line and pie charts draw", () => {
  const spec = { labels: ["A", "B", "C"], series: [{ name: "x", values: [1, 3, 2] }] };
  assert.ok(visuals.render("chart", JSON.stringify({ ...spec, type: "line" })).includes("<polyline"));
  assert.strictEqual(count(visuals.render("chart", JSON.stringify({ ...spec, type: "pie" })), "<path"), 3);
});

test("a diagram has a box per node and a line per link", () => {
  const svg = visuals.render("diagram", JSON.stringify({
    nodes: [{ id: "a", label: "Lodge" }, { id: "b", label: "Review" }, { id: "c", label: "Close" }],
    links: [{ from: "a", to: "b" }, { from: "b", to: "c", label: "done" }],
  }));
  assert.strictEqual(count(svg, "<rect class=\"node\""), 3);
  assert.strictEqual(count(svg, "<line class=\"link\""), 2);
  assert.ok(svg.includes("Review") && svg.includes("done"));
});

test("a diagram with a loop still draws", () => {
  const svg = visuals.render("diagram", JSON.stringify({
    nodes: [{ id: "a", label: "A" }, { id: "b", label: "B" }],
    links: [{ from: "a", to: "b" }, { from: "b", to: "a" }],
  }));
  assert.strictEqual(count(svg, "<rect class=\"node\""), 2);
});

test("a timeline has a dot per item", () => {
  const svg = visuals.render("timeline", JSON.stringify({ items: [{ when: "2025", label: "Plan" }, { when: "2026", label: "Build" }] }));
  assert.strictEqual(count(svg, "<circle"), 2);
});

test("broken data draws nothing", () => {
  assert.strictEqual(visuals.render("chart", "{not json"), null);
  assert.strictEqual(visuals.render("chart", JSON.stringify({ labels: "x" })), null);
  assert.strictEqual(visuals.render("diagram", JSON.stringify({ nodes: [] })), null);
  assert.strictEqual(visuals.render("table", "{}"), null);
});

// --- svg written by the model ---------------------------------------------------

test("a drawing keeps its shapes and text", () => {
  const svg = visuals.render("svg", '<svg viewBox="0 0 100 50"><rect x="1" y="1" width="20" height="10" fill="#0a7"/><text x="5" y="40">Hi &amp; bye</text></svg>');
  assert.ok(svg.includes('<rect x="1" y="1" width="20" height="10" fill="#0a7"/>') || svg.includes('<rect x="1" y="1" width="20" height="10" fill="#0a7"></rect>'));
  assert.ok(svg.includes("Hi &amp; bye"));
  assert.ok(svg.includes('viewBox="0 0 100 50"'));
});

test("scripts, handlers, foreign objects and links are removed", () => {
  const svg = visuals.render("svg",
    '<svg onload="alert(1)"><script>alert(2)</script><foreignObject><div>x</div></foreignObject>' +
    '<a href="javascript:alert(3)"><circle r="4" onclick="alert(4)" fill="url(javascript:alert(5))"/></a>' +
    '<rect style="background:url(evil)" width="3" height="3" fill="url(#g1)"/><image href="http://x/y.png"/></svg>');
  for (const bad of ["onload", "alert", "script", "foreignObject", "javascript", "style=", "<image", "<a", "<div"]) {
    assert.ok(!svg.includes(bad), bad + " survived: " + svg);
  }
  assert.ok(svg.includes("<circle") && svg.includes('fill="url(#g1)"'));
});

test("something that is not an svg draws nothing", () => {
  assert.strictEqual(visuals.render("svg", "<div>hello</div>"), null);
  assert.strictEqual(visuals.render("svg", "just words"), null);
});

// --- inside an answer ---------------------------------------------------------

test("an answer's chart block is drawn and other code stays code", () => {
  const html = markdown('See:\n\n```chart\n{"type":"bar","labels":["a"],"series":[{"name":"n","values":[1]}]}\n```\n\n```js\nx<1\n```');
  assert.ok(html.includes('<div class="c4-visual"><svg'));
  assert.ok(html.includes("<pre><code class=\"language-js\">x&lt;1</code></pre>"));
});

test("a visual still arriving shows as code until its fence closes", () => {
  const html = markdown('```chart\n{"type":"bar"');
  assert.ok(!html.includes("c4-visual") && html.includes("<pre>"));
});

// --- found in the live run ------------------------------------------------------

test("a visual the model forgot to close still draws, and the text after it stays text", () => {
  const html = markdown('```diagram\n{"nodes":[{"id":"a","label":"Lodge"},{"id":"b","label":"Review"}],"links":[{"from":"a","to":"b"}]}\n-- Unit Aduan Awam');
  assert.ok(html.includes('<div class="c4-visual"><svg'), html);
  assert.ok(html.includes("<p>-- Unit Aduan Awam</p>"), html);
});

test("an svg the model forgot to close draws up to its end tag", () => {
  const html = markdown('```svg\n<svg viewBox="0 0 10 10"><circle r="3"/></svg>\nThat is a dot.');
  assert.ok(html.includes('<div class="c4-visual"><svg') && html.includes("<p>That is a dot.</p>"), html);
});

test("drawings are sized for a chat bubble, so their text can be read", () => {
  const width = (svg) => Number(/viewBox="0 0 (\d+)/.exec(svg)[1]);
  const size = (svg) => Number(/font-size="(\d+)"/.exec(svg)[1]);
  const chart = visuals.render("chart", JSON.stringify({ type: "bar", labels: ["a"], series: [{ name: "n", values: [1] }] }));
  const line = visuals.render("timeline", JSON.stringify({ items: [{ when: "Day 14", label: "Late fee" }] }));
  for (const svg of [chart, line]) {
    assert.ok(width(svg) <= 320, "too wide: " + width(svg));
    assert.ok(size(svg) >= 13, "text too small: " + size(svg));
  }
});
