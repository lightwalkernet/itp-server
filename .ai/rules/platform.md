# ITP platform architecture

Status: agreed direction, Nov 2026. Place this file at `.ai/rules/platform.md` in every ITP repo so any agent working in one repo knows the boundaries of all of them.

ITP is completely separate from the Numinous ecosystem: its own database, its own packages, its own admin. Nothing here depends on `numinous/core` or Artemis.

---

## 1. Repos

| Repo | What it is | Deployed as |
|---|---|---|
| `itp-core` | Composer package. Shared models, shared migrations, sync registration. **Device-safe.** | Required by `itp-server` and `itp-app` |
| `itp-server` | Backend. API, generation pipeline, Horizon, push sending, Studio (admin). The only home of server-only code. | Forge: `api.` + `studio.` |
| `itp-app` | The app. One codebase, two builds: web and NativePHP mobile (web-view mode). | Forge: `app.` + `itp.sh` / Bifrost: iOS + Android |
| `itp-site` | Marketing site. No database dependency. | Forge: apex domain |
| `lightwalkernet/sync` (name TBC) | Universal offline sync package, usable by ITP and the Cura program. See `.ai/specs/sync.md`. | Required by `itp-core` consumers |

Repo names are placeholders; rename freely, but keep the boundaries.

## 2. Domains

| Host | Served by | Purpose |
|---|---|---|
| `itstotallypossible.life` | `itp-site` | Canonical brand domain. Marketing, privacy policy, delete-account page, beta signup (Mailcoach API). |
| `totallypossible.life` | Forge redirect | 301 to `itstotallypossible.life`, path preserved. |
| `app.itstotallypossible.life` | `itp-app` (web build) | The web app. Identical screens to the mobile app. |
| `itp.sh` | `itp-app` (web build, second domain on the same Forge site) | Shares only. `itp.sh/{code}` public, `itp.sh/p/{token}` private (long unguessable token). Serves `/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json` so a tapped share opens the app when installed; otherwise the web player loads with an "open in app / get the app" prompt. |
| `api.itstotallypossible.life` | `itp-server` | The single API host for both app builds. |
| `studio.itstotallypossible.life` | `itp-server` | Admin. Same deployment as `api.`, split with `Route::domain()`. |

Transactional email (Postmark) sends from `itstotallypossible.life`.

## 3. The device-safety rule

NativePHP ships the whole `itp-app` codebase, its `vendor/`, and therefore `itp-core`, onto every phone. Anything in those is readable by anyone who unzips the app.

**Never in `itp-app` or `itp-core`:** generation prompts or stages, Anthropic / ElevenLabs / Laravel AI SDK code, API keys or service-account files (FCM, R2 write keys), Horizon, admin code.

Enforcement:
- Pest arch tests in `itp-app` and `itp-core`: nothing may reference `Laravel\Ai`, `Prism`, ElevenLabs, or any `Itp\Server` namespace.
- `config/nativephp.php` → `cleanup_exclude_files` lists `credentials/`, `*service-account*.json`, `auth.json`, `tests/`, `.ai/`.
- The mobile `.env` holds only the API base URL and non-secret flags. `cleanup_env_keys` stays strict.
- Before each release, unzip the built `.ipa`/`.apk` and check for the above. Automate this once there is CI.

## 4. Data ownership and migrations

One MySQL database, `itp`, on the external DB server. `itp-server` owns the schema and is the only thing that runs migrations against it.

| Migrations | Live in | Run on |
|---|---|---|
| Shared tables (anything `itp-app` reads or writes) | `itp-core` | MySQL (by `itp-server`) **and** device SQLite |
| Server-only tables (pipeline internals, staff, audit log, device tokens, push schedules) | `itp-server` | MySQL only |
| Device-only tables (local settings, sync state, download queue) | `itp-app/database/migrations/device` | Device SQLite only |

- `itp-core` migrations must be portable across MySQL and SQLite: no MySQL-only column types, no raw SQL.
- The web build of `itp-app` runs **no** migrations. It connects to the same MySQL database and uses `itp-core` models directly.
- Plain Eloquent models everywhere. No DTO or repository layer between Livewire and the data.
- Synced tables use ULID primary keys (created on the device or the server, never remapped) and the sync package's Blueprint macro (see sync spec).

