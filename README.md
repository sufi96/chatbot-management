# ChitChat Command Center (C⁴) 🤖 `v1.2.0`

A production-ready, multi-tenant AI Chatbot Management platform featuring a **Laravel 13 Admin Portal**, a **Python FastAPI Streaming Engine**, and a **Zero-Dependency Shadow DOM JS Widget**.

Integrate customizable AI chatbots into **any website, CRM, or application** (PHP, WordPress, React/Next.js, Vue, or static HTML) using a **single-line `<script>` embed tag**.

---

## 🏗️ System Architecture

**System** — the three processes, the shared database, and what talks to what.

![System architecture](docs/architecture.png)

**Answering** — one message from arrival to a recorded answer. The knowledge base and database answer together by default; a bot can use a fixed source order instead.

![How a message is answered: combined sources by default, or a fixed source order](docs/architecture-answering.png)

Two services over one database. Laravel owns every table's schema and all the
human-facing screens. The engine owns chat streaming, chunking, embedding and
retrieval. They share no code, only the table contract.

Laravel calls the engine over HTTP for admin work only: index a source, delete
a source's chunks, list or test models, run a retrieval preview for the
playground, test a web search key, and play a voice sample. Those routes are
guarded by a shared secret that lives only in gitignored `.env` files. The
widget calls the engine for chat, its configuration and, when voice is on,
speech; those routes hold to the workspace's allowed sites.

