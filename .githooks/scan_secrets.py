"""Refuse to commit a credential.

The check runs over staged content rather than the working tree, so what it
clears is exactly what the commit would carry.

Matching on the name alone does not work: a codebase is full of `api_key: str`,
`is_primary_key=True` and `password: field('password')`, none of which are
secrets. So a name only raises suspicion, and the value has to earn the block:

  * in a config file, a secret-shaped name assigned a literal that is not a
    placeholder, an expression, or too short to be a credential;
  * in any file at all, a value matching a known credential format, or a long
    high-entropy literal that has no business being typed by hand.

The second rule is what actually catches a leak, and it is the one worth
trusting. The first is a backstop for the homemade token no format knows.
"""
import math
import re
import subprocess
import sys

# Files where NAME=VALUE means configuration rather than code.
CONFIG_SUFFIXES = (
    ".env", ".env.example", ".env.sample", ".env.template",
    ".ini", ".cfg", ".conf", ".properties", ".toml", ".tfvars",
)

SKIP_SUFFIXES = (
    ".png", ".jpg", ".jpeg", ".gif", ".ico", ".svg", ".webp", ".pdf",
    ".lock", ".sqlite", ".db", ".zip", ".gz", ".woff", ".woff2", ".ttf",
    ".min.js", ".min.css", ".map",
)

SECRET_NAME = re.compile(
    r'(KEY|TOKEN|SECRET|PASSWORD|PASSWD|PWD|DSN|CREDENTIALS?|AUTH)$', re.IGNORECASE)

# A name that only ever describes structure, never a credential.
NOT_A_SECRET_NAME = re.compile(
    r'(PRIMARY_?KEY|FOREIGN_?KEY|SORT_?KEY|ORDER_?KEY|GROUP_?KEY|ROW_?KEY'
    r'|CACHE_?KEY|IDEMPOTENCY_?KEY|PARTITION_?KEY|LICENSE_?KEY|KEYS?|_?AUTH)$',
    re.IGNORECASE)

CONFIG_ASSIGN = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$')

