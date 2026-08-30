# NAAP GSD Digital Trip Ticket System — Agent Guidelines

## CSS architecture (read before editing any PHP/UI)

- PHP templates use **standard Tailwind utilities** + **named semantic classes** defined in `assets/css/app.css`.
- **No arbitrary Tailwind values** in PHP files (no `[property:value]` classes). If a one-off size/spacing is needed, add a named class to `app.css`.
- `app.css` is **unlayered** and loads after `tailwind.css`, so `app.css` rules always override Tailwind utilities.
- Design tokens live in `assets/css/tailwind.src.css` (`@theme`) and `app.css` (`:root`). Custom text/color tokens in `@theme` become Tailwind utilities (e.g. `--text-md` → `text-md`). Keep the two token blocks in sync.
- Status colors map to `badge-*` classes. Status names come from DB (`request_status.status_name`); the dynamic mapping is `strtolower(str_replace('_','-',status_name))` → `badge-{name}`. When adding a status, add the matching `.badge-{name}` in `app.css` or badges render unstyled.

## Commands to run after any UI/CSS change

1. `npm run build` — recompiles `tailwind.css` (Tailwind v4 JIT only scans sources at build time; new utility classes in PHP won't apply otherwise).
2. `php scripts/class_audit.php` — flags literal `class="..."` tokens in PHP HTML that are neither compiled Tailwind utilities nor defined in `app.css` (exit 1 = problems). PHP-built dynamic classes and arbitrary `[value]` classes are intentionally skipped.

## Class audit (scripts/class_audit.php)

Checks every PHP file's literal HTML classes against: compiled `tailwind.css` utilities + `app.css` definitions + JS-hook allowlist (`hero-slide`, `logo-main`, `logo-dark`). Dynamic prefixes ending in `-` (`badge-<?= … ?>`) are skipped. Run it after adding/renaming classes; it is the safety net for the "no undefined class names" rule.

## Conventions

- Sidebar nav items use `.nav-item` (base styles in `app.css`); active state adds `bg-accent text-on-accent`.
- Cards: `.content-card` + `.card-head` (header) + body padding. Modals: `.modal-backdrop` > `.modal-body` > `.modal-head`.
- Buttons: `.btn btn-primary|btn-ghost|btn-danger`, optional `btn-sm`. Inputs: `.form-input`, labels `.form-label`.
- Status badges: `.badge badge-{status}`.
- Keep PHP template markup free of inline `<style>` blocks and inline `style=` styling where a semantic class exists.