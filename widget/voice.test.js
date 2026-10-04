const test = require("node:test");
const assert = require("node:assert");
const voice = require("./voice.js");

test("an answer is heard without its Markdown, citations or links", () => {
    const said = voice.speakable("**Warranty** is two years [1].\n\n- See [the policy](https://x.test/p) for more\n- `X200` covered");
    assert.strictEqual(said, "Warranty is two years .\nSee the policy for more\nX200 covered");
});

test("code blocks are not read out", () => {
    assert.strictEqual(voice.speakable("Run this:\n```\nrm -rf /\n```\nDone."), "Run this:\nDone.");
});

test("a table is read row by row", () => {
    const said = voice.speakable("| Item | Price |\n|---|---|\n| Kettle | RM 99 |");
    assert.ok(said.includes("Kettle, RM 99"), said);
    assert.ok(!said.includes("|") && !said.includes("---"), said);
});

test("Malay and English are told apart", () => {
    assert.strictEqual(voice.detectLanguage("Jaminan untuk produk ini adalah dua tahun dan boleh dituntut."), "ms");
    assert.strictEqual(voice.detectLanguage("The warranty on this product is two years and you can claim it."), "en");
    assert.strictEqual(voice.detectLanguage("X200", "ms"), "ms");
});

test("slots are named by language and gender", () => {
    assert.strictEqual(voice.slotFor("ms", "male"), "ms_male");
    assert.strictEqual(voice.slotFor("fr", "other"), "en_female");
});

test("a streaming answer comes out a sentence at a time", () => {
    const splitter = new voice.SentenceSplitter(10);
    assert.deepStrictEqual(splitter.feed("The warranty is two"), []);
    assert.deepStrictEqual(splitter.feed(" years. It covers parts "), ["The warranty is two years."]);
    assert.deepStrictEqual(splitter.feed("and labour."), []);
    assert.deepStrictEqual(splitter.flush(), ["It covers parts and labour."]);
});

test("a decimal point does not end a sentence", () => {
    const splitter = new voice.SentenceSplitter(5);
    assert.deepStrictEqual(splitter.feed("It costs RM 3.50 today. "), ["It costs RM 3.50 today."]);
});

const device = [
    { name: "Microsoft Aria Online (Natural) - English (United States)", lang: "en-US", localService: false },
    { name: "Microsoft Guy Online (Natural) - English (United States)", lang: "en-US", localService: false },
    { name: "Microsoft David - English (United States)", lang: "en-US", localService: true },
    { name: "Microsoft Yasmin Online (Natural) - Malay (Malaysia)", lang: "ms-MY", localService: false },
    { name: "Microsoft Osman Online (Natural) - Malay (Malaysia)", lang: "ms-MY", localService: false },
];

test("all four voices are found on a device that has them", () => {
    assert.match(voice.pickVoice(device, "en", "female").voice.name, /Aria/);
    assert.match(voice.pickVoice(device, "en", "male").voice.name, /Guy/);
    assert.match(voice.pickVoice(device, "ms", "female").voice.name, /Yasmin/);
    assert.match(voice.pickVoice(device, "ms", "male").voice.name, /Osman/);
    assert.strictEqual(voice.pickVoice(device, "ms", "male").match, "exact");
});

test("a device with one Malay voice still speaks Malay", () => {
    const chrome = [{ name: "Google Bahasa Melayu", lang: "ms-MY" }, { name: "Google US English", lang: "en-US" }];
    const picked = voice.pickVoice(chrome, "ms", "male");
    assert.strictEqual(picked.voice.name, "Google Bahasa Melayu");
    assert.strictEqual(picked.match, "language");
});

test("Indonesian never stands in for Malay, and nothing is nothing", () => {
    assert.deepStrictEqual(voice.pickVoice([{ name: "Damayanti", lang: "id-ID" }], "ms", "female"), { voice: null, match: "none" });
    assert.deepStrictEqual(voice.pickVoice([], "en", "female"), { voice: null, match: "none" });
});

test("Female is not mistaken for male", () => {
    assert.strictEqual(voice.genderOf({ name: "Google UK English Female" }), "female");
    assert.strictEqual(voice.genderOf({ name: "Google UK English Male" }), "male");
});