# Credential formats specific enough to block on sight, anywhere in any file.
KNOWN_FORMATS = [
    (re.compile(r'base64:[A-Za-z0-9+/]{40,}={0,2}'), "a Laravel base64: application key"),
    (re.compile(r'sk-ant-[A-Za-z0-9_-]{24,}'), "an Anthropic API key"),
    (re.compile(r'sk-[A-Za-z0-9]{32,}'), "an OpenAI-style API key"),
    (re.compile(r'gh[pousr]_[A-Za-z0-9]{36,}'), "a GitHub token"),
    (re.compile(r'github_pat_[A-Za-z0-9_]{60,}'), "a GitHub fine-grained token"),
    (re.compile(r'AKIA[0-9A-Z]{16}'), "an AWS access key id"),
    (re.compile(r'AIza[0-9A-Za-z_-]{35}'), "a Google API key"),
    (re.compile(r'xox[baprs]-[A-Za-z0-9-]{12,}'), "a Slack token"),
    (re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'), "a private key"),
    (re.compile(r'eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.'), "a signed JWT"),
    (re.compile(r'(?i)://[^/\s:@]+:([^/\s:@]{6,})@'), "a password inside a URL"),
]

# A quoted literal assigned to a secret-shaped name, for Rule B's entropy test.
QUOTED_ASSIGN = re.compile(
    r'([A-Za-z_][A-Za-z0-9_]*)\s*[:=]\s*[\'"]([^\'"\n]{16,})[\'"]')

PLACEHOLDER = re.compile(
    r'^(|change[-_ ]?me.*|changeme.*|your[-_ ].*|your[a-z]*|<.*>|\{\{.*\}\}'
    r'|\$\{?[A-Za-z_][A-Za-z0-9_]*\}?|%[A-Za-z_]+%|none|null|nil|true|false'
    r'|.*example.*|.*placeholder.*|.*dummy.*|.*sample.*|.*redacted.*|.*removed.*'
    r'|x{3,}|\.{3,}|-{3,}|todo.*|tbd.*|fixme.*|secret|password|passwd|token|key'
    r'|test.*|foo|bar|baz|abc123.*|hunter2'
    # Well-known defaults: guessable from any image's docs, so never private.
    r'|postgres|postgresql|mysql|redis|root|admin|user|guest|localhost'
    # Code, not a value.
    r'|str|int|bool|dict|list|self|env|true|false'
    r')$', re.IGNORECASE)

# A value that is plainly an expression rather than a literal.
EXPRESSION = re.compile(
    r'^[\'"]?\s*(?:os\.|env|getenv|config|secrets?\.|process\.|Column|field|new |'
    r'request|input|\$|@|\{|\[|\()|.*\)\s*[,;]?\s*$|.*\b(?:and|or|if|else)\b.*')


def shannon(s):
    if not s:
        return 0.0
    return -sum((n / len(s)) * math.log2(n / len(s))
                for n in (s.count(c) for c in set(s)))


def looks_random(value):
    """A hand-typed word is not this dense; a generated credential is."""
    if len(value) < 20:
        return False
    if re.fullmatch(r'[a-z0-9]+([._/-][a-z0-9]+)+', value, re.IGNORECASE):
        return False   # dotted/dashed words: a path, a hostname, an identifier
    if not re.search(r'\d', value) or not re.search(r'[A-Za-z]', value):
        return False
    return shannon(value) >= 3.6


def run(args):
    """Capture git output as bytes and decode it as UTF-8.

    Not as text=True: that decodes with the machine's locale codec, which on a
    non-UTF-8 console raises on the first accented byte and takes the whole
    hook down with it.
    """
    r = subprocess.run(args, capture_output=True)
    return None if r.returncode != 0 else r.stdout.decode("utf-8", "replace")


def staged_files():
    out = run(["git", "diff", "--cached", "--name-only", "--diff-filter=ACM"])
    return [f for f in (out or "").splitlines() if f.strip()]


def scan_line(path, line, is_config):
    if len(line) > 4000:
        return None

    for pattern, what in KNOWN_FORMATS:
        m = pattern.search(line)
        if m:
            found = m.group(m.lastindex or 0)
            if not PLACEHOLDER.match(found.strip()):
                return what

    m = QUOTED_ASSIGN.search(line)
    if m and SECRET_NAME.search(m.group(1)) and not NOT_A_SECRET_NAME.search(m.group(1)):
        value = m.group(2).strip()
        if not PLACEHOLDER.match(value) and looks_random(value):
            return "%s is set to a high-entropy literal" % m.group(1)

    if is_config:
        m = CONFIG_ASSIGN.match(line)
        if m and SECRET_NAME.search(m.group(1)) and not NOT_A_SECRET_NAME.search(m.group(1)):
            value = m.group(2).strip().strip('"\'')
            if (len(value) >= 8 and not PLACEHOLDER.match(value)
                    and not EXPRESSION.match(value)):
                return "%s is set to a real-looking value" % m.group(1)
    return None


def main():
    problems = []
    for path in staged_files():
        base = path.rsplit("/", 1)[-1]

        # An .env is never committed; its .example twin always may be.
        if base == ".env" or (base.endswith(".env") and "." in base[:-4]):
            problems.append((path, 0, "a .env file itself is staged"))
            continue
        if path.lower().endswith(SKIP_SUFFIXES):
            continue
        # This file quotes credential shapes in order to recognise them.
        if path.endswith(".githooks/scan_secrets.py"):
            continue

        is_config = base.startswith(".env") or path.lower().endswith(CONFIG_SUFFIXES)
        for n, line in enumerate((run(["git", "show", ":" + path]) or "").splitlines(), 1):
            why = scan_line(path, line, is_config)
            if why:
                problems.append((path, n, why))

    if problems:
        sys.stderr.write("\nCommit blocked: staged changes look like they carry a secret.\n\n")
        for path, n, why in problems[:40]:
            sys.stderr.write("  %-52s %s\n" % ("%s:%d" % (path, n) if n else path, why))
        if len(problems) > 40:
            sys.stderr.write("  ...and %d more\n" % (len(problems) - 40))
        sys.stderr.write(
            "\nLeave the value blank in committed files and keep the real one in .env.\n"
            "If this really is a placeholder, add it to PLACEHOLDER in\n"
            ".githooks/scan_secrets.py rather than reaching for --no-verify.\n\n")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
