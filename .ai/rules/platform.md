# ITP platform architecture

Status: agreed direction, Nov 2026. This file lives at `.ai/rules/platform.md` in every ITP repo (`itp-core`, `itp-server`, `itp-app`, `itp-site`), so any agent working in one repo knows the boundaries of all of them. The `itp-core` copy is canonical: edit it there, then copy it unchanged to the others.

ITP is completely separate from the Numinous ecosystem: its own database, its own packages, its own admin. Nothing here depends on `numinous/core` or Artemis.

---

## 1. Repos

| Repo | What it is | Deployed as |
|---|---|---|
| `itp-core` | Composer package. Shared models, shared migrations, sync registration. **Device-safe.** | Required by `itp-server` and `itp-app` |
| `itp-server` | Backend. API, generation pipeline, Horizon, push sending, Studio (admin). The only home of server-only code. | Forge: `api.` + `studio.` |
| `itp-app` | The app. One codebase, two builds: web and NativePHP mobile (web-view mode). | Forge: `app.` + `itp.sh` / Bifrost: iOS + Android |
| `itp-site` | Marketing site. No database dependency. | Forge: apex domain |
| `lightwalkernet/sync` | Universal offline sync package, usable by ITP and the Cura program. Spec: `.ai/rules/sync.md` in the sync repo. | Required by `itp-core` |

Repo names are placeholders; rename freely, but keep the boundaries.

### Package installs

- `itp-core` and the sync package are private GitHub repos. Every consumer installs them as `"type": "vcs"` repositories.
- No version tags. Consumers require `dev-main`, and `composer.lock` pins the exact commit, so builds stay reproducible. Update deliberately: name the ITP packages, never run a bare `composer update` (`composer update lightwalkernet/itp-core lightwalkernet/sync`). Both are root requirements, so naming only `itp-core` leaves sync on its old commit. Only push `main` when its tests are green.
- Composer accepts a `dev-main` requirement from the root `composer.json` only, and doesn't read repositories declared by dependencies. So each app (`itp-server`, `itp-app`) requires `lightwalkernet/sync: dev-main` directly, next to `itp-core`, and lists both `vcs` repositories itself.
- **Never commit a `path` repository.** Composer symlinks path repos into `vendor/`. NativePHP bundles `vendor/` by copying it, so a symlink either ships broken or drags the whole sibling repo (tests, `.ai/`) onto the phone. Before every mobile build, `find vendor -type l` must print nothing.
- Forge and Bifrost each need a read-only GitHub token for the private repos, set in their own settings. Never commit `auth.json`.

## 2. Domains

