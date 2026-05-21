# Equine Event Manager — Brand Guide

This is the canonical color, typography, and component reference for the plugin. Where this document and `BRAND_GUIDE.png` diverge, **this document wins** (the PNG is a marketing-style overview; some values have been refined against the live mockups).

The mockups (`*.html`) are the visual ground truth. If anything in this guide contradicts a mockup, the mockup wins — flag the discrepancy so we can reconcile.

---

## Brand personality

Modern · Technical · Trustworthy · Fast · Clean · Premium SaaS.
Equine-first without feeling western. The product should feel fast, trustworthy, and effortless to use — just like the identity itself.

---

## Color tokens

### Primary

| Token | Hex | Use |
|---|---|---|
| Equine Navy | `#031B4E` | Headings, primary text, dark surfaces, primary action buttons |
| Electric Blue | `#1668F2` | Links, active nav, info accents, primary form action emphasis |
| Aqua Teal | `#26D0B5` | Success states, accents, "Print View" buttons, toast left-border |

### Neutrals

| Token | Hex | Use |
|---|---|---|
| Dark Surface | `#071833` | Dark backgrounds (logo container, email header, footer) |
| Background | `#F7F9FC` | Page background, light table row hover |
| Surface | `#FFFFFF` | Cards, inputs, primary surfaces |
| Border | `#D9E2F2` | Field borders, dividers, card outlines |
| Text Secondary | `#6B7A99` | Subtext, hints, muted labels |
| Subtle Muted | `#8c8f94` | Table column headers, very low-emphasis text |

### State

| Token | Hex (bg / border / text) | Use |
|---|---|---|
| Warning amber | `#FFFBEB` / `#fde68a` / `#b45309` | Special-request notes, unpaid banners |
| Error red | `#fef2f2` / `#fecaca` / `#b91c1c` | Destructive buttons, error states, validation errors |
| Success teal | `#F0FDFA` / `#bbf7d0` / `#0d9488` | Confirmation chips, paid badges |
| Info blue | `#EEF4FF` / `#c0d8ff` / `#1668F2` | Section icon backgrounds, info chips, tag-select chips |

### Gradients

**Primary Gradient (135°)** — `#26D0B5` → `#1DA1F2` → `#1668F2`
**Icon Gradient (180°)** — `#29D7BC` → `#1DA1F2` → `#1668F2`

Use sparingly: marketing surfaces, app icon, splash. **Do not use gradients on functional UI** (buttons, badges, table rows).

---

## Typography

### Fonts

- **Space Grotesk** — display font. Use for the logo, page titles, section titles, rail/card titles, large marketing headings. Weights: 400, 500, 600, 700.
- **IBM Plex Sans** — UI font. Use for everything else: body text, form inputs, table cells, button labels, navigation. Weights: 300, 400, 500, 600.

Both available from Google Fonts:
```html
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
```

### Type scale (aligned to mockups)

| Style | Font | Weight | Desktop size / line-height | Use |
|---|---|---|---|---|
| Display | Space Grotesk | 700 | 48px / 56px | Marketing only — not used in admin |
| H1 / Page title | Space Grotesk | 700 | 22px / 1.3 | Top of every admin page |
| H2 / Card title | Space Grotesk | 700 | 16px / 1.3 | Section headers within cards |
| H3 / Subsection | Space Grotesk | 700 | 14px / 1.4 | Subsection labels |
| Body | IBM Plex Sans | 400 | 14px / 1.5 | Default body text |
| Body strong | IBM Plex Sans | 600 | 14px / 1.5 | Field values, emphasized inline text |
| Field label | IBM Plex Sans | 600 | 13px / 1.4 | Form field labels |
| Hint / sub | IBM Plex Sans | 400 | 12px / 1.6 | Help text under fields, captions |
| Tiny caps | IBM Plex Sans | 700 | 10.5px / 1.4, letter-spacing .09em, uppercase | Column headers, "labels above values" |

**Note on marketing display sizes (48px/36px/28px):** these come from the brand guide PNG and are appropriate for marketing pages and email headers, **not** the admin UI. Admin H1 is 22px because the mockup pages live inside the WP admin shell and need to feel native.

---

## UI fundamentals

### Corner radius

- Form inputs, buttons, chips: **4px**
- Cards, modals, larger surfaces: **6px**
- Avatar / circular elements: 50%

**Do not use 12px** (despite the brand guide PNG suggestion) — it feels out of place next to native WP admin elements.

### Borders

- Standard: `1.5px solid #D9E2F2` on form inputs
- Card outline: `1px solid #c3c4c7` (matches WP admin convention)
- Focus state: `1.5px solid #1668F2` + `box-shadow: 0 0 0 2px rgba(22,104,242,.1)`

### Elevation / shadow

Subtle. Cards generally use a hairline border instead of a shadow. When shadow is used:
- Dropdowns: `0 6px 16px rgba(3,27,78,.08)`
- Toasts: `0 8px 24px rgba(3,27,78,.12)`

Never use heavy drop shadows. Never use glows or inner shadows.

### Spacing scale

