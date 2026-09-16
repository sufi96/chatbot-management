# Admin Settings Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split Admin settings into sidebar categories and replace raw model endpoints with platform providers plus a search-to-pick model picker.

**Architecture:** Platform providers are `ai_providers` rows with a null `system_id`. Settings store `*_provider_id` links; the engine's `get_settings` expands them back into the `*_base_url`/`*_api_key` keys its callers already read, so `roles.py` and `kb/embedding.py` stay unchanged. The portal renders one form whose sections are separate URLs listed in the main sidebar under "Admin Settings".

**Tech Stack:** Laravel 13 (Blade, Bootstrap 5 bundle, vanilla JS), FastAPI + SQLAlchemy async, PHPUnit, pytest.

**Spec:** `docs/superpowers/specs/2026-09-16-admin-settings-revamp-design.md`

## Global Constraints

- Section keys and order: `providers`, `models`, `chunking`, `web-search`, `branding`, `maintenance`.
- Sidebar group label text: `Admin Settings`.
- Setting keys: `embedding_provider_id`, `{role}_model_provider_id` for roles `intent, sql, rerank, guard, vision`.
- Platform provider ids: `aip_` + 12 random chars (same as workspace providers).
- Blank embedding provider means `http://localhost:11434/v1` with no key.
- Bot form (`resources/views/bots/form.blade.php`) is not modified.
- Laravel tests: `php artisan test` in `admin-laravel`. Engine tests: `.venv/Scripts/python -m pytest` in `api-engine`.

---

### Task 1: Engine expands provider links

**Files:**
- Modify: `api-engine/database.py` (AiProvider.system_id nullable; SETTING_DEFAULTS; get_settings)
- Test: `api-engine/tests/test_settings_providers.py`

**Interfaces:**
- Produces: `get_settings(session)` returns a dict where, for every key `X_provider_id` with a value, `X_base_url` and `X_api_key` hold the provider's endpoint (or `""` when the provider is gone).

- [ ] **Step 1: Write the failing tests**

```python
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
import pytest
from database import AiProvider, AppSetting, Base, get_settings

@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    async with async_sessionmaker(engine, expire_on_commit=False)() as s:
        yield s
    await engine.dispose()

async def test_a_linked_role_reads_its_endpoint_from_the_provider(session):
    session.add(AiProvider(id="aip_spark", system_id=None, name="Spark",
                           base_url="http://spark:8000/v1", api_key="k"))
    session.add(AppSetting(key="intent_model_provider_id", value="aip_spark"))
    await session.commit()
    s = await get_settings(session)
    assert (s["intent_model_base_url"], s["intent_model_api_key"]) == ("http://spark:8000/v1", "k")

async def test_a_link_to_a_deleted_provider_is_unavailable_not_defaulted(session): ...
async def test_a_blank_embedding_link_keeps_the_local_default(session): ...
```

- [ ] **Step 2: Run** `pytest tests/test_settings_providers.py -v` — expect FAIL (keys not expanded).
- [ ] **Step 3: Implement** in `get_settings`: collect `*_provider_id` keys with values, load those providers in one query, set `prefix + "base_url"` / `prefix + "api_key"`. Add `embedding_provider_id` and `{role}_model_provider_id` defaults of `""`. Make `AiProvider.system_id` `nullable=True`.
- [ ] **Step 4: Run** the new file and `tests/test_roles.py tests/test_kb_models.py tests/test_ai_provider.py` — expect PASS.
- [ ] **Step 5: Commit** `feat(engine): resolve settings provider links`.