| Host | Served by | Purpose |
|---|---|---|
| `itstotallypossible.life` | `itp-site` | Canonical brand domain. Marketing, privacy policy, delete-account page (explains and links to the web build's deletion page, section 5), beta signup (Mailcoach API). |
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
- Before each release, unzip the built `.ipa`/`.apk` and check for the above, and check that `vendor/` holds no symlinks (section 1). Automate this once there is CI.

## 3a. The platform flag

One config value in `itp-app` drives every difference between the two builds. It follows the Cura program's runtime flag (`numinous.runtime`), so both projects read the same way:

```php
// config/itp.php (itp-app)
'runtime' => env('ITP_RUNTIME', 'server'),   // 'server' (web build) | 'mobile'
```

- Read it via `config('itp.runtime')` or `Runtime::isMobile()` / `Runtime::isServer()`. Never `env()` outside `config/`.
- Default is `server`. A missing value must never produce mobile behaviour.
- Do not detect NativePHP at runtime (facade present, user agent, `nativephp-internal.running`). The flag is declared per build, so behaviour is explicit and testable.
- Set per build:
  - **Web (Forge):** `ITP_RUNTIME=server`, MySQL.
  - **Mobile (Bifrost):** `ITP_RUNTIME=mobile`, `DB_CONNECTION=sqlite` and `API_BASE_URL` in the Bifrost build configuration, and nothing secret. For local `native:run`, a committed `.env.mobile` template with the same keys is swapped in for the build.
- `AppServiceProvider::boot()` throws if the flag and the default DB connection disagree (`mobile` + mysql, or `server` + sqlite).
- NativePHP's service providers register only when `runtime=mobile`. On Forge it is files in `vendor/` and nothing else.
- The layout puts `@class(['is-native' => Runtime::isMobile()])` on `<html>`. CSS and `transitions.js` read `html.is-native` (section 8). Nothing else in the front end checks the build.
- `sync.role` follows the flag: `client` on `mobile`, `server` on `server`.

## 4. Data ownership and migrations

One MySQL database, `itp`, on the external DB server. `itp-server` owns the schema and is the only thing that runs migrations against it.

| Migrations | Live in | Run on |
|---|---|---|
| Shared tables (anything `itp-app` reads or writes) | `itp-core` | MySQL (by `itp-server`) **and** device SQLite |
| Server-only tables (pipeline internals, staff, audit log, device tokens, push schedules, `personal_access_tokens`) | `itp-server` | MySQL only |
| Device-only tables (local settings, sync state, download queue) | `itp-app/database/migrations/device` | Device SQLite only |

- `itp-core` migrations must be portable across MySQL and SQLite: no MySQL-only column types, no raw SQL.
- `itp-core`'s service provider does not auto-load its migrations. `itp-server` loads them, and `itp-app` loads them only when `runtime=mobile`.
- The web build of `itp-app` runs **no** migrations. It connects to the same MySQL database and uses `itp-core` models directly.
- **Runtime stores:** the web build keeps sessions, cache and locks in Redis (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`), on its own Redis database or prefix, apart from `itp-server`'s Horizon queues. It therefore needs no `sessions`, `cache` or `cache_locks` tables in the shared database. The mobile build uses the `file` driver for both, so a session write never contends for the SQLite file.
- Plain Eloquent models everywhere. No DTO or repository layer between Livewire and the data.
- Synced tables use ULID primary keys (created on the device or the server, never remapped) and the sync package's Blueprint macro, `$table->syncable()`, with the `Syncable` trait on the model (see the sync spec).
- **Write synced tables through Eloquent models only:** `create()`, `save()`, `delete()` on a model instance. `DB::table()`, `Model::query()->update()`, `insert()` and `upsert()` skip the model events that stamp the change number, so the row never reaches the phone. For a batch write, use the sync package's batch helper (`updateSynced()`, see the sync spec), never a raw query.

### Schema changes after first release

Applies from the first store release (v1.0) onward. Until then, shared migrations can be edited freely, because no installed app depends on them.

Once a version is in the stores, phones running older versions keep talking to the API and keep their own SQLite schema until they update.

- **Shared (`itp-core`) tables change additively only:** new tables, new nullable columns, new columns with defaults. No renames, drops, type changes or new `NOT NULL` columns without defaults.
- Removing or renaming a column takes three releases: stop reading and writing it, raise the minimum app version past the last version that used it, then drop it.
- The server never requires a new field from a push. Old clients don't send it, and the sync `fields` allowlist simply doesn't receive it.
- **The API enforces a minimum app version.** Both builds send `X-App-Version` on every API call (the web build sends its deployed version). `itp-server` answers `426 Upgrade Required` below `config('itp.min_app_version')`, and the app shows an "update required" screen. Sync stops until the app is updated; local data is untouched.
- Server-only tables are free to change at any time.

### What lives in `itp-core` vs `itp-server`

The finished, playable ITP is shared. The machinery that produced it is not.

- **`itp-core`:** users, ITPs (finished attunement text + audio reference), companions (Breath 11, Quantum Whys), activations/plays, user settings, notification preferences, shares, universal ITPs, wildcards, backgrounds.
- **`itp-server`:** intakes, generations, attunement segments, segment flags, follow-ups, ratings, fixtures/evaluation set, reversal derivations, slot extraction, classifier output, staff, audit log, Sanctum tokens.

**A playable ITP is one row.** The attunement and its companions (Breath 11, Quantum Whys) are columns on the same row, so a pull can never deliver half an ITP. `itps` (user) and `universal_itps` (global) share the same content columns, defined once in `Itp\Core\Schema\ItpContent`. The old parent table with activation, Breath 11 and base child tables is not carried over.

`itp-core` registers four sync types, in pull order:

| Type | Model | Scope | Notes |
|---|---|---|---|
| `users` | `User` | user, owner key `id` | Pull-only (section 5) |
| `itps` | `Itp` | user | Pull-only for now: the phone never edits a finished ITP |
| `universal_itps` | `UniversalItp` | global, daily | `kind`: universal or base (Introduction, heart coherence) |
| `wildcards` | `Wildcard` | global, daily | |

Plays, settings, notification preferences, shares and backgrounds are added with their features (section 11, steps 5–7). Luminous is still undecided (section 12).

Generation-in-progress screens never read pipeline tables directly; they go through the API (section 6). This keeps the prompt-adjacent data off devices and gives both builds one code path.

## 5. Auth

Passwordless email OTP (six digits), unchanged in spirit from the current build.

- The OTP endpoints live in `itp-server` (`api.`): `send-code`, `verify-code`, `complete-profile`, `logout`.
- Sanctum: the `HasApiTokens` trait is on the `itp-core` `User` model, so `itp-core` requires `laravel/sanctum`. The `personal_access_tokens` migration is server-only and lives in `itp-server`. Users have ULID keys, so it uses `ulidMorphs('tokenable')`. Only `itp-server` creates or revokes tokens.
- `itp-app` has one `AuthClient` used by both builds. It calls those endpoints over HTTP. On success the backend returns the user and a Sanctum token.
  - **Mobile:** token + user id go into SecureStorage, and the returned user row is written to local SQLite at login.
  - **Web:** the app logs the user into its own session (`Auth::login`, possible because it shares the database) and keeps the token in the encrypted session for later API calls.
- **`users` is pulled, never pushed.** On mobile, the row arrives at login (above). After that, `users` is registered with sync as a pull-only, user-scoped type (owner key `id`, empty `fields`), so a change made on web or by the server reaches the phone on the next sync. Profile changes (first name) always go through the API, on both builds.
- **Logout:**
  - **Web:** `AuthClient::logout()` revokes the Sanctum token (`POST logout`), then `Auth::logout()`, session invalidate and token regenerate. If the API call fails, the local session still ends.
  - **Mobile:** the same API call, then the sync sign-out wipe and removing the token from SecureStorage.
- The only per-build difference is where the token is stored. Bind that with the platform flag (section 3a).
- First name is still collected only if empty, checked on every boot.
- **A 401 on any API call signs the app out.** On mobile that runs the sync sign-out wipe. This is how a phone learns its account was deleted or its token revoked.

### Account deletion

Decided Nov 2026: web-only, and a hard delete.

- **Only on the web build.** The deletion page lives on `app.itstotallypossible.life`, behind a fresh OTP sign-in, which doubles as the identity check. The mobile app has a "Delete account" button and no deletion screen: the button opens that page directly in the browser, never a home or settings page. The `itp-site` delete-account page explains the process and links to the same page.
- **Store review is a known risk, accepted for simplicity.** Apple 5.1.1(v) wants deletion offered "within the app", and allows a link straight to the web page that completes it. Google Play wants an in-app way to start it, plus a web page that works on its own. If App Review objects, add a one-time link from the app that skips the second sign-in; don't build in-app deletion unless asked.
- **`itp-server` does the delete, through the API.** It is a hard delete: `forceDelete()`, never `delete()`. `users` has `deleted_at` only because the sync package's `Syncable` trait brings soft deletes with it. A tombstone could never reach a deleted user, because pulling needs a valid token.
  - Revoke the user's Sanctum tokens explicitly. `personal_access_tokens` points at users without a foreign key.
  - Delete dependent rows first: user-scoped `itp-core` tables (`itps` has a `user_id` foreign key without cascade) and server-only tables (intakes, generations).
  - The phone's next API call gets a 401 and signs out, which wipes its local copy. A phone that is offline keeps its copy until it next connects.

## 6. Generation

Generation always runs on `itp-server` and always needs a connection, on both builds.

- `itp-app` has one `GenerationClient` (HTTP, Sanctum bearer token) for: submit intake, fetch follow-ups, submit follow-up answers, poll generation status, flag a section, choose between a rewritten section and the original, accept the attunement.
- The attunement is reviewed by section, not by line (prompt library v2, `itp-server` `database/data/prompts/ITP_Prompt_Library_v2.md`). Every line belongs to one of eight SHAPE parts. The app shows each part as a plain passage; only Studio shows the part names. A flag rewrites the whole section, optionally pointing to the line that bothered the person most. The rewrite comes back as a proposal beside the original, with "Use this" and "Keep the original". Nothing is replaced until they choose, and accepting waits until every proposal is settled. One section is rewritten at a time. Attunements from before v2 have no parts and count as one section.
- The same Livewire screens drive the flow on web and mobile. No "direct" implementation on web: one path, one set of bugs.
- When an attunement is accepted and companions are derived, the finished ITP is written to `itp-core` tables and reaches the phone through normal sync (with its audio downloaded for offline playback).
- Offline on mobile: the generation entry point shows a clear "needs a connection" state (NativePHP network plugin), and everything already synced keeps working.

## 7. Offline and audio

- Mobile must play every synced ITP offline. Generation is the only online-only feature.
- Audio and images live in R2. The API returns short-lived signed URLs.
  - **Mobile:** a device-only download queue fetches audio for every pulled ITP and records the local path. Playback uses the local file. Bundled base media (breath sounds, introduction, backgrounds) stays in `resources/bundled`.
  - **Web:** streams from signed URLs.
- Every URL to a file in `public/` (images, audio, fonts, CSS `url()` written in Blade) goes through `asset()`. On device the scheme handler serves `public/` only under the `_assets` prefix that `asset()` adds, so a hard-coded `/file.m4a` works in the browser and 404s on the phone.
- Sync itself: see the sync spec (`.ai/rules/sync.md` in the sync repo). The sync client is active only on the mobile build. On the web build and in `itp-server` the package runs in server role.

### Universal ITPs and audio

- Every universal ITP has a fixed ULID `id` in `itp-server`'s `database/data/universal-itps.json`. `itp:import-universal-itps` writes it, so the server row, the bundled snapshot and every phone share one key. Never regenerate them.
- `itp:export-content-snapshot` writes the global sync types (universal ITPs, wildcards) as pulled rows without `sync_seq`. `itp-app` bundles the result at `resources/content/snapshot.json` and applies it on first launch, offline and signed out. A bundled row never overwrites a newer `updated_at`, and never moves the sync cursor.
- `GET /v1/content/pull` is the sync pull for global types, without sign-in: same body as `GET /v1/sync/pull`, rate limited per IP (`content`), any other type 404. Guests pull through it, so server changes reach every phone on load.
- Guests can play the `is_preview` ITPs. The rest show locked, with "Sign in to play". Signed-in users play all of them.
- `itp-app` bundles the preview ITPs' audio (attunement and Breath 11), the Introduction audio and every universal image in `public/media`, under the same keys as R2. Other universal audio downloads after sign-in from the public media host (`itp.media_url`).
- Audio is never replaced in place. A new recording goes up under a new key (`….v2.mp3`) and the row's path changes. The phone downloads any path it doesn't have and deletes files no row references.

## 8. Navigation and transitions

The single-page omni/accordion structure is retired. Every screen is a real route and a full-page Livewire component.

Stack: Laravel 13, Livewire 4.4, NativePHP Mobile 4.4, as proven in the `itpinterface` spike (see its `FINDINGS.md`).

**The whole app runs in NativePHP's root web view.** No native screens, no `<native:webview>` embedded inside native screens. The spike found the embedded web view strips custom request headers (Livewire actions 404 without a workaround), never receives `OnNative` events, and can only intercept navigation one way. Everything works normally in the root web view, so the spike's `RestoreLivewireHeaderOnDevice` workaround is not ported.

Page transitions follow the `itpinterface` transition demo (`resources/js/transitions.js`, `resources/css/transitions.css`). On real iOS and Android phones (device test matrix, `FINDINGS.md` section 15, first run Nov 2026), these pass: link-driven slides, swipe-back, audio across navigation, Android back and slow pages. Rapid input (row 6) is still to check.
- Links carry `data-transition="forward" | "back" | "tab"` and deliberately **don't** use `wire:navigate`, so Livewire's click handling can't race the transition. The script calls `Livewire.navigate()` itself, inside `document.startViewTransition()`.
- The direction is a `data-nav-direction` attribute on `<html>`, re-applied after Livewire's swap. The swap overwrites `<html>` attributes, inline `style` included, so anything the script sets on `:root` is re-applied too. The outgoing and incoming pages are named `page-out` / `page-in`, so each keeps its own position.
- Back uses real `history.back()`, so browser history and the Android back button stay in step. A `popstate` listener keeps the script's own stack correct for back navigations it didn't start.
- The iOS-style edge swipe is hand-built: the page follows the finger with a static copy of the previous page underneath, then hands off to the view transition from where the finger let go. It runs only under `html.is-native`, so it never races a mobile browser's own back swipe.
- Timing lives in custom properties on `:root`: `--page-duration` (450ms: 380 and 450ms felt the same on device, so the longer one), `--page-easing` (`cubic-bezier(.2, .8, .2, 1)`) and `--fade-duration` (150ms). A swipe release takes `--page-duration` × the distance left, floored at 40%, so letting go is always quicker than a tap.
- Slides are a **push**, not the iOS parallax: the old page moves fully off and isn't dimmed. Pages can be see-through to a background, and a parallaxed old page shows through as a grey panel.
- Slide animations apply only under `html.is-native` (set from the platform flag, section 3a). Without View Transitions support (iOS < 18), pages swap instantly; nothing breaks.
- **Web build:** the spike's 150ms crossfade didn't work on desktop. Choose a simpler treatment, or none, when building `itp-app`.
- `@persist` keeps the bottom nav and the audio player alive across navigation. The tab bar's active state is updated by the script, because a persisted element doesn't re-render.
- `@persist` cancels running CSS animations on every navigation (Livewire removes and re-inserts the element), so keep animated elements out of persisted regions.
- **Ken Burns is not an app-wide background** (decided Nov 2026: it glitched across page transitions on both phones). If it's used at all, it lives on a single screen, so it never crosses a navigation. If it is ported, take the spike's `ken-burns.js`, which fixes Artemis's mid-crossfade bug, not Artemis's original.
- `<html>` gets `scrollbar-gutter: stable`, so desktop scrollbars don't resize the fixed background between pages.
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
| `itp` branch `build-itp-page` | `ItpGenerationController`, `Http/Requests/Itp`, `Http/Resources/Itp` | `itp-server` API (behind `GenerationClient`) |
| `itp` branch `build-itp-page` | Harness, GenerationInspector, FixtureManager, IntakeForm | `itp-server` Studio (IntakeForm is also the model for the app's intake screen) |
| `itp` main | OTP auth (`Api/Mobile/AuthController`, EmailVerificationCode, OtpCodeMail) | `itp-server` API |
| `itp` main | Device tokens, notification schedules, FCM push command (`SendScheduledPushNotifications`) | `itp-server` |
| `itp` main | `database:backup` command (daily at 03:00) | `itp-server`, or replace with backups on the DB server |
| `itp` main | ElevenLabs voice modules (`modules/Voice`, ElarielJob, `Livewire/Voice/Elariel`) | `itp-server` |
| `itp` main | Marketing pages, privacy, delete-account, beta signup, `/app` and `/private/signup` share pages | `itp-site` |
| `itp` main | Oracle v1–v3 (`Oracle` model, `oracles` migrations, OracleJob, `Livewire/Ai/Oracle`, `Traits/Oracle`, `modules/AI/Oracle.php`) and the card maker (`modules/Cards/Maker.php`, used only by OracleJob) | Drop |
| `itp` main | Luminous (`itp_luminous`, a per-ITP companion: audio, words, images; returned with universal ITPs) | Decide (section 12). If kept, redesign in `itp-core` with the other companions |
| `itp` main | `Audio` model + `audio/upload` endpoint (user uploads to R2; nothing in `itpmobile` calls it) | Drop |
| `itp` main | Password-auth web routes, `angels` area (AI tester, Possible game, universal tester) | Drop |
| `itp` main | Universal ITP and wildcard endpoints behind a fixed UUID path | Drop (replaced by `global` sync types) |
| `itpmobile` | Player components and scripts (breath, words, why, burns, audioPlayer, `luminous.js` if Luminous stays), `Player/Introduction` | `itp-app` |
| `itpmobile` | Settings (backgrounds, notifications), QuantumNotificationManager (14-day rolling buffer) + `MaintainQuantumSchedule` | `itp-app` (mobile-only) |
| `itpmobile` | `DownloadITPTrait` | `itp-app` (reference for the download queue) |
| `itpmobile` | Bundled media, fonts, form/icon components | `itp-app` |
| `itpmobile` | omni.js, accordion navigation (`Nav/*` components), single `/` route | Drop (replaced by routes) |
| `itpmobile` | `AuthService`, `AuthMiddleware` | Drop (replaced by `AuthClient`) |
| both | SyncService, Syncable, sync config, `Sync/*` components, `SyncUniversalItps` / `SyncWildcards` jobs | Drop (replaced by the sync package) |
| both | ITP, Base, Breath11, Why, Activations, ItpWildcard models | Redesign in `itp-core` |

## 11. Build order

1. **Finish the transition spike in `itpinterface`.** Built: directional slides, swipe-back, `@persist` audio, timing tokens, `html.is-native`. Device matrix first run done (Nov 2026): `--page-duration` is 450ms, and Ken Burns is limited to a single screen (section 8). Still to do: row 6 (rapid input), then record go/no-go in `FINDINGS.md` section 15.
2. **Sync package, schema half.** The `$table->syncable()` Blueprint macro, the `Syncable` trait (ULIDs, soft deletes, server-side stamping, `updateSynced()`), the `sync_sequence` table and its tests.
3. **`itp-core` + `itp-server` skeleton.** Shared migrations (using the macro), staff + roles, Studio login, Horizon gate, OTP API, minimum-version check.
4. **Sync package, transport half.** Push/pull endpoints, client, cursors and the Testbench scenarios in the sync spec.
5. **`itp-app` skeleton.** Platform flag, layouts, `@persist` regions, AuthClient, first screens.
6. **Pipeline port.** From `build-itp-page` into `itp-server`, with GenerationClient in the app.
7. **`itp-site` + `itp.sh` shares.** Including universal/app link files.

## 12. Open items

- Final repo names.
- Whether Luminous is still needed. It is per-ITP content, so keeping it means a companion model in `itp-core`.
- Transition go/no-go, after row 6 of the device matrix (section 11, step 1).
- Whether iOS needs the `audio` background mode. Playback continues with the screen locked, and the lock screen shows a play control. Switching to another app is not checked yet.
