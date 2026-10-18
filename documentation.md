# Legacy CV Maker — Documentation

> **Legacy reference:** This describes the pre-rewrite CV Maker and its known issues. The current product scope and rewrite sequence are in [ATS_Plan.md](ATS_Plan.md).

> Tool location: `tools/cv-maker/index.php` (single file, 1,170 lines)
> Original context: the CV Maker was one feature in a general-purpose PHP toolbox.

## Overview

This is a snapshot of the old browser-based CV builder. It lets an authenticated user fill out structured CV data, see a **live preview** rendered in real time, save/load drafts to MySQL, and download a **PDF** generated server-side with mPDF. It is retained as migration reference for the ATS rewrite.

### Key characteristics

| Aspect | Detail |
|---|---|
| Architecture | Single PHP file: backend + frontend in one |
| Auth | `requireAuth()` — session-based |
| PDF engine | mPDF (`vendor/autoload.php`), A4, 12px margins |
| Draft storage | MySQL table `draft_cvs` (id, draft_name, cv_data JSON, created_at, updated_at) |
| Rendering model | **Dual renderer** — PHP renders the PDF; JavaScript re-implements rendering for the live preview |

---

## Features

### Templates (4)

1. **Classic** — plain black-on-white, horizontal dividers
2. **Modern** — dark sidebar (contact / skills / languages) + white main column
3. **Professional** — colored header band (#1e3a5f)
4. **Minimal** — serif font (Georgia), dotted dividers

Each template has a "section dividers" toggle that strips all borders/underlines.

### Sections

Always on: **Personal** (name, title), **Contact** (phone, email, location, LinkedIn, GitHub).
Toggleable: Summary, Skills, Experience, Education, Projects (default off), Certifications (off), Languages (off), Extra custom sections (off).

Repeatable entries ("+ Add") exist for skills, experience, education, projects, certifications, languages, and extra sections. Responsibilities within an experience entry are one-per-line in a textarea.

### Live preview

Every input fires `oninput="up()"`, which collects all form fields via `D()` and re-renders the preview entirely client-side using inline-styled HTML matching the chosen template.

### Drafts (AJAX)

- `GET ?action=list_drafts` → JSON list of drafts
- `GET ?action=get_draft&id=N` → draft's `cv_data` JSON
- `POST action=save_draft` (+ optional `draft_id`) → insert/update
- `POST action=delete_draft` → delete

The form is serialized into a JS object and stored as JSON in `cv_data`. Loading a draft calls `fillForm()` which rebuilds all dynamic rows and repopulates every field.

### PDF download

Regular form POST with `action=download`. The same form data is passed to `buildCvHtml()` (PHP), written to mPDF, and streamed as `cv.pdf`.

---

## Data flow

```
Form inputs ──oninput──▶ D() ──▶ buildPreview_*() ──▶ #cvPreview (innerHTML)
     │
     └──submit──▶ POST action=download ──▶ buildCvHtml($_POST) ──▶ mPDF ──▶ cv.pdf

Save: FormData ──▶ serializeDraftObj() ──▶ JSON.stringify ──▶ POST save_draft ──▶ draft_cvs.cv_data
Load: GET get_draft ──▶ JSON.parse ──▶ fillForm() ──▶ rebuild rows ──▶ up()
```

Note the asymmetry: the **preview** is built from the JS object `D()`, but the **PDF** is built from raw `$_POST` arrays. Two independent renderers must stay visually identical.

---

## Bugs

### 🔴 High severity

**B1. XSS via draft name (stored, self-contained).**
In `loadDraftList()` the draft name is interpolated directly into HTML:

```js
onclick="loadDraft(+this.dataset.id,this.dataset.name)"
data-name="${esc(r.draft_name)}"
```

A draft named `x')alert(1)//` breaks out of the double-quoted attribute context. `esc()` escapes `<`, `>`, `&`, `"` — but not single quotes, so `'`-based attribute escape works. Since only this user can name their own drafts it's self-XSS today, but any future multi-user/admin listing of drafts makes it a real stored XSS. Fix: never inline names in JS attributes — use `dataset` lookup or `JSON.encode` + event listeners.

**B2. Modern template: sidebar/main split missing in PDF.**
`build_modern()` uses `<table class="cv-tbl">` with fixed widths (34% / 66%). mPDF handles tables poorly with long content: a long skill value or many sidebar items pushes the main column down rather than flowing beside it. The live preview uses `display:flex` while the PDF uses `<table>` — the two renderers diverge here by construction.

**B3. Preview ≠ PDF output (systemic drift risk).**
There are two complete rendering codebases:
- PHP: `build_classic/modern/professional/minimal()` — CSS-class based
- JS: `buildPreview_classic/modern/...` — inline-style based

Any styling change made in one place silently diverges from the other. There are already small mismatches (e.g., modern template's main-column section titles use `str_replace('"st"','"mn-st"')` hack server-side vs. explicit `mainSt` client-side; spacing differs slightly).

**B4. `save_draft` overwrites without ownership check.**
`UPDATE draft_cvs SET ... WHERE id=?` has no `user_id` filter — the table has no user column at all. All users share one global draft pool. Anyone authenticated sees and can overwrite/delete anyone else's drafts. Fine for a personal toolbox; a data-isolation bug if ever multi-user.

### 🟡 Medium severity

**B5. Legacy database export endpoint (removed).**
The separate `db_export.php` script was removed during the toolbox cleanup. This issue does not apply to the retained CV Maker.

**B6. No CSRF protection on any AJAX/POST endpoint.**
`delete_draft`, `save_draft`, `download` accept any cross-site post. Combined with B4, a crafted page could silently delete drafts.

**B7. Section-toggle state desync after failed load.**
If `get_draft` returns `null` (bad id), `fillForm` is skipped silently but `_draftId` was already... actually no — `_draftId` is set *after* `.then(d => { if (!d) return; ... })`. OK there. However `loadDraftList()` has no error handler at all — a DB error returns non-JSON and the list stays "Loading..." forever.

**B8. `sec_on` values not validated server-side.**
`$_POST['sec_on']` array keys go straight into `buildCvHtml()`. Harmless today since `secOn()` only reads known keys, but unexpected keys pollute the saved JSON.

**B9. Duplicate-draft names allowed.**
Saving twice creates near-identical drafts with no warning; the list becomes cluttered and there is no rename.

**B10. Date fields free-text.**
`exp_start`, `exp_end`, `edu_year`, `cert_date` are plain text. "Jan 2026", "01/2026", "2026-01" all render verbatim — no normalization, no validation, no sorting of experiences chronologically.

**B11. `lang_level` default mismatch on load.**
`<select>` options are Native/Fluent/Intermediate/Basic. If a loaded draft has an empty `lang_level` (e.g., older data), `select.value = ''` silently fails, leaving the select showing "Native" even though the data says empty — preview then shows "Native" too (from `d.lLv[i]||''` fallback in some paths but the select shows Native), causing confusion between what's shown and what's saved.

**B12. No way to reorder entries — new rows always append at the bottom.**
`mkEntry()` only ever does `container.appendChild(d)`, so a newly added Experience or Education entry lands **last**, even though CVs conventionally list the most recent role/degree first. There is no up/down button, no drag-and-drop, and no swap mechanism of any kind — the only workaround is deleting every entry below it and re-typing them (which also destroys their saved order in existing drafts). This affects all seven repeatable sections: skills, experience, education, projects, certifications, languages, extra.

### 🟢 Low severity

**B13. `contact_parts()` ignores its `$separate` param.**
Signature declares `bool $separate=false` but the body never uses it — dead parameter, misleading API.

**B14. Unused variable in `langs_classic()`:**
```php
($lv=trim(av($d,'lang_level')[$i]??'')?(' — '.e($lv)):'')
```
assignment-inside-expression works but is fragile and hard to read.

**B15. Dead code block in JS** (lines ~945–948): a comment-only "patch" explaining that r_* aren't used for preview — should be deleted along with the unused generic `r_*` wrappers if truly unused.

**B16. `up()` runs on every keystroke** with full innerHTML replacement — fine for this size, but focus is lost if the user is typing inside the preview (not currently possible) and large CVs may flicker.

**B17. No `maxlength` anywhere.** A pasted 50KB blob into `summary` will be accepted, saved, and rendered into a broken multi-page PDF.

**B18. Hard-coded colors/fonts duplicated across 8+ places** (CSS strings ×4 templates ×2 languages = drift surface).

---

## Improvement suggestions

### Correctness / robustness

1. **Single-source rendering.** Render the preview by fetching HTML from the server (e.g., `POST action=render` returning the exact `buildCvHtml()` markup) and inject it into the preview iframe. This kills bug class B3 permanently — WYSIWYG becomes literally true.
   - Cheaper alternative: keep dual renderers but add a visual-regression test comparing PDF text extraction against preview text.
2. **Add `user_id` to `draft_cvs`** and scope all queries (`WHERE user_id = ?`). Fixes B4, prerequisite before any multi-user exposure.
3. **CSRF token** on all state-changing endpoints (the app already has sessions — a per-session token in `bootstrap.php` covers everything).
4. **Validate & normalize dates** with a small parser accepting `MM/YYYY`, `Mon YYYY`; store ISO internally, format on render. Enables chronological sorting (currently insertion-order only).
5. **Escape draft names properly**: build the list DOM via `createElement`/`textContent`, or store name in `data-*` read via `el.dataset.name` (still needs quote-safe escaping) — safest is no inline `onclick` at all.
6. **Add `maxlength`** to text inputs and a character counter on summary/responsibilities.

### UX

7. **Reordering** (fixes B12): quickest fix is ▲/▼ buttons in each `.dyn-entry` that call `insertBefore` on the sibling, then `up()`. Better UX: HTML5 drag-and-drop (`draggable` + `dragover` on the container). Also consider an "Insert at top" option or defaulting new Experience/Education rows to the top, since reverse-chronological is the CV convention. Reorder sections themselves too.
8. **Autosave to localStorage** every few seconds — currently a refresh loses everything unsaved.
9. **Export/Import JSON** button so users can back up drafts outside the DB.
10. **Print-friendly @media CSS** + a "Print" button as a fallback when mPDF is unavailable.
11. **Photo support** (upload, crop to circle in modern template) — common CV requirement; needs file handling + size limits.
12. **Template color picker** — the four templates have hard-coded palettes; exposing 2 accent colors would multiply variety cheaply.
13. **ATS-friendly mode**: a single-column, no-color variant optimized for applicant-tracking parsers; also warn when the modern sidebar layout might confuse ATS.
14. **Rename/delete confirmation UX**: replace `confirm()` with an inline modal for consistency with the existing draft modal.

### Code health

15. **Split the file.** `index.php` (controller/routes), `templates/*.php` (the 4 builders), `assets/cv-maker.js` (frontend). 1,100 lines in one file makes every change risky.
16. **Deduplicate section builders** — `skills_classic/exp_classic/edu_classic/...` are structurally identical loops over parallel arrays; a config-driven builder (field map per section) removes ~200 lines.
17. **Replace parallel arrays with row objects.** The `skill_label[]` / `skill_value[]` pairing breaks if JS ever inserts them in different DOM orders; grouping each entry in `entries[skills][] [label] / [value]` is safer and serializes more naturally to JSON.
18. **Remove dead code**: `$separate` param (B13), comment-only patch block (B15), unused CSS classes.
19. **Type-hint and docblock** the builder functions; they're the core of the tool and currently undocumented.

---

## Quick reference — endpoints

| Method | Params | Purpose |
|---|---|---|
| GET | `?action=list_drafts` | List all drafts (id, name, updated_at) |
| GET | `?action=get_draft&id=N` | Fetch one draft's JSON payload |
| POST | `action=save_draft, draft_name, cv_data[, draft_id]` | Create/update draft |
| POST | `action=delete_draft, id` | Delete draft |
| POST | `action=download` + full form | Generate & stream PDF |

## Database schema (`draft_cvs`)

```sql
CREATE TABLE draft_cvs (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  draft_name varchar(255) NOT NULL,
  cv_data longtext NOT NULL CHECK (json_valid(cv_data)),
  created_at datetime DEFAULT current_timestamp(),
  updated_at datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No `user_id` column (see B4).
