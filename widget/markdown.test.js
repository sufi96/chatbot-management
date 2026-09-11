const test = require("node:test");
const assert = require("node:assert");

const { render } = require("./markdown.js");

// --- safety -------------------------------------------------------------

test("markup in the model's text is escaped, never executed", () => {
  const html = render("<script>alert(1)</script>");
  assert.ok(!html.includes("<script>"));
  assert.ok(html.includes("&lt;script&gt;"));
});

test("ampersands and quotes are escaped", () => {
  assert.ok(render("Tom & Jerry").includes("Tom &amp; Jerry"));
});

test("a javascript url never becomes a link", () => {
  const html = render("[click](javascript:alert(1))");
  assert.ok(!html.includes("javascript:"));
});

test("an http link opens safely in a new tab", () => {
  const html = render("[docs](https://example.com/a)");
  assert.ok(html.includes('href="https://example.com/a"'));
  assert.ok(html.includes('rel="noopener noreferrer"'));
  assert.ok(html.includes('target="_blank"'));
});

// --- inline -------------------------------------------------------------

test("bold", () => assert.ok(render("a **b** c").includes("<strong>b</strong>")));

test("italic with asterisks", () => assert.ok(render("a *b* c").includes("<em>b</em>")));

test("italic with underscores", () => assert.ok(render("a _b_ c").includes("<em>b</em>")));

test("bold and italic together", () => {
  const html = render("***b***");
  assert.ok(html.includes("<strong>") && html.includes("<em>"));
});

test("strikethrough", () => assert.ok(render("~~gone~~").includes("<del>gone</del>")));

test("inline code", () => assert.ok(render("run `npm test` now").includes("<code>npm test</code>")));

test("markdown inside inline code stays literal", () => {
  const html = render("`**not bold**`");
  assert.ok(html.includes("**not bold**"));
  assert.ok(!html.includes("<strong>"));
});

test("an underscore inside a word does not start italics", () => {
  assert.ok(!render("user_name_field").includes("<em>"));
});

// --- code blocks --------------------------------------------------------

test("a fenced block keeps its language", () => {
  const html = render("```python\nprint(1)\n```");
  assert.ok(html.includes("<pre>"));
  assert.ok(html.includes('class="language-python"'));
  assert.ok(html.includes("print(1)"));
});

test("markdown inside a fenced block stays literal", () => {
  assert.ok(render("```\n**x**\n```").includes("**x**"));
});

test("a fence still streaming renders as a block, not stray backticks", () => {
  const html = render("```js\nlet a = 1;");
  assert.ok(html.includes("<pre>"));
  assert.ok(!html.includes("```"));
});

// --- lists --------------------------------------------------------------

test("an unordered list", () => {
  const html = render("- one\n- two");
  assert.ok(html.includes("<ul>"));
  assert.equal((html.match(/<li>/g) || []).length, 2);
});

test("an ordered list", () => {
  const html = render("1. one\n2. two");
  assert.ok(html.includes("<ol>"));
  assert.equal((html.match(/<li>/g) || []).length, 2);
});

test("a nested list sits inside its parent item", () => {
  const html = render("- one\n    - deeper\n- two");
  assert.equal((html.match(/<ul>/g) || []).length, 2);
});

test("list items carry inline formatting", () => {
  assert.ok(render("- **bold** item").includes("<strong>bold</strong>"));
});

// --- tables -------------------------------------------------------------

test("a table becomes a real table", () => {
  const html = render("| Name | Qty |\n| --- | --- |\n| Bolt | 4 |");
  assert.ok(html.includes("<table>"));
  assert.ok(html.includes("<th>Name</th>"));
  assert.ok(html.includes("<td>Bolt</td>"));
});

test("column alignment is honoured", () => {
  const html = render("| a | b |\n| :-- | --: |\n| 1 | 2 |");
  assert.ok(html.includes('style="text-align:right"'));
});

test("a table without its separator row yet is not drawn as a table", () => {
  assert.ok(!render("| Name | Qty |").includes("<table>"));
});

test("table cells carry inline formatting", () => {
  const html = render("| a | b |\n| --- | --- |\n| **x** | y |");
  assert.ok(html.includes("<strong>x</strong>"));
});

// --- blocks -------------------------------------------------------------

test("headings", () => {
  assert.ok(render("## Title").includes("<h2>Title</h2>"));
});

test("a blockquote", () => {
  assert.ok(render("> quoted").includes("<blockquote>"));
});

test("a horizontal rule", () => {
  assert.ok(render("---").includes("<hr"));
});

test("paragraphs are separated", () => {
  const html = render("one\n\ntwo");
  assert.equal((html.match(/<p>/g) || []).length, 2);
});

test("a single newline inside a paragraph becomes a line break", () => {
  assert.ok(render("one\ntwo").includes("<br"));
});

test("empty input renders nothing", () => {
  assert.equal(render("").trim(), "");
});

test("a table is wrapped so it can scroll instead of widening its bubble", () => {
  const html = render("| a | b |\n| --- | --- |\n| 1 | 2 |");
  assert.ok(html.includes('<div class="md-table">'));
  assert.ok(html.indexOf('<div class="md-table">') < html.indexOf("<table>"));
});
