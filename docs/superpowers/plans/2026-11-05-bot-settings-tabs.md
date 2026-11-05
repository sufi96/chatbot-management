# Bot Settings Tabs Implementation Plan

> Executed inline (superpowers:executing-plans), TDD per task.

**Goal:** Split the packed Behaviour page into tabs: **Appearance · Behaviour · Brain · Agents · Files & vision · Safety**. Each tab is its own page with its own Save.

**Decided with the user (2026-11-05):**
- The tab names above, in this order.
- Voice belongs in Behaviour.
- "Files & vision" holds files in and out, visuals, and the future image model.
- Profile is renamed Appearance.

## Global constraints

- No Claude name, co-author or session trailer in commits.
- **A section's save** validates and changes only that section's fields. A switch on another tab is never turned off because its form didn't send it.
- **A save without a section** behaves exactly as today, so older clients and tests still work.
- The answer cache is cleared on every save except Safety's. Safety changes no stored answer.
- Every card keeps its fields, ids and scripts; only where a card appears changes. Each card's script already skips itself when its elements are absent.

## Sections

| Tab | Route | Cards |
|---|---|---|
| Appearance | `bots.edit` (unchanged) | the Profile page's cards; the tab label changes |
| Behaviour | `bots.settings` / behaviour | System prompt, Model & endpoint, Generation, Voice |
| Brain | `bots.settings` / brain | Answer sources (with follow-ups), Knowledge base & databases, Web search, Answer cache |
| Agents | `bots.settings` / agents | Agentic (tools, built-ins, skills, call limit) |
| Files & vision | `bots.settings` / files | Files & visuals, renamed Files & vision |
| Safety | `bots.settings` / safety | Guard, refusal, topics, the check that answers match their sources |

- `bots.brain` (GET) redirects to the Behaviour tab, so old links keep working.
- `bots.brain.update` (PUT) stays the save endpoint and takes a `section`.

## Tasks

1. **Controller.**
   - Add `BotBrainController::SECTIONS` (field lists per section).
   - `update` filters its rules and switches by `section`, and redirects to that tab.
   - `edit` takes the section.
   - Tests in `BotSettingsTabsTest`:
     - each section saves only its own fields;
     - a Brain save leaves Agents, files, voice and guard untouched;
     - Safety keeps the cache, any other section clears it;
     - an unknown section is a 404;
     - `bots.brain` redirects to Behaviour.
2. **Routes and view.**
   - Add the `bots.settings` route.
   - `_tabs` gets the six tabs.
   - `brain.blade.php` renders only `$section`'s cards. On a tab page every card starts open, and the save button names the tab.
   - Tests:
     - each tab shows its cards and none of the others;
     - the tabs bar shows six links;
     - Appearance is the first tab.
3. **Existing tests.** Point each `get(route('bots.brain'…))` at the tab that holds what it checks.
4. **Coverage.**
   - Map `bots.settings` to `get_bot` in `Coverage`.
   - Regenerate `docs/admin-assistant.md`.
   - The console assistant widget stays off the new tab pages, as it did on Behaviour.
5. **Live check.** Open each tab in the browser, save one field on each, and confirm other tabs are unchanged.