- **In the portal:** the info button in the top bar opens the live version of this diagram, with tabs for answering, indexing, techniques, models and the DGX Sparks plan. The images above are its System and Answering tabs. The **Techniques** tab lists every retrieval and security technique with its status: built, a choice, needs its own model, or waiting for the DGX Sparks.
- **Earlier detailed diagram:** [`docs/architecture.excalidraw`](docs/architecture.excalidraw) — open it at [excalidraw.com](https://excalidraw.com)
- **Written notes:** [`docs/architecture.md`](docs/architecture.md) — the reasoning behind each decision

### The short version of the retrieval design

**Chunking.** Structure decides where a chunk ends; size is only a ceiling. A
new heading always starts a new chunk. Tables and fenced code are never split
when they fit, and an oversized table repeats its header row on every part.
Overlap applies only inside one long passage, never across a section boundary,
and snaps forward so a chunk can never open on a word fragment.

**Header lines.** Every chunk carries the document title, its heading path, and
the description an operator wrote. Both lines are embedded and keyword-indexed,
which is what makes a bare warranty table findable by the word "warranty".
Contextual retrieval, a model-written sentence per chunk, is available on top
as an install-wide choice; it costs one model call per chunk at indexing time.

**Retrieval.** Hybrid: dense vectors catch paraphrase, BM25 catches exact terms
like product codes, in Malay and English alike. The branches are merged by
weighted rank (Reciprocal Rank Fusion), never by score, so no calibration is
needed between them. A bot can also search rephrasings of the question
(multi-query) or a hypothetical answer (HyDE), have a cross-encoder rerank the
result, hand the model each hit's neighbouring passages, and reuse answers to
questions it has already answered (semantic cache).

**Security.** Layers that need no model run on every bot: the conversation is
read from the engine's own records, never the browser's copy; messages worded
to override the bot are refused by pattern; retrieved passages written as
instructions are dropped, and all material is marked as data; a secret marker
in each prompt stops a reply that starts reciting its instructions. The guard
model and a grounding check, which flags claims the sources do not support, add
judgement on top.

**Sources.** Either combined or asked in the order the operator set, where the
first with something answers. No model routes between them, because an operator can predict and
explain an order. By default a bot combines the knowledge base and the
database instead: both are asked at once and both answer, for the question that
needs a policy and a record. The web stays the last resort either way.

**The gate.** A fixed word list, not a model call, decides whether a message
could be a question at all. A question mark always overrides it, so "thanks, and
shipping?" still searches. Saying hello costs nothing.

**Competing techniques are choices.** Where two techniques compete (BM25 or
Postgres full text, HyDE or multi-query, cache or not), the operator picks, and
every choice starts off or neutral so an existing bot answers as before. The
Techniques tab of the in-app architecture overview lists each one with its
status.

**Rejected on evidence.** Semantic chunking benchmarks worse than plain
recursive splitting, at roughly fourteen times the indexing cost. GraphRAG is
not built: support material is mostly policy and FAQ, and the database source
already answers relational questions. Reranking runs over HTTP (the `rerank`
role), so the engine carries no PyTorch.

---

## 🆕 What's New in `v1.2.0`

Run `php artisan migrate` after pulling, and let `start-dev` reinstall the
engine's requirements (it now needs `python-multipart`). Everything below that
changes how a bot answers starts switched off; the security layers start on.

### 🧭 Admin settings and the Behaviour tab
- **Admin settings is two sidebar entries, with tabs inside.** **AI and answering** holds Providers, Models, Guard, Security, Chunking and search, Voice and Web search; **Console** holds Branding and Maintenance. **Bots** sits between them. Each tab keeps its own address, and a red dot marks the tab and the entry a save refused.
- **Providers say what they serve.** Each platform provider ticks the jobs it serves: Language model, Embedding, Reranker, Text to speech, Speech to text (several for one endpoint, such as OpenAI). Every picker lists only the providers serving its job, so a speech server is never offered as a bot's model, and a fetched model list shows that job's models (OpenAI's chat picker leaves out `tts-1`, `whisper-1` and `text-embedding-3-small`). A purpose a job still uses cannot be unticked.
- **The Behaviour tab folds.** Every card on the left has an icon and folds to its header; a first visit opens the prompt and generation only, and after that each card stays as you left it. A card with a field that failed to save always opens. **Safety** is now last, below Voice.

### 🔒 Security (Admin settings → AI and answering → Security)
- **Conversation history from the engine's records.** The widget's copy of the conversation is no longer trusted: a visitor could send a fake `system` turn, or a fake reply in which the bot agreed to drop its rules. The engine reads the session's last ten turns itself. *Widget copy* is still a choice, cleaned to visitor and assistant turns only. Clearing the widget now starts a new session.
- **Injection shield.** "Ignore your previous instructions", requests for the hidden prompt, fake role tags and invisible Unicode characters are caught by pattern, in English and Malay, with no model. Block, flag only, or off.
- **Retrieved material is screened and marked.** A knowledge-base passage or web result written as instructions is dropped. Every source's material is wrapped and marked as reference, never instructions.
- **Prompt leak guard.** Each prompt carries a secret marker. A reply that starts reciting its instructions is stopped before the marker is shown, replaced with the refusal, and flagged; a long word-for-word copy of the prompt is flagged too.
- **Messages are capped at 4,000 characters.**
- Flags appear as *Prompt injection* and *Prompt leak* in conversations and in the analytics flag chart.

### 🔎 Retrieval (Behaviour → Knowledge base, and Admin settings → AI and answering → Chunking and search)
- **BM25 keyword ranking** inside the engine, the same on Postgres and SQLite, reading Malay and English alike. Postgres full text remains a choice, and now matches any of a question's words rather than all of them, which used to leave hybrid search running on vectors alone.
- **Keyword weight:** how much keywords count against meaning in rank fusion.
- **Query expansion:** multi-query, HyDE, or both, from one model call.
- **Neighbouring passages:** each hit with up to two passages either side, from the same section.
- **Contextual chunks:** a model-written sentence placing each chunk in its document. Install-wide, off by default, needs a re-index.
- **Answer cache:** a question worded close enough to one already answered from the knowledge base gets that answer at once. Never for database or web answers; emptied when the bot's behaviour is saved or its collections are re-indexed.
- **Grounding check:** a sourced answer is checked against its material after it is sent, and an unsupported claim is flagged.
- The retrieval playground takes keyword weight and neighbouring passages too.

### 🔊 Voice (Behaviour → Voice, and Admin settings → AI and answering → Voice)
- **Bots read answers aloud** (a speaker on every answer, or every answer as it arrives) and **take spoken questions** (a microphone by the message box).
- **Visitors decide:** a *Read answers aloud* on/off switch, right-aligned above the message box and shown only on bots with voice switched on. The bot's setting is only where a first-time visitor starts; the widget remembers each visitor's choice.
- **Four voices:** English or Malay, female or male. Visitors switch in the widget's voice menu; the bot sets where they start, or follows each answer's language.
- **Choose each voice** under Admin settings → Voice, grouped by language with a female and a male row: American, British, Singaporean, Australian and Indian English voices, and Malaysian Malay (Yasmin, Osman). A speech server that lists its voices, such as the Malaysian TTS server, adds them to each list; *Another name* takes any other server's voice name.
- **Hear before you save.** Type any text and press play, on the Voice settings page (with the engine as the page shows it, saved or not) and on a bot's Behaviour tab (in its starting voice). A speech server that is not running, or is not a speech server at all, is named as such.
- **Nothing to install by default:** the visitor's browser speaks and listens. For the same four voices on every device, use **Azure Speech** or a speech server (see [Setting up voice](#-setting-up-voice)), and a Whisper server for listening.
- Answers are spoken a sentence at a time while they stream; Markdown, citations, links and code are never read out.

### 🔑 Web search keys per workspace (Behaviour → Web search)
- **Each bot chooses its search:** the platform's (as before), DuckDuckGo with no key, or one of its workspace's own Tavily or Brave keys, billed to the workspace.
- **A workspace's system admins manage its keys** in a modal on the Behaviour tab, with a Test that runs one real search. Keys are never shown again once saved, and a key in use cannot be deleted.
- **Admin settings → AI and answering → Web search** can stop lending the platform's search to workspaces; their bots then use DuckDuckGo unless they bring a key.

### 🤖 Three new model roles (Admin settings → AI and answering → Models)
- **Query expansion**, **Answer check** and **Chunk context**. Left blank, each borrows the bot's own model, so one chat model and one embedding model still run everything. Only the reranker needs a model of its own.

### 📊 Analytics and architecture
- The Guard card counts answers served from the cache and answers with an unsupported claim.
- The architecture overview has a **Techniques** tab: every retrieval and security technique, whether it is built, a choice, needs its own model, or waits for the DGX Sparks. The Indexing tab's flow fits all seven steps on one row, the optional one dashed.

---

## 🆕 What's New in `v1.0.1`

### 📊 Analytics
- **Its own sidebar link** under Administer. Bot profiles stay about settings and embed codes.
- **Pick bots across workspaces:** a dropdown at the top right groups bots by workspace, one at a time or a whole workspace at once. Everyone sees the workspaces they belong to; a Super Admin sees all.
- **Any window:** 24 hours, 7, 30 or 90 days, or custom dates, read in the viewer's own time zone.
- **Headline figures with the change from the window before,** green when it is good news and red when it is not: more flags is red, faster replies is green.
- **Activity by hour or day, and a weekday × hour heatmap** of when visitors write.
- **Visitors and questions:** where they come from, new or returning, how messages were read, and the most asked questions.
- **Answers:** which source answered (knowledge base, database, web, nothing found, model only, refused), response times, the documents cited and the ready ones never cited, sites cited, and the model behind each job.
- **Gaps and safety:** questions the sources had nothing for, and guard flags by category.
- **By bot profile,** and the full conversations table for the bots and window chosen.
- **Two tabs: Bot analytics and Knowledge base.** The bot picker and window apply to both; only the open tab is built.

### 📚 Knowledge Base Analytics
- **Headline figures:** collections, documents, chunks and how many are embedded, indexed text, average chunk size, hits in the window, hit rate, document coverage and citations per answer. A hit is one chunk cited in one answer.
- **Usage:** hits over time, the most hit documents, and the ready ones never hit.
- **Semantic map:** a sample of up to 600 chunks laid out by meaning (the two leading principal components of their embeddings), coloured by collection, with hit documents drawn solid. Beside it, how much each pair of collections overlaps.
- **Collections table:** linked bots, documents with failed and pending counts, chunks, embedded share, average chunk, text size, hits and last indexed.
- **Chunking and embedding:** the chunk size distribution, heading path coverage, the chunking settings, and chunks per embedding model, with a warning for chunks a re-index is owed.
- **Documents and storage:** documents by type and status, the ones that need attention with their error, and the vector database: driver, vector type, search indexes, rows and size.

### 💬 Conversations
- **Token column:** total, prompt tokens in and reply tokens out, sortable.
- **Every bubble shows its time** and the gap since the one before, with long pauses marked and a divider when the day changes. A bot's reply also shows its time to first token and to the whole reply.
- **Tidier transcript:** the session id sits at the top right, and the model behind each job moves to the footer.

### 🗑️ Deleted Bots Keep Their History
- A workspace is told a deleted bot is gone for good, and for its members it is: the bot, its conversations and its analytics disappear from every page, the dashboard included.
- A Super Admin still sees all three, marked **Deleted**, can open them from the admin **Bots** page, and can restore the bot. Only **Delete permanently** there erases anything.

### 🏠 Dashboard and Help
- **The dashboard is a workspace overview:** bots, conversations in the last seven days, knowledge sources, database connections and members. Each bot has one **Actions** menu for its embed code, settings and analytics.
- **Architecture for everyone:** the info button in the top bar opens the architecture overview on any page. The live model settings in it stay with Super Admins.

### 🤖 Console Assistant
- **A built-in bot for this console,** first on the admin **Bots** page in a blue card. It cannot be deleted, and it is edited like any other bot: provider, model, widget, prompt.
- **Its chat widget shows on every console page, for super admins only,** once it has a provider and is switched on.
- **It reads the console's own database,** read-only: workspaces, members, bots, conversations and knowledge. Tables holding passwords, provider keys, connection passwords and platform settings stay shut, even if ticked.
- **Analytics and conversations of its own,** under a **Platform** group in the bot picker. "All bot profiles" leaves it out unless it is ticked.
- **The engine answers it only from the console's address.** Set `CONSOLE_ORIGIN` in `api-engine/.env` when the browser reaches the console at a different address from `PORTAL_BASE_URL`, as in Docker.

### ⚙️ Engine
- **Answer sources: Combined or Source order** (Bot → Behaviour). Combined is the default for every bot, existing ones included. The knowledge base and database are asked at the same time and everything they found goes into one numbered context, so a question that needs a document and a live record gets both. The widget marks each source chip with its own kind, and analytics counts these answers as "Knowledge base and database". Each source gets half the context budget, and every question runs a database query. Pick *Source order* for the previous first-hit behaviour. New bots also have *Understand follow-up questions* on; existing bots keep their setting. Run `php artisan migrate` after pulling.
- Every answer records which source answered, what it cited, the time to first token and to the whole reply, and prompt and reply tokens separately. Run `php artisan migrate` after pulling: answers saved before this have no such data, and the pages say so rather than guessing.
- **"Send instructions inside the message"** on a provider, for a gateway that silently drops system messages. Without it a bot on such a gateway answers without its prompt, its knowledge or its database.

---

## 🌟 Key Features in `v1.0.0`

### 1. 🏢 Multi-Tenant Workspace & RBAC Hierarchy
- **1 System = Many Bot Profiles:** Organize chatbots by client, domain, or team.
- **Granular RBAC:** Four distinct permission tiers:
  - **Super Admin:** Global control over all platform users, workspaces, and bots. The only role that can restore or permanently erase a deleted bot.
  - **System Admin:** Workspace-level administrator with full user and bot control. Deleting a bot only marks it, so nothing is lost by mistake.
  - **Editor:** Configure bots, prompts, models, and styling within assigned workspace.
  - **Viewer:** Read-only access to bot configurations and conversation transcripts.
- **CORS Whitelisting:** Define authorized host domains per workspace to restrict widget usage.

### 2. ⚡ Universal LLM Support & Dynamic Model Discovery
- **Local Ollama:** Direct integration with `http://localhost:11434/v1`.
- **Instant Model Fetch Dropdown:** Split dropdown button queries Ollama `/api/tags` and `/v1/models` in milliseconds. No manual typing, no cold-start timeouts.
- **Extended Timeout Resilience:** 60-second client timeout accommodates heavy 27B+ parameter models on local GPUs without HTTP 499 disconnects.
- **Custom OpenAI-Compatible Endpoints:** Connect OpenAI, Groq, OpenRouter, vLLM, or LocalAI with custom `Base URL`, `API Key`, and `Model Name`.

### 3. 🎨 Custom Images & Silhouette Cutout Fitting
- **Dual Custom Uploads:** Upload distinct images for the **Floating Launcher Button** and the **Chat Header Avatar**.
- **Transparent Silhouette Cutout (`transparent_fit`):** Natural drop-shadow on the image contour with no circular clipping or background squares. Perfect for mascots, character PNGs, logos, and vehicles.
- **Circle & Border Options:** Supports standard filled circles or circle outlines with transparent backgrounds.
- **Cutout in a Circle (`cutout_circle`, `cutout_ring`):** The picture sits in a filled circle or a ring with its top rising out of the circle, for the launcher, the close button and the avatar.
- **Header & Chat Background:** The header and the conversation each take a colour and an optional picture, with an opacity slider that lets the colour show through. The header colour follows the widget colour unless given its own. The header's text and icons (title, status, clear, expand, close) take their own colour; at 100% opacity every picture is shown exactly as uploaded.
- **Notice & Footer:** An "AI can make mistakes" notice sits at the bottom of the conversation, just above the message box; the footer reads "Powered by C⁴".

### 4. 💬 Live Progress Indicator
- While a reply is being prepared, the engine streams `status` events for each step it actually takes, and the widget's bubble shows them as they happen: *"Reading your message..."* (shown by the widget the moment the message is sent) ➔ *"Understanding intent..."* ➔ *"Consulting the knowledge base..."* / *"Retrieving records..."* / *"Researching the web..."* (each source in the bot's order, as it is tried) ➔ *"Composing a response..."*.

### 5. 📦 1-Line Embeddable Widget (`widget.js`)
- **Zero NPM Dependencies:** pure vanilla JS, served as one script with its Markdown renderer and voice helpers.
- **Shadow DOM Isolation:** Host page CSS (Bootstrap, Tailwind, WordPress) cannot interfere with the chat widget styling, and widget styles cannot leak into the host.
- **Real-Time SSE Streaming:** Low-latency typewriter token streaming.

### 6. 📚 Knowledge Base with Retrieval Augmented Generation
- **Three source types:** pasted text, uploaded documents (PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML), and question-answer pairs kept whole.
- **Structure-aware chunking:** headings, tables and code blocks decide where a passage ends. Size is a ceiling, not a target.
- **Heading breadcrumbs on every passage**, so a bare table still says which section and which document it came from.
- **Hybrid retrieval:** vector search and BM25 keyword search merged by weighted Reciprocal Rank Fusion, so paraphrase and exact terms both land. Optional query expansion (multi-query, HyDE), neighbouring passages, a reranker, an answer cache and a grounding check build on it; see What's New.
- **A gate before the search.** A greeting never triggers retrieval, and never costs an embedding call.
- **Retrieval playground:** ask what a bot would ask and see the exact passages that come back, with scores.
- **Source detail:** read, correct, download or re-index any source, and see every passage it produced.

### 7. 🗄️ Database Connections and Annotated Schema
- **Four drivers:** MySQL and MariaDB, PostgreSQL, SQL Server, SQLite.
- **Schema discovery:** one button reads every table, column, primary key and foreign key the connected account can see.
- **Plain-language annotation:** an editor writes what each table and column actually holds. A bot writes its queries from those sentences, which is what makes a column named `amt_ttl` usable.
- **An allowlist, not a blocklist:** a table nobody enabled is invisible. Nothing reaches a model by default.
- **A master switch per database:** cut a bot off from a whole connection in one click. It is a gate, not an eraser, so every tick and description survives being switched off.
- **Annotations survive re-discovery.** A table that disappears is marked absent, never deleted, so a permissions blip cannot destroy somebody's work.
- **Manual entry** for accounts that cannot read the information schema, on the same screen and in the same rows as the discovered ones.
- **Read-only by design.** Credentials are stored encrypted and the form asks for a read-only account.
- **Relationships you can write down.** Discovery finds every declared foreign key. Where a database never declared one, an editor writes it in, and re-discovery never erases it. This is what lets a bot follow a question from an order to the customer who placed it.
- **The operator decides, not a model.** Each bot asks its sources in the order you set, and the first with something answers. A source that fails or has nothing passes the question to the next, so no failure ends a conversation.
- **Or both at once, the default.** With *Combined* chosen, a question like "is my order still inside the return window?" is answered from the policy and the order record together. Web search stays the fallback for when both have nothing.
- **Read-only, twice over.** The generated statement is validated in the engine and validated again by the portal before anything runs. Only a single SELECT ever reaches a customer's database.
- **Auditable.** Every database-answered message keeps its statement and row count in the conversation log.
- **A playground for tuning.** Run a statement the way a bot would and see exactly what your annotations bought you.

### 8. 🤖 Bot Settings and Safe Deletion
- **Profile and Behaviour tabs:** a bot's settings are split in two. Profile holds identity, the on/off switch and widget styling; Behaviour holds the prompt, generation (temperature, max tokens, sampling), answer sources, web search, the answer cache, voice and safety on the left, folding to their headers, and the model and endpoint and the Brain (its knowledge base and databases) on the right. A new bot still picks its model on the create form. Each tab saves on its own.
- **A clear On/Off switch:** a large Online/Offline card beside the identity section decides whether the bot answers at all. Green when online, red when offline, with the same state shown next to the bot's name.
- **Save bar that only shows when needed:** the floating save bar stays hidden until something changes, then counts the unsaved changes (*"2 unsaved changes"*) with **Discard** and **Save**. It sits on the left so it never covers the chat launcher.
- **Test inference in the model card header**, with the result shown in the card once there is one.
- **Deleting takes intent:** delete lives at the foot of a bot's settings, in a red section, and asks for the bot's name to be typed before it unlocks. The server checks the name too.
- **Soft delete:** a deleted bot stops answering on every site and leaves its workspace, but the bot and its conversations are kept. From `v1.0.1`, a workspace is told it is deleted permanently, and only a Super Admin still sees it.
- **Admin Bots page (Super Admin):** every bot in every workspace in one list, filtered by workspace, status and a search on name, id or model. Status badges show **Active**, **Deactivated** or **Deleted**. A deleted bot can be **restored**, or **deleted permanently** (type the name again), which also erases its conversations.

### 9. 📱 Responsive UI & Clean Action Layouts
- Styled with modern **Plus Jakarta Sans** typography, sleek cards, unified toolbars, and dynamic-width responsiveness across laptops, desktops, and mobile devices.

---

## 🚀 How to Run on Another Device (After `git pull`)

Follow these instructions to clone and run the platform on any other computer (Windows, macOS, or Linux). [`INSTALLATION.md`](INSTALLATION.md) has the full picture: the stack, how many servers, every prerequisite, and the TTS server.

### 📋 Prerequisites
Ensure the target machine has:
1. **PHP 8.3+** with `pdo`, `pdo_sqlite` (or `pdo_pgsql`), `curl`, `mbstring` extensions enabled.
2. **Composer** ([getcomposer.org](https://getcomposer.org/)).
3. **Python 3.10+** ([python.org](https://www.python.org/)).
4. (Optional) **Ollama** installed locally ([ollama.ai](https://ollama.ai/)) if testing local LLMs.
5. (Optional) **PostgreSQL 14+** if using PostgreSQL instead of SQLite.

---

### Step 1: Clone the Repository
```bash
git clone https://github.com/sufi96/chatbot-management.git
cd chatbot-management

# Turn on the pre-commit secret scanner. Git does not enable a repository's
# own hooks on clone, so this line is needed once per clone.
git config core.hooksPath .githooks
```

Every `.env` in this project is ignored and every `.env.example` ships its
secrets blank. The hook refuses a commit that would change that; if it stops
a value that really is a placeholder, add it to `PLACEHOLDER` in
`.githooks/scan_secrets.py` rather than reaching for `--no-verify`.

---

### Step 2: One-Click Startup

**Windows:** double-click:
```cmd
start-dev.bat
```
or execute the PowerShell starter:
```powershell
.\start-dev.ps1
```

**Linux / macOS:** from the repository root:
```bash
./start-dev.sh
```
The engine's dependencies do not install on Python 3.14 yet. The script uses
Python 3.10–3.13 if one is installed, otherwise [uv](https://docs.astral.sh/uv/)
(`pip install --user uv`) to fetch 3.12. Press **Ctrl+C** to stop both services.

Either script will:
- Create the Python virtual environment (`api-engine/.venv`) and install dependencies.
- Install Composer dependencies, create both `.env` files, generate the shared secrets, and migrate and seed the database.
- Launch the **FastAPI Streaming Engine** on `http://127.0.0.1:8000`.
- Launch the **Laravel 13 Admin Portal** on `http://127.0.0.1:8080`.

---

### Step 3: Manual Startup (Linux / macOS / Windows)

If setting up manually or running on Linux/macOS:

#### 1. Setup Python FastAPI Engine
```bash
cd api-engine

# Create virtual environment
python -m venv .venv

# Activate virtual environment:
# On Linux/macOS:
source .venv/bin/activate
# On Windows:
.venv\Scripts\activate

# Install dependencies
pip install -r requirements.txt

# Start FastAPI server
uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```
*FastAPI Engine will be live at `http://localhost:8000` (API Docs: `http://localhost:8000/docs`).*

#### 2. Setup Laravel 13 Management Portal (In a new terminal)
```bash
cd admin-laravel

# Install Composer dependencies
composer install

# Create environment configuration
cp .env.example .env

# Generate application encryption key
php artisan key:generate

# Run database migrations and seed default users
php artisan migrate --seed

# Start Laravel development server
php artisan serve --host=127.0.0.1 --port=8080
```
*Laravel Admin Portal will be live at `http://localhost:8080`.*

---

## 🔑 Default Test Accounts

The seeder automatically provisions 4 pre-configured accounts with different role privileges:

| Role | Email | Password | Access Scope |
|---|---|---|---|
| **Super Administrator** | `admin@chatbothub.com` | `password` | Universal platform access (all workspaces, users, settings) |
| **System Manager** | `manager@chatbothub.com` | `password` | Full workspace admin for *Default Helpdesk System* |
| **Bot Editor** | `editor@chatbothub.com` | `password` | Can create & edit bot profiles in assigned workspace |
| **Support Viewer** | `viewer@chatbothub.com` | `password` | Read-only access to bot configurations and chat logs |

---

## 🦙 Connecting to Local Ollama

1. Start Ollama on the machine:
   ```bash
   ollama serve
   # or ensure the Ollama desktop app is active on http://localhost:11434
   ```
2. Pull your preferred model (e.g. `llama3.2`, `mistral`, `deepseek-r1:7b`):
   ```bash
   ollama pull llama3.2
   ```
3. In the Management Portal (`http://localhost:8080`):
   - Navigate to **Bot Profiles** ➔ **Create Bot Profile** (or edit existing).
   - In **Provider & Model Configuration**, click the **Preset: Local Ollama** button.
   - Click the **Fetch Models** dropdown button.
   - Select your model from the auto-populated list and click **Test Connection** to confirm live connectivity!

---

## 🔊 Setting up voice

Out of the box the **browser** speaks and listens on each visitor's device, so
there is nothing to install. Its voices vary by device; Malay especially. For
the same four voices on every device, pick one of these under
**Admin settings → AI and answering → Voice → Speaking**:

- **Azure Speech** (recommended for production). Create a *Speech* resource in
  the Azure portal, then enter its region (for example `southeastasia`) and one
  of its keys. Its neural voices include all four defaults, and there is a
  free monthly allowance.
- **Speech server**: any server on the OpenAI audio API
  (`POST /v1/audio/speech`). To hear the Microsoft voices locally for testing:
  ```bash
  docker run -d --name chitchat-tts -p 5050:5050 -e REQUIRE_API_KEY=False travisvn/openai-edge-tts:latest
  ```
  then add a provider with base URL `http://localhost:5050/v1` under
  **Providers**, pick it under Voice, keep model `tts-1`, and press ▶ on a
  voice. This container uses Microsoft's Edge read-aloud service unofficially
  and is licensed for personal use, so use it to try voices, not to run a
  product. On the DGX Sparks, a TTS model of your own takes its place.
- **Malaysian TTS server** (self-hosted, in [`tts-server/`](tts-server/README.md)):
  Mesolitica's VITS voices (Yasmin, Osman and 12 more, on CPU) and Malaysian
  F5-TTS (any voice from a short clip, on a GPU), with a settings page at
  `http://localhost:5051` to add voices and try them:
  ```bash
  docker compose -f tts-server/compose.yaml --profile gpu up -d --build
  ```
  Add `http://localhost:5051/v1` under **Providers** and use `yasmin` and
  `osman` for the Malay voices. The F5 checkpoint is CC-BY-NC, so it is for
  testing only.

For **listening**, the browser's recognition works best in Chrome and Edge; a
Whisper-family server (`POST /v1/audio/transcriptions`) can replace it under
**Listening**.

Then switch voice on per bot under **Behaviour → Voice**, and try it with the
**Hear it** box there. **Speaks with** lets one bot differ from the install:
the visitor's browser, the speech server or Azure, whichever are set up
above, while the install's choice stays the default for every other bot.

---

## 📋 How to Embed into Any Website

Once a bot profile is configured, open the **Embed Code** page (`/bots/{id}/embed`) to grab the universal snippet:

```html
<!-- Place before closing </body> tag -->
<script
  src="http://localhost:8000/widget.js"
  data-bot-id="YOUR_BOT_PROFILE_ID"
  data-api-url="http://localhost:8000"
  defer>
</script>
```

### Framework Integration Recipes
- **Standard HTML / PHP / WordPress:** Paste snippet directly into your template footer or `footer.php`.
- **React / Next.js:** Use `next/script` with `strategy="lazyOnload"`.
- **Vue / Nuxt / Svelte:** Append dynamic script tag inside `onMounted()`.

---

## 🗄️ Database Options

### Default: PostgreSQL with pgvector

The knowledge base needs a vector store, so PostgreSQL 17.5 with pgvector 0.8.0
is the default. Vector search uses an HNSW index with cosine distance, and
keyword search uses a generated `tsvector` column with a GIN index.

### Fallback: SQLite

SQLite still works and needs no setup, so the project runs where PostgreSQL is
absent. Vectors are stored as float32 blobs and scanned in memory, which is fine
into the tens of thousands of passages and slows beyond that. The engine falls
back to it automatically if PostgreSQL is unreachable, prints a banner, and
reports `degraded` on `/health` so the fallback can never pass for healthy.

Switch drivers in Admin Settings, then rebuild the index.

### Setting up PostgreSQL
1. Create the database and enable pgvector:
   ```sql
   CREATE DATABASE chatbot_management;
   \c chatbot_management
   CREATE EXTENSION IF NOT EXISTS vector;
   ```
2. In `admin-laravel/.env`:
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=chatbot_management
   DB_USERNAME=postgres
   DB_PASSWORD=your_password
   ```
3. In `api-engine/.env`:
   ```env
   DATABASE_URL=postgresql+asyncpg://postgres:your_password@127.0.0.1:5432/chatbot_management
   USE_SQLITE_FALLBACK=False
   ```
4. Run migrations:
   ```bash
   cd admin-laravel
   php artisan migrate --seed
   ```

---

## 📄 License & Version
- **Version:** `1.2.0`
- **License:** MIT
