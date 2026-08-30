# Frontend Audit — Phase 0 findings

**Date:** 2026-08-26 · **Scope:** 70 PHP files (excluding `.playwright-mcp/`), `assets/css/app.css`, `assets/js/*`

## 1. Headline finding: Tailwind CDN and Alpine are NOT wired up

- `assets/js/vendor/tailwindcss.js` exists on disk but is referenced by **zero** PHP files.
- `assets/js/vendor/alpine.min.js` exists but is referenced by **zero** PHP files; no `x-data` / `x-show` / `@click` directives anywhere (only SVG attrs like `x-width`).
- All styling comes from `assets/css/app.css` (254 lines) + three inline `<style>` blocks on auth pages.
- JS-generated markup (`ui.js`, `idle.js`) uses the same custom classes: `toast`, `btn btn-primary`, `btn btn-danger`, `idle-box`, `idle-count`.

**Implication:** there is no runtime-vs-compiled conflict risk. Phase 1 is simpler than planned: add compiled Tailwind output alongside `app.css`; the "remove vendor runtime" step of Phase 5 is just deleting an already-orphaned file. The HANDOFF.md claim that Tailwind/Alpine are "served locally" describes intent, not reality.

## 2. app.css section map (254 lines)

| Section | Lines | Contents |
|---|---|---|
| Fonts | 8–12 | `@font-face` ×5, Plus Jakarta Sans woff2 |
| Design tokens | 15–48 | `:root` vars (shell/accent/status colors/radius) + dark theme overrides |
| Base | 51–61 | body, headings, links |
| App shell | 64–97 | `.shell .sidebar .sidebar-brand .nav-* .topbar .content` + ≤900px mobile nav |
| Components | 100–160 | `.card(-pad,-head)` `.form-grid` `.toolbar` `.muted` `.stat-*` `.btn*` inputs/labels `table.data` `.table-wrap` `.when` `.tbl-tools` `.badge` + 13 `.b-STATUS` |
| Settings drawer | 163–189 | `.drawer*` `.seg` `.drawer-link` `.icon-btn` `.nav-toggle` |
| Modal & toast | 192–201 | `.modal*` `#toastZone` `.toast(.err/.ok)` |
| Idle overlay | 204–207 | `#idleOverlay` `.idle-box` `.idle-count` |
| Tracking page | 210–223 | `.track-*` `#map` `#crumbMap` `.legend` `.b-current/delayed/offline` |
| Chat panel | 226–254 | `.chat-*` `.msg` `.chip` |

## 3. Class inventory (from all PHP `class="..."` attributes)

Top custom classes by occurrence:

| Class | Uses | Class | Uses |
|---|---|---|---|
| `btn` | 102 | `icon-btn` | 13 |
| `muted` | 62 | `form-grid` | 12 |
| `btn-sm` | 53 | `table-wrap` | 11 |
| `btn-primary` | 53 | `card-head` | 11 |
| `card` | 51 | `modal-backdrop/body/head/foot/modal` | 9 ea |
| `btn-ghost` | 37 | `btn-danger` | 8 |
| `badge` | 37 | `tbl-tools` | 7 |
| `card-pad` | 30 | `when` | 3 |
| `table.data` (`data`) | 29 | `toolbar` | 16 |

Dynamic: `b-<?= ... ?>` (17×) plus literal `b-DRAFT`(6)/`b-COMPLETED`(4)/etc. Status badge color comes entirely from the `.b-STATUS` map — must be preserved as a mapping (component or safelist).

**Zero Tailwind utility classes found in markup** (no `flex`, `gap-*`, `text-*`, `p-*`… anywhere).

## 4. Auth pages: orphaned inline CSS

`auth/login.php:33`, `auth/forget_pw.php:53`, `auth/forget_otp.php:117` each embed a `<style>` block defining page-local classes that do NOT exist in app.css:
`hero`, `card-login`, `form-side`, `field`, `logo-main`, `logo-dark`, `login-card`.
These are the main hardcoded-CSS migration targets outside app.css.

## 5. Repeated markup → partial/ui component candidates

| Pattern | Files | Candidate component |
|---|---|---|
| `table-wrap > table.data > tbl-tools` list layout | director/approvals, director/dashboard, director/reports, flags.php, personnel/feedback, personnel/requests, staff/assignments, staff/trips (8) | `table.php` (+ optional `toolbar.php`) |
| `modal-backdrop > modal > head/body/foot` | mis/accounts, mis/chatbot, mis/fleet, staff/assignments, ticket.php + partials/reason_modal.php, layout_foot (7) | `modal.php` |
| `badge b-<?=status?>` pill | most list pages | `badge.php` (status→class map lives here) |
| `stat-grid > stat-card(label,value)` | dashboards | `stat_card.php` or fold into `card.php` |
| `form-grid` + label/input blocks | request form, MIS fleet/accounts, auth pages | `input.php` (label+control wrapper) |
| `btn btn-primary/-ghost/-danger btn-sm` | everywhere (102+) | `button.php` |
| `card card-pad` (+`card-head`) | everywhere (51+) | `card.php` |

## 6. Risks/notes discovered

- `layout_foot.php` renders shared modal/toast/idle DOM — migrate carefully (every authenticated page).
- Dark mode relies purely on CSS vars under `html[data-theme="dark"]` — compiled Tailwind should consume the same vars (`rgb(var(--...) / <alpha-value>)` or plain var references); do not introduce duplicate token sources.
- `tracking.php` and `ticket.php` inject Leaflet CSS/JS via `$HEAD_EXTRAS` after app.css — keep that ordering.
- `tests/run.php` exists (plain assert runner) — extend in Phase 6 as planned.

## 7. Recommended adjustments to HANDOFF_MIGRATION.md plan

1. Phase 1: no preflight-conflict concern from a loaded CDN runtime, but keep preflight off anyway (app.css base styles assume browser defaults, e.g. unstyled buttons).
2. Phase 5: removing `tailwindcss.js` = delete orphaned file (zero references today).
3. Keep Alpine.js vendored and untouched (constraint says keep it; nothing uses it — do not add usage during migration either).
4. Auth-page inline styles are small enough to fold into the compiled build during Phase 4 (auth cluster last).