### Task 2: Migration to platform providers

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_16_000001_move_setting_endpoints_to_platform_providers.php`
- Modify: `admin-laravel/app/Models/AiProvider.php` (add `scopePlatform`, `scopeWorkspace`), `admin-laravel/app/Models/AppSetting.php` (DEFAULTS)
- Test: `admin-laravel/tests/Feature/PlatformProviderMigrationTest.php`

**Interfaces:**
- Produces: `AiProvider::platform()` query scope (`whereNull('system_id')`); `AppSetting::DEFAULTS` keys `embedding_provider_id`, `{role}_model_provider_id` = `''`, old URL/key keys removed.

- [ ] **Step 1: Write failing tests** — insert old rows (`embedding_base_url`, `sql_model_base_url` same URL+key, `rerank_model_base_url` different), run the migration's `up()` via `(require path)->up()`, assert two platform providers, links set, old rows gone; then `down()` restores URL rows and removes platform providers.
- [ ] **Step 2: Run** `php artisan test --filter=PlatformProviderMigrationTest` — expect FAIL.
- [ ] **Step 3: Implement** migration: `$table->string('system_id', 36)->nullable()->change()`; convert with fingerprint `url|key`; name from host (`Local Ollama` for localhost/127.0.0.1 on 11434 or no port, else `host:port`); delete old rows; `Cache::forget("app_setting:{$key}")` for every touched key. `down()` reverses.
- [ ] **Step 4: Run** tests — expect PASS.
- [ ] **Step 5: Commit** `feat(admin): move setting endpoints to platform providers`.

### Task 3: Platform provider controller

**Files:**
- Create: `admin-laravel/app/Http/Controllers/AdminProviderController.php`
- Modify: `admin-laravel/routes/web.php` (super_admin group), `admin-laravel/app/Http/Controllers/AiProviderController.php` (scope `findOrFail` to workspace rows)
- Test: `admin-laravel/tests/Feature/AdminProviderControllerTest.php`

**Interfaces:**
- Consumes: `AdminSettingsController::providerLinks(): array<string settingKey, string label>`, `AdminSettingsController::usageOf(string $providerId): array<string label>` (Task 4 defines; implement those two static methods first in this task).
- Produces: routes `admin.providers.store` (POST `/admin/providers`), `admin.providers.update` (PUT `/admin/providers/{id}`), `admin.providers.destroy` (DELETE). JSON `{success, message, provider: {id, name, base_url, api_key, label, used_by: string[]}}`; 409 on in-use delete.

- [ ] **Step 1: Write failing tests:** super admin creates (system_id null); system admin 403; update; delete refused with "Still used by Embedding and SQL" when linked; delete succeeds when unused; workspace `providers.update` on a platform id returns 404.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** mirroring `AiProviderController` (validation messages identical), `AiProvider::platform()->findOrFail($id)`.
- [ ] **Step 4: Run** — expect PASS.
- [ ] **Step 5: Commit** `feat(admin): manage platform providers`.

### Task 4: Settings controller: sections, links, model listing

**Files:**
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`, `admin-laravel/routes/web.php`, `admin-laravel/app/Services/EngineClient.php` (rename `listEmbeddingModels` → `listModels`)
- Test: update `AdminSettingsTest`, `ModelRoleSettingsTest`, `SqlModelSettingsTest`, `EmbeddingModelListTest`, `BrandingSettingsTest`; add `AdminSettingsSectionsTest`

**Interfaces:**
- Produces:
  - `AdminSettingsController::SECTIONS` = `[key => ['label' => ..., 'icon' => 'bi-...']]`
  - `AdminSettingsController::sectionsWithErrors(\Illuminate\Support\ViewErrorBag $errors): string[]`
  - Route `admin.settings` = GET `/admin/settings/{section?}` (404 for unknown section)
  - `update` validates with `Validator::make`; on failure redirects to `route('admin.settings', firstErroredSection)`; on success to the posted `section`.
  - `models`: accepts `provider_id` (exists, platform) or `base_url` (+`api_key`); returns engine JSON.
  - `test`: accepts `provider_id` (nullable → default localhost) + `embedding_model`.

- [ ] **Step 1: Write failing tests:** each section URL 200; unknown section 404; saving a role with provider but no model → error and redirect to `/admin/settings/models`; saving with a workspace provider id → error; saving from `chunking` redirects back to `chunking`; `models` with `provider_id` sends that provider's URL to the engine (`Http::assertSent`).
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** full `php artisan test` — expect PASS apart from view assertions handled in Task 5.
- [ ] **Step 5: Commit** `feat(admin): settings sections and provider links`.

### Task 5: Views: sidebar group, sections, picker, modal

**Files:**
- Modify: `admin-laravel/resources/views/layouts/app.blade.php`, `admin-laravel/resources/views/admin/settings.blade.php`, `admin-laravel/public/css/console.css`
- Create: `admin-laravel/resources/views/admin/_model-picker.blade.php`, `admin-laravel/resources/views/admin/_provider-modal.blade.php`, `admin-laravel/public/js/admin-settings.js`
- Delete: `admin-laravel/resources/views/admin/_model-role.blade.php`
- Test: `AdminSettingsSectionsTest` (sidebar shows "Admin Settings" and every section label for super admin, not for system admin; error dot class `sidebar-error-dot` on errored section)

**Interfaces:**
- Consumes: `SECTIONS`, `sectionsWithErrors`, view vars `section`, `providers` (JSON list), `settings`, `modelRoles`.
- Picker partial params: `providerField`, `modelField`, `label`, `job`, `blank`, `placeholder`, `required` (bool).
- JS (`admin-settings.js`) reads `window.AdminSettings = {routes: {models, store, base}, providers: [...]}` and binds `[data-picker]` elements.

- [ ] **Step 1: Write failing view tests.**
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** views and JS. Model dropdown built with `textContent` (no HTML injection of model names). Search button shows a spinner while fetching; success status "Connected · N models"; failure status shows the engine message.
- [ ] **Step 4: Run** full suite — expect PASS.
- [ ] **Step 5: Browser check** with the webapp-testing skill: screenshot each section and the open model dropdown (fake models via the Ollama on the dev machine, or a stubbed endpoint).
- [ ] **Step 6: Commit** `feat(admin): categorised settings UI with model picker`.
