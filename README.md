# Chatbot Management Hub 🤖 `v1.0.0`

A production-ready, multi-tenant AI Chatbot Management platform featuring a **Laravel 13 Admin Portal**, a **Python FastAPI Streaming Engine**, and a **Zero-Dependency Shadow DOM JS Widget**.

Integrate customizable AI chatbots into **any website, CRM, or application** (PHP, WordPress, React/Next.js, Vue, or static HTML) using a **single-line `<script>` embed tag**.

---

## 🏗️ System Architecture

![System architecture](docs/architecture.png)

Two services over one database. Laravel owns every table's schema and all the
human-facing screens. The engine owns chat streaming, chunking, embedding and
retrieval. They share no code, only the table contract.

Laravel calls the engine over HTTP for four things only: index a source, delete
a source's chunks, list or test embedding models, and run a retrieval preview
for the playground. Those routes are guarded by a shared secret that lives only
in gitignored `.env` files.

- **Editable source:** [`docs/architecture.excalidraw`](docs/architecture.excalidraw) — open it at [excalidraw.com](https://excalidraw.com)
- **Written notes:** [`docs/architecture.md`](docs/architecture.md) — the reasoning behind each decision

### The short version of the retrieval design

**Chunking.** Structure decides where a chunk ends; size is only a ceiling. A
new heading always starts a new chunk. Tables and fenced code are never split
when they fit, and an oversized table repeats its header row on every part.
Overlap applies only inside one long passage, never across a section boundary,
and snaps forward so a chunk can never open on a word fragment.

**Header lines.** Every chunk carries the document title, its heading path, and
the description an operator wrote. Both lines are embedded and keyword-indexed,
which is what makes a bare warranty table findable by the word "warranty". This
is a deterministic substitute for contextual retrieval, which would otherwise
need one LLM call per chunk.

**Retrieval.** Two branches merged by rank, not by score. Dense catches
paraphrase, keyword catches exact terms like product codes. Reciprocal Rank
Fusion needs no calibration between them because it compares positions rather
than values.

**The gate.** A fixed word list, not a model call, decides whether a message
could be a question at all. A question mark always overrides it, so "thanks, and
shipping?" still searches. Saying hello costs nothing.

**Rejected on evidence.** Semantic chunking benchmarks worse than plain
recursive splitting, at roughly fourteen times the indexing cost. Cross-encoder
reranking needs PyTorch for a gain that fusion largely captures at this corpus
size.

---

## 🌟 Key Features in `v1.0.0`

### 1. 🏢 Multi-Tenant Workspace & RBAC Hierarchy
- **1 System = Many Bot Profiles:** Organize chatbots by client, domain, or team.
- **Granular RBAC:** Four distinct permission tiers:
  - **Super Admin:** Global control over all platform users, workspaces, and bots.
  - **System Admin:** Workspace-level administrator with full user and bot control.
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

### 4. 💬 Dynamic Rotating "Thinking..." Indicator
- While waiting for LLM tokens to stream, the widget displays an animated bubble that shifts status messages every few seconds (*"Thinking..."* ➔ *"Analyzing..."* ➔ *"Drafting response..."* ➔ *"Almost ready..."*), reassuring users during model inference.

### 5. 📦 1-Line Embeddable Widget (`widget.js`)
- **Zero NPM Dependencies:** Pure vanilla JS (~14KB).
- **Shadow DOM Isolation:** Host page CSS (Bootstrap, Tailwind, WordPress) cannot interfere with the chat widget styling, and widget styles cannot leak into the host.
- **Real-Time SSE Streaming:** Low-latency typewriter token streaming.

### 6. 📚 Knowledge Base with Retrieval Augmented Generation
- **Three source types:** pasted text, uploaded documents (PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML), and question-answer pairs kept whole.
- **Structure-aware chunking:** headings, tables and code blocks decide where a passage ends. Size is a ceiling, not a target.
- **Heading breadcrumbs on every passage**, so a bare table still says which section and which document it came from.
- **Hybrid retrieval:** vector search and keyword search merged by Reciprocal Rank Fusion, so paraphrase and exact terms both land.
- **A gate before the search.** A greeting never triggers retrieval, and never costs an embedding call.
- **Retrieval playground:** ask what a bot would ask and see the exact passages that come back, with scores.
- **Source detail:** read, correct, download or re-index any source, and see every passage it produced.

### 7. 📱 Responsive UI & Clean Action Layouts
- Styled with modern **Plus Jakarta Sans** typography, sleek cards, unified toolbars, and dynamic-width responsiveness across laptops, desktops, and mobile devices.

---

## 🚀 How to Run on Another Device (After `git pull`)

Follow these instructions to clone and run the platform on any other computer (Windows, macOS, or Linux).

### 📋 Prerequisites
Ensure the target machine has:
1. **PHP 8.2+** with `pdo`, `pdo_sqlite` (or `pdo_pgsql`), `curl`, `mbstring` extensions enabled.
2. **Composer** ([getcomposer.org](https://getcomposer.org/)).
3. **Python 3.10+** ([python.org](https://www.python.org/)).
4. (Optional) **Ollama** installed locally ([ollama.ai](https://ollama.ai/)) if testing local LLMs.
5. (Optional) **PostgreSQL 14+** if using PostgreSQL instead of SQLite.

---

### Step 1: Clone the Repository
```bash
git clone https://github.com/sufi96/chatbot-management.git
cd chatbot-management
```

---

### Step 2: One-Click Startup (Windows)
If you are on Windows, simply double-click:
```cmd
start-dev.bat
```
or execute the PowerShell starter:
```powershell
.\start-dev.ps1
```
This automated script will:
- Create the Python virtual environment (`api-engine/.venv`) and install dependencies.
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
- **Version:** `1.0.0`
- **License:** MIT
