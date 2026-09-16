# Admin settings revamp: categories and platform providers

## Problem

Admin settings is one long two-column page, and it keeps growing. Every model
job (embedding, intent, SQL, reranker, guard, vision) asks for a raw base URL,
API key and model name, so the same endpoint is typed up to six times, a typo
in a model name is only found when the job fails, and nothing proves the
endpoint answers before saving.

The bot form already solves this for chat models: a saved provider is picked
from a list, and the model is picked from what the endpoint publishes.

## Decisions

- **Categories live in the main sidebar**, under a group separator named
  **Admin Settings** (styled like "Operate" and "Administer"), shown to super
  admins only. Each category is its own URL: `/admin/settings/{section}`.
  Sections, in order: Providers, Models, Chunking, Web search, Branding,
  Maintenance. The old single "Admin settings" link goes.
- **Platform providers.** `ai_providers.system_id` becomes nullable; a row with
  no workspace is a platform provider. They are managed in Admin settings and
  never offered to bots (the bot form already filters by workspace). Workspace
  providers are untouched.
- **Settings link to a provider rather than copying it.**
  `embedding_base_url`/`embedding_api_key` become `embedding_provider_id`;
  `{role}_model_base_url`/`{role}_model_api_key` become
  `{role}_model_provider_id`. Model names stay where they are.
- **Model picker.** Each job has a provider select (with a "None" option that
  says what blank means) and a model input with a search button. The button
  lists the provider's models in a filterable dropdown; a failure is shown
  inline, so the button is also the connection test. Typing a name still works.

## Behaviour

### One form, many pages
Every section renders inside one form, but only the current section is
visible. Save posts everything and returns to the section it was pressed on.
If validation fails, the redirect goes to the first section with an error, and
every section with an error gets a red dot in the sidebar. The form is
`novalidate`, because a `required` field on a hidden section would otherwise
block the browser from submitting. Providers and Maintenance have no Save
button: providers save through their modal, and re-index is its own form.

On phones the sidebar hides group labels and wraps its links; the admin
settings sub-links are hidden there (except the active one), and the settings
page shows a section select instead.

### Providers section
A list of platform providers: name, base URL, and which jobs use it. Add and
edit open a modal (Name, Base URL, API key, Test). Test lists models from the
draft values. Deleting a provider still linked from a saved setting is refused
with the jobs named: "Still used by Embedding and SQL. Point them at another
provider first."

### Models section
- **Embedding:** provider, model (picker), dimensions, Test connection (fills
  dimensions), and the re-index warning. Blank provider means the built-in
  default `http://localhost:11434/v1`.
- **Intent, SQL, Reranker, Guard, Vision:** provider and model (picker). Blank
  provider keeps today's rule from `roles.py`. A provider without a model, or
  a model without a provider, is refused.
- The "+" beside each provider select opens the same modal; the new provider
  is selected in that picker on save, and every select on the page gains it.

### Engine
`get_settings` expands each `*_provider_id` into the `*_base_url` and
`*_api_key` it replaced, by reading `ai_providers`. `roles.py` and
`kb/embedding.py` keep reading the expanded keys and do not change. A linked
provider that no longer exists expands to an empty URL (unavailable), never to
a default. A blank embedding link keeps the localhost default.

### Migration
Every stored non-empty URL (+ key) becomes a platform provider, deduplicated by
URL and key and named from the host ("Local Ollama" for localhost:11434).
The old rows are deleted and their cache entries forgotten. Rollback writes
URL and key back from the links and deletes platform providers.

### Model listing endpoint
`POST /admin/settings/models` takes either `provider_id` (a platform provider)
or `base_url` + `api_key` (a draft from the modal). `POST /admin/settings/test`
takes `provider_id` + `model`. Both resolve the endpoint on the server and go
through the engine's admin-token routes.

## Out of scope
The bot form's own Model and endpoint section is not changed.

## Testing
- Laravel: migration (conversion, dedup, rollback); provider CRUD, in-use
  refusal, super admin only, workspace provider controller cannot touch
  platform rows; settings save with provider links and both-or-neither
  validation; model listing by provider and by draft; section routes, error
  redirect, sidebar group.
- Engine: `get_settings` expansion (linked, missing, blank).
- Browser: each section rendered, model search dropdown opened.
