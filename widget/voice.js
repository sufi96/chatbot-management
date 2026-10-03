/**
 * The widget's voice, minus the parts that need a browser.
 *
 * What to say, in which language, sentence by sentence, and which of a
 * device's voices comes closest to the one asked for. Playing sound and
 * listening live in widget.js; everything here is plain functions, so it is
 * tested under Node like markdown.js.
 *
 * Four voices, always the same four: English or Malay, female or male. A bot
 * and a visitor choose by language and gender, never by a vendor's voice name.
 */
(function (root, factory) {
    if (typeof module === "object" && module.exports) { module.exports = factory(); }
    else { root.__ChatbotVoice = factory(); }
})(typeof globalThis !== "undefined" ? globalThis : this, function () {

    var SLOTS = ["en_female", "en_male", "ms_female", "ms_male"];

    // Words that mark a sentence as one language or the other. Short and
    // common, so even a two-line answer has a few.
    var MALAY = ("yang dan untuk tidak ini itu dengan adalah boleh saya anda kami akan dari pada " +
                 "ke di dalam atau jika kerana juga sila terima kasih ialah oleh tersebut bagi " +
                 "hari kepada sahaja sudah belum lagi mana apa bagaimana berapa encik puan").split(" ");
    var ENGLISH = ("the and is are to of you your for with this that it be can will on in " +
                   "we our please thank have has not or if from at by".split(" "));

    /**
     * An answer as it should be heard: no Markdown, no citation numbers, no
     * links read out letter by letter, no code read out at all.
     */
    function speakable(text) {
        var s = String(text || "");
        s = s.replace(/```[\s\S]*?(```|$)/g, " ");                 // code blocks
        s = s.replace(/`([^`]*)`/g, "$1");                          // inline code
        s = s.replace(/!\[([^\]]*)\]\([^)]*\)/g, "$1");             // images
        s = s.replace(/\[([^\]]+)\]\((?:[^)]*)\)/g, "$1");          // links: their words
        s = s.replace(/https?:\/\/\S+/g, " ");                      // bare urls
        s = s.replace(/\[\d+(?:\s*,\s*\d+)*\]/g, "");               // citations [1], [1, 2]
        // Tables line by line: [ \t], not \s, so a pattern never eats the
        // line break and runs two rows together.
        s = s.replace(/^[ \t]*\|?[ \t]*:?-{2,}[-:| \t]*$/gm, "");    // table separators
        s = s.replace(/^[ \t]*\|(.*?)\|?[ \t]*$/gm, function (row, cells) { // table rows, cell by cell
            return cells.split("|").map(function (c) { return c.trim(); })
                .filter(Boolean).join(", ") + ".";
        });
        s = s.replace(/^\s{0,3}#{1,6}\s+/gm, "");                   // headings
        s = s.replace(/^\s*>\s?/gm, "");                            // quotes
        s = s.replace(/^\s*([-*+]|\d+[.)])\s+/gm, "");              // list markers
        s = s.replace(/(\*\*|__|\*|_|~~)(?=\S)([\s\S]*?\S)\1/g, "$2"); // emphasis
        s = s.replace(/[*_#>~]/g, " ");
        s = s.replace(/\s*,\s*(,\s*)+/g, ", ");
        return s.replace(/[ \t]+/g, " ").replace(/\s*\n\s*/g, "\n").trim();
    }

    /** "ms" or "en", by which language's common words the text uses more. */
    function detectLanguage(text, fallback) {
        var words = String(text || "").toLowerCase().match(/[a-z]+/g) || [];
        var ms = 0, en = 0;
        for (var i = 0; i < words.length; i++) {
            if (MALAY.indexOf(words[i]) !== -1) { ms++; }
            if (ENGLISH.indexOf(words[i]) !== -1) { en++; }
        }
        if (ms === en) { return fallback || "en"; }
        return ms > en ? "ms" : "en";
    }

    function slotFor(language, gender) {
        return (language === "ms" ? "ms" : "en") + "_" + (gender === "male" ? "male" : "female");
    }

    /**
     * Cuts a streaming answer into sentences as they complete, so the first
     * one can be spoken while the rest is still being written.
     */
    function SentenceSplitter(minimum) {
        this.buffer = "";
        this.minimum = minimum || 40;
    }

    // A full stop, question or exclamation mark, or a line break, followed by
    // whitespace. "3.5" and "e.g." without a following space do not end one.
    var BOUNDARY = /[.!?。]+["')\]]*\s+|\n+/g;

    SentenceSplitter.prototype.feed = function (text) {
        this.buffer += text || "";
        var out = [];
        var start = 0, match;
        BOUNDARY.lastIndex = 0;
        while ((match = BOUNDARY.exec(this.buffer)) !== null) {
            var end = match.index + match[0].length;
            // Very short pieces wait for the next, so "Yes." and "Hi!" are
            // not each a separate, choppy utterance.
            if (end - start >= this.minimum) {
                out.push(this.buffer.slice(start, end).trim());
                start = end;
            }
        }
        this.buffer = this.buffer.slice(start);
        return out.filter(Boolean);
    };

    SentenceSplitter.prototype.flush = function () {
        var rest = this.buffer.trim();
        this.buffer = "";
        return rest ? [rest] : [];
    };

    // Browsers say a voice's language but not its gender, so gender is read
    // from the names the common vendors give their voices.
    var FEMALE = /female|woman|ava|aria|jenny|emma|michelle|samantha|victoria|karen|moira|tessa|zira|susan|hazel|libby|sonia|natasha|yasmin|amira|siti|sara|zoe|serena|fiona|catherine|allison|nicky|joanna|salli|kendra|ivy|ruth/i;
    var MALE = /\bmale\b|\bman\b|andrew|guy|davis|brian|christopher|eric|roger|daniel|alex|fred|david|mark|george|ryan|thomas|osman|rizwan|james|aaron|arthur|oliver|matthew|justin|joey|stephen|tom\b|rishi/i;

    function genderOf(voice) {
        var name = String(voice && voice.name || "");
        // "Female" and "Male" both contain "male"; test female first.
        if (FEMALE.test(name)) { return "female"; }
        if (MALE.test(name)) { return "male"; }
        return "";
    }

    /**
     * The device voice closest to a language and gender, with how close it
     * came: "exact", "language" (right language, wrong or unknown gender),
     * "near" (Indonesian for Malay, which a Malay listener follows), or
     * "none". Natural and online voices are preferred over robotic ones.
     */
    function pickVoice(voices, language, gender) {
        var list = Array.prototype.slice.call(voices || []);
        var prefixes = language === "ms" ? ["ms"] : ["en"];

        function quality(voice) {
            var name = String(voice.name || "");
            return (/natural|neural|online|premium|enhanced|google/i.test(name) ? 2 : 0) + (voice.localService ? 0 : 1);
        }

        function best(candidates) {
            return candidates.sort(function (a, b) { return quality(b) - quality(a); })[0] || null;
        }

        function byLang(prefixList) {
            return list.filter(function (v) {
                var lang = String(v.lang || "").toLowerCase().replace("_", "-");
                return prefixList.some(function (p) { return lang === p || lang.indexOf(p + "-") === 0; });
            });
        }

        var same = byLang(prefixes);
        var exact = best(same.filter(function (v) { return genderOf(v) === gender; }));
        if (exact) { return { voice: exact, match: "exact" }; }
        if (same.length) {
            var unknown = best(same.filter(function (v) { return genderOf(v) === ""; }));
            return { voice: unknown || best(same), match: "language" };
        }
        if (language === "ms") {
            var near = byLang(["id"]);
            if (near.length) {
                var nearExact = best(near.filter(function (v) { return genderOf(v) === gender; }));
                return { voice: nearExact || best(near), match: "near" };
            }
        }
        return { voice: null, match: "none" };
    }

    return {
        SLOTS: SLOTS,
        speakable: speakable,
        detectLanguage: detectLanguage,
        slotFor: slotFor,
        SentenceSplitter: SentenceSplitter,
        genderOf: genderOf,
        pickVoice: pickVoice
    };
});