### What lives in `itp-core` vs `itp-server`

The finished, playable ITP is shared. The machinery that produced it is not.

- **`itp-core`:** users, ITPs (finished attunement text + audio reference), companions (Breath 11, Quantum Whys), activations/plays, user settings, notification preferences, shares, universal ITPs, wildcards, backgrounds.
- **`itp-server`:** intakes, generations, attunement segments, segment flags, follow-ups, ratings, fixtures/evaluation set, reversal derivations, slot extraction, classifier output, staff, audit log.

Generation-in-progress screens never read pipeline tables directly; they go through the API (section 6). This keeps the prompt-adjacent data off devices and gives both builds one code path.

## 5. Auth

Passwordless email OTP (six digits), unchanged in spirit from the current build.

- The OTP endpoints live in `itp-server` (`api.`): `send-code`, `verify-code`, `complete-profile`, `logout`.
- `itp-app` has one `AuthClient` used by both builds. It calls those endpoints over HTTP. On success the backend returns the user and a Sanctum token.
  - **Mobile:** token + user id go into SecureStorage; the user row is pulled into local SQLite.
  - **Web:** the app logs the user into its own session (`Auth::login`, possible because it shares the database) and keeps the token in the encrypted session for later API calls.
- The only per-build difference is where the token is stored. Bind that with the platform flag.
- First name is still collected only if empty, checked on every boot.

## 6. Generation

Generation always runs on `itp-server` and always needs a connection, on both builds.

- `itp-app` has one `GenerationClient` (HTTP, Sanctum bearer token) for: submit intake, fetch follow-ups, submit follow-up answers, poll generation status, flag a segment, accept the attunement.
- The same Livewire screens drive the flow on web and mobile. No "direct" implementation on web: one path, one set of bugs.
- When an attunement is accepted and companions are derived, the finished ITP is written to `itp-core` tables and reaches the phone through normal sync (with its audio downloaded for offline playback).
- Offline on mobile: the generation entry point shows a clear "needs a connection" state (NativePHP network plugin), and everything already synced keeps working.

## 7. Offline and audio

- Mobile must play every synced ITP offline. Generation is the only online-only feature.
- Audio and images live in R2. The API returns short-lived signed URLs.
  - **Mobile:** a device-only download queue fetches audio for every pulled ITP and records the local path. Playback uses the local file. Bundled base media (breath sounds, introduction, backgrounds) stays in `resources/bundled`.
  - **Web:** streams from signed URLs.
- Sync itself: see `.ai/specs/sync.md`. The sync client is active only on the mobile build. On the web build and in `itp-server` the package runs in server role.

## 8. Navigation and transitions

The single-page omni/accordion structure is retired. Every screen is a real route and a full-page Livewire component.

Stack: Laravel 13, Livewire 4.4, NativePHP Mobile 4.4, as proven in the `itpinterface` spike (see its `FINDINGS.md`).

**The whole app runs in NativePHP's root web view.** No native screens, no `<native:webview>` embedded inside native screens. The spike found the embedded web view strips custom request headers (Livewire actions 404 without a workaround), never receives `OnNative` events, and can only intercept navigation one way. Everything works normally in the root web view.

Page transitions follow the `itpinterface` transition demo (`resources/js/transitions.js`, `resources/css/transitions.css`), confirmed on real iOS and Android phones. Duration and easing are CSS custom properties so the feel can be tuned in one place (the 300ms default felt slightly quick on device).
- Links carry `data-transition="forward" | "back" | "tab"` and deliberately **don't** use `wire:navigate`, so Livewire's click handling can't race the transition. The script calls `Livewire.navigate()` itself, inside `document.startViewTransition()`.
- The direction is a `data-nav-direction` attribute on `<html>`, re-applied after Livewire's swap (the swap overwrites `<html>` attributes). The outgoing and incoming pages are named `page-out` / `page-in`, so each keeps its own position.
- Back uses real `history.back()`, so browser history and the Android back button stay in step. A `popstate` listener keeps the script's own stack correct for back navigations it didn't start.
- The iOS-style edge swipe is hand-built: the page follows the finger with a static copy of the previous page underneath, then hands off to the view transition from where the finger let go.
- Slide animations apply only under `html.is-native` (set by the platform flag). The web build gets a short crossfade or nothing. Without View Transitions support (iOS < 18), pages swap instantly; nothing breaks.
- `@persist` keeps the Ken Burns background, the bottom nav and the audio player alive across navigation. The tab bar's active state is updated by the script, because a persisted element doesn't re-render.
- Platform-only screens are gated by the flag, e.g. notification settings exist only on mobile.

