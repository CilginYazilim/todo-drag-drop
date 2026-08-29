<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="96">

# Drag & Drop Task Board

A Kanban-style task board built with **PHP · MySQL · vanilla JavaScript**.
**No drag-and-drop library** — the whole engine is ~250 fully commented lines.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Dependencies](https://img.shields.io/badge/npm%20%2F%20composer-none-success?style=flat-square)
![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)

Mouse · Touch · Keyboard · CSRF-protected AJAX

**[cilginyazilim.com](https://cilginyazilim.com)**

[Türkçe](README.md) · **English**

</div>

---

<div align="center">

![Task board screenshot](assets/images/screenshot.png)

<em>Four columns, colour-coded priority stripes, overdue warnings, and struck-through titles for finished work.</em>

</div>

---

## Contents

| | |
|---|---|
| [What it does](#what-it-does) | [How does drag & drop work?](#how-does-drag--drop-work) |
| [Where you can use it](#where-you-can-use-it) | [How is the order stored?](#how-is-the-order-stored) |
| [Installation](#installation) | [API endpoints](#api-endpoints) |
| [What each file does](#what-each-file-does) | [Security](#security) |
| [Database](#database) | [Customisation](#customisation) |
| [Mobile support](#mobile-support) | |

---

## What it does

Task cards you can drag between columns. Wherever you drop a card, **the position is written to the database** — refresh the page and everything stays put.

- **Drag & drop** — reorder within a column or move a card to another column
- **Three input methods** — mouse, touch screen and keyboard all do the same job
- **Fully usable on a phone** — 44px touch targets, columns that snap into place, a full-screen form ([details](#mobile-support))
- **Priority and due dates** — low/medium/high colour coding, overdue cards are flagged
- **Full CRUD** — quick add per column, edit in a modal, delete with confirmation
- **Instant search** — across titles and descriptions, debounced by 300 ms
- **Data-driven board** — column names, colours, order and the "this column means done" flag all come from the database
- **Zero dependencies** — no `npm install`, no `composer install`. Copy, import the SQL, run.

<div align="center">

![Task edit modal](assets/images/screenshot-modal.png)

<em>The edit modal: free text on the left, classification panel on the right.</em>

</div>

---

## Where you can use it

This repository is both a **teaching resource** and a **foundation you can build on**. Drag & drop plus order persistence shows up in far more places than it first appears:

| Domain | How to adapt it |
|--------|-----------------|
| **Project / task tracking** | As-is. Name the columns "Backlog, Sprint, Testing, Live". |
| **Order / shipping tracking** | Columns become order states: "New → Preparing → Shipped → Delivered". Moving a card changes the state. |
| **Support / ticket desk** | "Open → Assigned → Resolved". The `is_done` flag marks closed records. |
| **Hiring pipeline** | Candidate cards: "Applied → Screening → Technical → Offer". |
| **Editorial calendar** | "Idea → Drafting → Editing → Published". The due date becomes the publish date. |
| **Course / homework planner** | A ready-made skeleton for student projects and deadlines. |
| **Warehouse / stock movement** | Product cards moving between shelf or location columns. |

**Its teaching value in code:** beyond the drag engine there are patterns worth reusing — a CSRF-protected AJAX endpoint, single-file `action` routing, a server-side validation layer, optimistic updates with a rollback plan, and order persistence via `sort_order`.

---

## Installation

**Requirements:** PHP 8.0+, MySQL 5.7+ / MariaDB 10.2+ (XAMPP, Laragon or any LAMP stack)

```bash
# 1. Put the files in your web root
cd C:/xampp/htdocs
git clone https://github.com/CilginYazilim/todo-drag-drop.git

# 2. Import the database (it creates the cy_todo schema itself)
mysql -u root -p < todo-drag-drop/cy_todo.sql
```

> Using phpMyAdmin: **Import → Choose file → `cy_todo.sql` → Go**

Then open: **`http://localhost/todo-drag-drop/`**

### Using a different database

Edit the `DB_*` lines in `system/config.php` **or** define environment variables on your server (the preferred route, so the password never lands in the code):

```
DB_HOST=127.0.0.1   DB_NAME=cy_todo   DB_USER=root   DB_PASS=secret
```

### Going to production

Debug output switches itself off — `APP_DEBUG` is not hard-coded to `true`; it inspects the environment. It is on for local hosts (`localhost`, `127.0.0.1`, `*.test`, `*.local`) and off on a real domain. You can always override it with the `APP_DEBUG` environment variable.

---

## What each file does

```
todo-drag-drop/
├── index.php                 ← UI skeleton + modals (the board renders EMPTY)
├── cy_todo.sql               ← Database setup and a sample board
│
├── system/
│   ├── .htaccess             ← Blocks direct HTTP access to config/function files
│   ├── config.php            ← Session, settings, constants, PDO connection
│   ├── function.php          ← Helpers: CSRF, validation, data access, formatting
│   └── ajax.php              ← JSON endpoint: board, add/edit/delete, move
│
└── assets/
    ├── css/
    │   ├── bootstrap.min.css ← Base framework
    │   ├── cilginyazilim.css ← BRAND DESIGN SYSTEM (shared across all CY projects)
    │   └── style.css         ← Page-specific only: board, cards, drag states
    ├── js/
    │   ├── jquery-3.7.0.js
    │   ├── bootstrap.bundle.js
    │   └── board.js          ← THE DRAG ENGINE + all UI logic
    └── images/
        ├── logo.png
        └── screenshot*.png
```

### Division of labour between layers

| Layer | Responsible for | **Not** responsible for |
|-------|-----------------|-------------------------|
| `index.php` | HTML skeleton, modals, embedding the CSRF token | Runs **no** database queries |
| `system/config.php` | Session hardening, constants, PDO connection | Contains no business logic |
| `system/function.php` | Validation, CSRF, reads, formatting | Does **not** route HTTP responses |
| `system/ajax.php` | Request routing, authorisation, writes | Emits **no** HTML, only JSON |
| `assets/js/board.js` | Rendering, dragging, keyboard, AJAX | Its validation is **never trusted** (the server repeats it) |

**Why doesn't `function.php` include `config.php`?** Functions that need the database take the PDO object as a **parameter** (`fetch_board($db)`). That way no new connection is opened per call, and the functions stay testable in isolation. *(Dependency injection)*

**Why is the board rendered by JavaScript instead of PHP?** The board is redrawn constantly as cards are dragged, added and deleted. Writing the same card markup twice — once in PHP, once in JavaScript — means the two will drift apart over time. A single rendering site removes that risk.

### Notable functions

| Function | File | What it does |
|----------|------|--------------|
| `csrf_token()` / `require_csrf()` | `function.php` | Issues a session-bound token; verifies it in constant time via `hash_equals()` |
| `send_security_headers()` | `function.php` | CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy` |
| `validate_task_*()` | `function.php` | Each returns a `[clean value, error]` pair |
| `fetch_board()` | `function.php` | Loads the whole board in **two queries** (no N+1 problem) |
| `present_task()` | `function.php` | Converts a raw row into what the UI expects (overdue logic lives in one place) |
| `column_task_ids()` | `function.php` | Returns a column's ids in order — used for both resequencing and **authorisation** |
| `resequence_column()` | `ajax.php` | Compacts numbering back to 0,1,2… after a delete or move |
| `handle_move()` | `ajax.php` | **The heart of the app** — writes the new order in a single transaction |
| `onPointerDown/Move/Up` | `board.js` | The three stages of the drag engine |
| `persistMove()` | `board.js` | Optimistic update plus board reload on failure |

---

## How does drag & drop work?

### Why no ready-made library?

Using SortableJS or jQuery UI is perfectly reasonable in a real project. But drag & drop tops the list of features people use without understanding how they work. This engine teaches three things: **pointer tracking**, **finding the drop target**, and **persisting the order**. Once you have read it, you will use that library knowingly.

### Why Pointer Events instead of HTML5 `draggable`?

The browsers' built-in `draggable` API **does not work on touch devices** and gives you almost no control over how the dragged element looks. Pointer Events unify mouse, touch and pen into one set of events: write it once, it works on all three.

### Three stages

```
pointerdown → record the starting point (NO dragging yet)
pointermove → once the 5px threshold is crossed, start, find the target, move the card there
pointerup   → write the new order to the server
```

**The card itself is the placeholder.** Instead of creating a separate placeholder element, we move the actual card through the DOM; what you see under your finger is a copy of it (the *ghost*). This way there is nothing left to do on drop — the card is already in the right place.

**How is the insertion point found?** We look at the vertical **midpoint** of every card in the column. If the pointer is above a card's midpoint, the card goes before it; otherwise after it. This simple rule works correctly even when cards have different heights.

### Five critical details

| Detail | Why |
|--------|-----|
| `pointer-events: none` (on the ghost) | With it enabled, `elementFromPoint` would always find the ghost, never the column underneath — and the card could not be dropped anywhere. |
| `touch-action: none` (on the grip only) | Without this line, dragging never starts on touch devices. It is applied **only** to the grip: on the whole card it would make scrolling the column with a finger impossible. |
| Auto-scroll at the edges | Normal scrolling does not work mid-drag. Without this you cannot move a card below the fold of a column taller than the screen. |
| `setPointerCapture()` | When a finger moved quickly outside the card, the browser fired `pointercancel` and killed the drag — the card snapped back mid-journey. Capture routes every event from that pointer to the card and removes the interruption entirely. |
| Lifting the ghost off the finger | A mouse cursor is a few pixels of arrow and hides nothing; **a finger covers the whole card.** Placing the ghost directly under the pointer left mobile users blind to both what they were carrying and where they were dropping it. `TOUCH_GHOST_LIFT` applies only when `pointerType === 'touch'`. |

### Keyboard access

Drag & drop is a mouse/finger-only feature. Not writing a keyboard alternative makes it **completely inaccessible** to some users. Focus a card and:

| Shortcut | Action |
|----------|--------|
| <kbd>Ctrl</kbd> + <kbd>↑</kbd> / <kbd>↓</kbd> | Move the card up/down within its column |
| <kbd>Ctrl</kbd> + <kbd>←</kbd> / <kbd>→</kbd> | Move the card to the previous/next column |
| <kbd>Esc</kbd> | Cancel the drag in progress |

`Ctrl` is required on purpose: with bare arrow keys, a keyboard user simply navigating between cards would rearrange the board by accident.

---

## Mobile support

A Kanban board is **wide by nature**; a phone screen is narrow. That tension is not something you resolve by sticking a "responsive" label on the page — making the board usable on a phone means the layout has to behave differently in several specific places.

### Ask about the input, not the width

Most of the mobile fixes hang off **`@media (hover: none)`**, not a width breakpoint.

```css
@media (hover: none) { … }   /* "there is no pointer that can hover" */
```

Width lies: a shrunken desktop window still has a keyboard and a mouse; a wide tablet has neither. The real condition behind "enlarge the touch targets" and "stop hiding buttons until hover" is not *is the screen narrow* but **is this being used with a finger**.

The same query picks the footer hint: pointer devices are told about <kbd>Ctrl</kbd> + arrows, touch devices about the grip. Printing a keyboard shortcut on a phone is simply false information.

### Touch targets

| Element | Desktop | Touch |
|---------|---------|-------|
| Edit / delete icons | 34 px | **44 px** |
| Drag grip | a small piece of text | **44 px wide, full card height** |

44 px is the floor WCAG 2.5.8 and Apple's HIG agree on. It matters doubly for the grip: it is **the only place a drag can start**, so missing it means the feature does not work at all.

But enlarging the icons created a new problem — the desktop arrangement of two stacked icons down the card's right edge became an 88 px column, and cards looked enormous. The fix was to move the buttons onto **the same row as the badges**, in the card's bottom-right corner: priority and due-date badges are left-aligned, so the right of that row was already empty.

### Columns that snap

```css
.cy-board  { scroll-snap-type: x mandatory; }
.cy-column { scroll-snap-align: start; scroll-snap-stop: always; }
```

Let go and the board aligns to the nearest column instead of resting anywhere. Stopping halfway between two columns made cards hard to read on a phone.

> **Snapping is switched off mid-drag** (`body.cy-dragging .cy-board`). Left on, every `scrollLeft` written by the auto-scroll would be snapped straight back to the nearest column, and the board would judder while you tried to carry a card into the next one.

### The rest

| Problem | Fix |
|---------|-----|
| Reaching the end of the board handed the gesture to the page and triggered pull-to-refresh | `overscroll-behavior: contain` — scrolling stays inside the box |
| Scrolling a long column pushed its header off screen; you lost track of which column you were in | `position: sticky` header. This required changing `overflow: hidden` to `clip` on `.cy-column`: `hidden` makes the box a scroll container, which silently kills stickiness |
| The modal stayed narrow on a phone, and the virtual keyboard pushed its title off screen | `modal-fullscreen-sm-down` |
| The full-screen modal left a grey strip at the bottom | `height: 100%` on `.modal-dialog` does not flow through the intervening `<form>`; it is now set on the form too |
| Title and description filled only the left half of the modal | `align-items: flex-start` changes meaning in a column layout and shrank children to their content → `stretch` |
| Tapping the title field made iOS zoom in and stay there | Font size `1rem` (=16px); Safari auto-zooms fields below 16px |
| An empty search result showed empty columns, which reads as "my tasks are gone" | An explicit notice above the board |
| The virtual keyboard kept covering half the screen after searching | <kbd>Enter</kbd> → search and `blur()`; <kbd>Esc</kbd> → clear |
| The moment a drag started was visually ambiguous under a finger | `navigator.vibrate(10)` — 10 ms on devices that support it, skipped silently elsewhere |
| On notched phones the footer sat under the home indicator | `viewport-fit=cover` + `env(safe-area-inset-*)`, wrapped in `max()` |

> **Deliberately not done:** `maximum-scale=1` / `user-scalable=no`. Disabling zoom is an accessibility failure that makes the page unusable for people with low vision — far too high a price for a tidier-looking mobile layout.

---

## How is the order stored?

In the `tasks.sort_order` column; each column numbers its own cards starting from 0.

**What does the client send?** The id of the moved card, the column it was dropped in, and **the new order of every card in that column**. Reporting just one card's position would force the server to guess how to shift the rest. The browser already holds the correct order on screen; sending it verbatim is both simpler and makes screen/database divergence impossible.

**Why not fractional ranks (squeezing in at 0.5)?** The fractional approach is faster — one row update — but precision runs out over time and a renumbering pass becomes necessary anyway. For a few hundred cards per column, rewriting integers in a single transaction is simple, fast enough and **always consistent**.

**Optimistic update.** The card moves on screen without waiting for the server's confirmation — waiting would cause a visible freeze on every drop. In return we make a promise: if the request fails, the board is reloaded from the server and the screen is brought back to the truth.

> *Using an optimistic update without a rollback plan is lying to the user.*

---

## API endpoints

Everything goes to **`POST system/ajax.php`**, everything requires `csrf_token`, everything returns JSON.

| `action` | Extra parameters | Returns |
|----------|------------------|---------|
| `board` | `search` *(optional)* | `columns[]`, `total` |
| `add` | `title`, `column_id`, `description`, `priority`, `due_date` | `id` |
| `edit` | `task_id` + the above | `id` |
| `fetch` | `id` | Every field of a single task |
| `delete` | `id` | `id` |
| `move` | `task_id`, `column_id`, `order[]` | `id`, `column_id` |

**The response shape is always the same**, so the client needs only one way to display errors:

```json
{ "success": true,  "type": "success", "description": "Task added.", "id": 13 }
{ "success": false, "type": "danger",  "description": "Please fix the errors in the form.",
  "errors": { "title": "The task title must be at least 3 characters." } }
```

**HTTP codes in use:** `200` success · `400` bad request · `403` invalid CSRF · `404` not found · `405` not a POST · `422` validation error · `500` server error

> **Why 403 and not 419?** Some frameworks use `419` for CSRF failures. But 419 is not an IANA-registered code: **Apache does not recognise it and silently rewrites the status line to `500 Internal Server Error`.** The result is that a rejected request looks like a crashed server. This was measured and confirmed in this repository, then replaced with `403 Forbidden`.

**Why a single file?** Using one entry point instead of a file per operation keeps the security checks (CSRF, POST-only, error handling) **in one place**. There is no way to forget one of them in one file.

---

## Database

**Two tables:** `task_columns` (columns) and `tasks` (cards).

```
task_columns                          tasks
├── id                                ├── id
├── title       "In Progress"         ├── column_id ──────┐ FK, ON DELETE CASCADE
├── accent      "#0b5cb5"             ├── title           │
├── sort_order  left-to-right order   ├── description     │
└── is_done     "this column = done"  ├── priority   ENUM(dusuk,orta,yuksek)
                        ▲             ├── due_date        │
                        └─────────────┤ sort_order   order WITHIN the column
                                      ├── created_at      │
                                      └── updated_at      │
```

**Why are columns a separate table?** Storing "To Do / In Progress / Done" as an ENUM on the task looks simpler at first. But then adding a column requires `ALTER TABLE`, and there is nowhere to keep a column's order, colour or heading. A separate table makes the board **shapeable by data**.

**The `is_done` flag** removes the fragility of deciding by a column's *name* ("is it called Done?") — nothing breaks when a user renames it.

**The composite index `(column_id, sort_order)`** is precisely the query that renders the board: `WHERE column_id = ? ORDER BY sort_order`. Keeping both columns in one index, in that order, lets MySQL satisfy the filter and the sort from the index alone.

**Why `task_columns` and not `columns`?** `columns` is a reserved word in MySQL; it would force backticks in every query and eventually get forgotten, producing an error.

---

## Security

| Measure | How it is implemented |
|---------|-----------------------|
| **CSRF** | Every POST is verified against a session-bound 32-byte token; compared with `hash_equals()`, closed to timing attacks |
| **SQL injection** | Prepared statements without exception, `PDO::ATTR_EMULATE_PREPARES = false` |
| **XSS** | `htmlspecialchars()` on the server, **always** `.text()` on the client — never `.html()` |
| **POST only** | Stops a simple tag like `<img src="ajax.php?action=delete&id=5">` from deleting records |
| **Session cookie** | `HttpOnly` (unreadable to JS) + `SameSite=Lax` (not attached to cross-site POSTs) + `Secure` over HTTPS |
| **Security headers** | CSP, `X-Frame-Options: DENY` (clickjacking), `nosniff`, `Referrer-Policy` |
| **Allow-listing** | Priority is validated with "only these are permitted", not "these are forbidden" |
| **Move authorisation** | The `order[]` list is restricted to **the target column's real members + the moved card** |
| **Input limits** | Title, description, search term and cards-per-column all have upper bounds |
| **Transaction** | The entire move is one transaction; a half-written ordering is worse than none |
| **File access** | `system/.htaccess` denies direct HTTP access to everything except `ajax.php` |
| **Error hiding** | `APP_DEBUG` turns itself off by environment; table and query names never leak in production |
| **Foreign key** | Deleting a column deletes its tasks (`ON DELETE CASCADE`); no orphan rows accumulate |

### Why move authorisation gets its own heading

`handle_move()` runs `UPDATE tasks SET column_id = :target` for every id in the list. If that list were not validated, ids belonging to **another** column of the board could be placed into `order[]`, dragging those cards into the target column too — the user would watch their cards move without touching anything.

The rule is clear: a target column's new list may only consist of **cards already in that column** and **the card being dragged in**. Any id outside that set is silently dropped *(the board may have changed in another tab; rejecting the whole request would surprise the user more)*.

> **Note:** this example has **no authentication** — anyone who opens the board can edit every card. For multi-user use you need to add login and an ownership check ("does this card belong to this user?").

---

## Customisation

### Adding a new column

**No code changes needed**, a single SQL statement is enough:

```sql
INSERT INTO task_columns (title, accent, sort_order, is_done)
VALUES ('On Hold', '#7c3aed', 4, 0);
```

The colour appears automatically on the board, in the form and on the card edges.

### Adding a new priority

Two places to update:

```sql
ALTER TABLE tasks MODIFY priority ENUM('dusuk','orta','yuksek','kritik') NOT NULL DEFAULT 'orta';
```

```php
// system/config.php — the form, validation and badges all read from this array
define('TASK_PRIORITIES', [
    'dusuk'  => 'Low',
    'orta'   => 'Medium',
    'yuksek' => 'High',
    'kritik' => 'Critical',   // ← new
]);
```

Then add a `.cy-priority--kritik` colour rule to `assets/css/style.css`.

### Tunable constants

In `system/config.php`: `TASK_TITLE_MIN` / `TASK_TITLE_MAX` · `TASK_DESC_MAX` · `SEARCH_MAX` · `MAX_TASKS_PER_COLUMN` · `APP_DEBUG`

> **Note on language:** the interface and the code comments are in Turkish. Priority values are stored ASCII-only (`dusuk`, `orta`, `yuksek`) precisely so they are safe to use in URLs, JSON and CSS class names — translating the labels is a matter of editing the `TASK_PRIORITIES` array.

---

## License

**MIT** — download and use it however you like, commercial projects included.

To contribute, fork the repository and open a pull request.

<div align="center">

---

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="64">

**[Çılgın Yazılım](https://cilginyazilim.com)**

Open source, educational PHP examples

**[Code examples &amp; library](https://cilginyazilim.com/kutuphane)** · [This project's page](https://cilginyazilim.com/kutuphane/surukle-birak-gorev-panosu)

[cilginyazilim.com](https://cilginyazilim.com) · [github.com/CilginYazilim](https://github.com/CilginYazilim)

</div>