The mockups loosely follow an 8/12/16/20/24 px scale. Common values:
- Field row vertical padding: `12px`
- Card body padding: `16px 18px`
- Card-to-card gap: `10px`
- Page padding: `12px 20px`

---

## Components

### Buttons

| Style | Background | Text | Border | Use |
|---|---|---|---|---|
| **Primary (Navy)** | `#031B4E` | `#fff` | none | Default primary action (Save, Update, Publish) |
| **Primary (Electric Blue)** | `#1668F2` | `#fff` | none | Cross-page navigation actions (+ New Reservation, Create Order) |
| **Secondary** | `#fff` | `#031B4E` | `1.5px solid #D9E2F2` | Save Draft, Cancel, neutral actions |
| **Tertiary / Text link** | transparent | `#1668F2` | none | Inline links, "View →" affordances |
| **Teal accent** | `#26D0B5` | `#fff` | none | Print View button (rare, attention-getting accent) |
| **Danger** | `#fff` | `#b91c1c` | `1.5px solid #fecaca` | Move to Trash, destructive small-button |

**Decision rule:** within a single page, the most important action is Navy. Electric Blue is reserved for navigation-style "go to a new place" actions that aren't form submissions.

### Form fields

- Padding: `8px 11px`
- Border: `1.5px solid #D9E2F2`
- Border radius: `4px`
- Font: IBM Plex Sans 13.5px, color `#031B4E`
- Placeholder color: `#B0BDD4`
- Focus: border `#1668F2`, soft blue box-shadow

### Cards

- Background: `#fff`
- Border: `1px solid #c3c4c7`
- Radius: `4px` (admin cards; mockups use this not 6px)
- Header padding: `13px 18px`
- Body padding: `16px 18px`

### Toggle switch

- Track: `36×20px`, rounded 10px
- On: `#1668F2` track, thumb slides right
- Off: `#c3c4c7` track, thumb slides left
- Thumb: `14×14px` white with subtle shadow

### Section icons (inside section headers)

- Container: `28×28px`, `4px` radius
- Icon inside: `14×14px`, outline style, 2px stroke
- Color pairings (light bg + matching stroke):
  - Blue → bg `#EEF4FF`, color `#1668F2`
  - Green → bg `#F0FDF4`, color `#16a34a`
  - Purple → bg `#F5F3FF`, color `#7c3aed`
  - Orange → bg `#FFF7ED`, color `#c2410c`
  - Teal → bg `#F0FDFA`, color `#0d9488`
  - Pink → bg `#FFF1F2`, color `#be185d`
  - Navy → bg `#EEF4FF`, color `#031B4E`

### Tag chip (multi-select)

- Background: `#EEF4FF`, text `#1668F2`, border `1px solid #c0d8ff`
- Radius: `3px`, padding `2px 4px 2px 8px`
- Remove ✕ button: 16×16, hover bg `#c0d8ff`

---

## Iconography

**Style:** Outline, rounded geometry, consistent 2px stroke weight. Lucide / Feather style.

**Do not use:**
- WordPress Dashicons
- Filled / solid icons
- Two-tone or color-fill icons
- Emoji as iconography (some uses are intentional — checkmarks, attachment paperclip — but don't replace UI icons)

---

## Logo usage

The logo is the horse mark + "EQUINE EVENT MANAGER" wordmark. Mark alone is acceptable when space is constrained (favicon, app icon, sidebar collapsed state).

**Do:**
- Use on white or dark navy backgrounds
- Maintain clear space around the logo equal to the "E" cap-height
- Use the gradient app icon variant where shown
- Use the monochrome variant on busy backgrounds

**Don't:**
- Add shadows, glows, or effects
- Stretch or alter proportions
- Use on busy or low-contrast backgrounds
- Recolor the horse mark

---

## Admin shell decision

**The plugin styles its own admin pages, but lives inside the native WordPress admin shell.** Sidebar, top bar, and `wp-admin` chrome are unchanged. This is a deliberate choice:

- Familiar to WP admins
- No fights with future WP admin redesigns
- Plugin still feels like a first-class WP citizen

The brand guide's "Application Example" dashboard (full custom shell with Equine logo sidebar) is **marketing/aspirational**, not a build target. If we ever do a full takeover, it'll be a separate phase.

What we *do* control inside the WP shell:
- Page content area: brand fonts, colors, components, layout
- Breadcrumb area at top of each page with our plugin logo
- The right rail on Edit Reservation
- All form controls, buttons, tables, modals

---

## DO

- Use approved colors and fonts
- Maintain clear visual hierarchy
- Keep layouts clean and consistent
- Use gradients sparingly (marketing only)
- Prefer thin hairline borders over heavy shadows
- Use Space Grotesk for any title/heading
- Use Aqua Teal as an accent that draws the eye

## DON'T

- Use unapproved colors (especially WP default `#2271b1` blue)
- Add effects, glows, or heavy shadows
- Distort or rotate the logo
- Overcrowd the layout
- Mix icon styles within one screen
- Use the brand gradient on functional UI elements (buttons, status badges)