## 9. Studio (admin)

Hand-built Livewire, no Filament. Lives in `itp-server` on `studio.`.

- Separate `staff` table and `studio` auth guard. App users can never become staff, and staff accounts are not app accounts.
- Staff log in with the same email OTP flow, against the staff table.
- Two roles:

| Capability | Owner (Aaron) | Editor (sister) |
|---|---|---|
| Horizon, failed jobs, queue health, logs | yes | no |
| App users and accounts | yes | no |
| Pipeline config (model routing, effort levels) | yes | no |
| Intakes **including personal answers** | yes | yes |
| Generations, outputs, segment flags, ratings | yes | yes |
| Evaluation set (hand-written scripts) and fixtures | yes | yes |
| Universal ITPs and wildcards | yes | yes |
| Generation harness / inspector (ported from `build-itp-page`) | yes | yes |

- Horizon is mounted on `studio.` and gated to the owner role.
- **Intake privacy, built in from day one:**
  - Personal intake answers use Laravel's `encrypted` casts at rest.
  - Every view of personal answers writes an audit-log row (staff member, intake, timestamp).
  - A Studio setting controls access: `all` (now, during quality work) → `flagged` (only intakes with an open problem/support flag) → `none`. Switching it later is a setting change, not a rebuild. The policy reads this setting.
  - The privacy policy on `itp-site` states this access.

## 10. Carrying over from the old repos

Fresh repos. Beta-tester data is not migrated. Port code deliberately, don't copy folders.

| From | Item | Goes to |
|---|---|---|
| `itp` branch `build-itp-page` | Pipeline: Actions, Stages, Jobs, enums, `config/itp.php`, pipeline models, factories, tests | `itp-server` |
| `itp` branch `build-itp-page` | Harness, GenerationInspector, FixtureManager | `itp-server` Studio |
| `itp` main | OTP auth (AuthController, EmailVerificationCode, OtpCodeMail) | `itp-server` API |
| `itp` main | Device tokens, notification schedules, FCM push command | `itp-server` |
| `itp` main | ElevenLabs voice modules, card maker | `itp-server` |
| `itp` main | Marketing pages, privacy, delete-account, beta signup | `itp-site` |
| `itp` main | Password-auth web routes, `angels` area | Drop (review Oracle v1–v3 and Luminous first) |
| `itpmobile` | Player components and scripts (breath, words, why, burns, audioPlayer) | `itp-app` |
| `itpmobile` | Settings (backgrounds, notifications), QuantumNotificationManager (14-day rolling buffer) | `itp-app` (mobile-only) |
| `itpmobile` | Bundled media, fonts, form/icon components | `itp-app` |
| `itpmobile` | omni.js, accordion navigation, single `/` route | Drop (replaced by routes) |
| both | SyncService, Syncable, sync config | Drop (replaced by the sync package) |
| both | ITP, Base, Breath11, Why, Activations, ItpWildcard models | Redesign in `itp-core` |

## 11. Build order

1. **Finish the transition spike in `itpinterface`.** Directional slides and swipe-back already exist there. Still to prove: `@persist` keeping audio playing and the Ken Burns background still, Android, desktop browsers, rapid taps. See `.ai/specs/spike-transitions.md` in that repo. Decide go/no-go before step 4.
2. **`itp-core` + `itp-server` skeleton.** Shared migrations, staff + roles, Studio login, Horizon gate, OTP API.
3. **Sync package.** Built and tested on its own (Testbench) per `.ai/specs/sync.md`.
4. **`itp-app` skeleton.** Platform flag, layouts, `@persist` regions, AuthClient, first screens.
5. **Pipeline port.** From `build-itp-page` into `itp-server`, with GenerationClient in the app.
6. **`itp-site` + `itp.sh` shares.** Including universal/app link files.

## 12. Open items

- Final repo and package names.
- Whether Oracle v1–v3, Luminous and the card maker are still needed.
- Which platform flag name to reuse from the Cura program's `platform.md`, so both projects read the same way.
