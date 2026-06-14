# ENTERPRISE SAAS DESIGN SPECIFICATION
## Based on Sneat Dashboard UI System — Complete Design Language Reference

**Version:** 1.0  
**Document Type:** Enterprise Design System Specification  
**Scope:** Full SaaS application — all pages, components, states, and patterns  
**RTL Support:** Full (Arabic/Hebrew/Persian markets)

---

# PART 1 — GLOBAL DESIGN SYSTEM

## 1.1 Color Palette

### Primary Brand Colors

| Token | Hex | Usage |
|---|---|---|
| `--color-primary` | `#696CFF` | Primary buttons, active states, links, accent |
| `--color-primary-dark` | `#5F61E6` | Hover on primary button |
| `--color-primary-light` | `#E7E7FF` | Primary tinted backgrounds, badges |
| `--color-primary-muted` | `#F0F0FF` | Subtle highlights, card accents |

### Semantic / Status Colors

| Token | Hex | Usage |
|---|---|---|
| `--color-success` | `#71DD37` | Active status, positive deltas, success badges |
| `--color-success-dark` | `#59AB2C` | Hover on success elements |
| `--color-success-light` | `#E8F8DE` | Success badge background |
| `--color-warning` | `#FFAB00` | Pending status, warning alerts |
| `--color-warning-light` | `#FFF3CD` | Warning badge background |
| `--color-danger` | `#FF3E1D` | Delete buttons, error states, negative deltas |
| `--color-danger-light` | `#FFE0DB` | Error badge background |
| `--color-info` | `#03C3EC` | Info states, secondary highlights |
| `--color-info-light` | `#D7F4FC` | Info badge background |

### Neutral / Surface Colors

| Token | Hex | Usage |
|---|---|---|
| `--color-bg-body` | `#F4F5FB` | Page background (light lavender-gray) |
| `--color-bg-card` | `#FFFFFF` | Card and panel backgrounds |
| `--color-bg-sidebar` | `#FFFFFF` | Sidebar background |
| `--color-border` | `#DBDDE0` | Default borders, dividers |
| `--color-border-light` | `#F0F1F3` | Subtle dividers, table row separators |
| `--color-text-primary` | `#434A5C` | Main body text, table data |
| `--color-text-secondary` | `#8A8FA3` | Labels, secondary info, timestamps |
| `--color-text-tertiary` | `#B4B9C9` | Placeholder text, disabled text |
| `--color-text-heading` | `#2E2F45` | Page titles, section headers |

### Avatar / Icon Background Palette (Fallback Initials)

When no profile photo is available, use these tinted backgrounds with white initials:

| Color | Hex | Initials Text |
|---|---|---|
| Indigo | `#696CFF` | `#FFFFFF` |
| Teal | `#03C3EC` | `#FFFFFF` |
| Amber | `#FFAB00` | `#FFFFFF` |
| Sage | `#71DD37` | `#FFFFFF` |
| Coral | `#FF3E1D` | `#FFFFFF` |

Assign by hashing the user's display name to ensure consistency per user.

---

## 1.2 Typography

### Font Stack

```
Primary:   'Public Sans', system-ui, -apple-system, sans-serif
Monospace: 'JetBrains Mono', 'Fira Code', Consolas, monospace
```

### Type Scale

| Role | Size | Weight | Line Height | Color Token | Usage |
|---|---|---|---|---|---|
| `display-xl` | 28px | 700 | 1.25 | `--color-text-heading` | Page hero figures, KPI numbers |
| `display-lg` | 22px | 700 | 1.3 | `--color-text-heading` | Card primary stats |
| `display-md` | 18px | 600 | 1.35 | `--color-text-heading` | Section titles, modal titles |
| `heading-lg` | 16px | 600 | 1.4 | `--color-text-heading` | Card titles, table headings |
| `heading-sm` | 14px | 600 | 1.4 | `--color-text-heading` | Sidebar labels (parent), form section headers |
| `body-md` | 15px | 400 | 1.5 | `--color-text-primary` | Table cell data, form inputs |
| `body-sm` | 13px | 400 | 1.5 | `--color-text-primary` | Secondary table info, descriptions |
| `label` | 12px | 500 | 1.4 | `--color-text-secondary` | Input labels, column headers, metadata |
| `caption` | 11px | 400 | 1.4 | `--color-text-tertiary` | Timestamps, footnotes |
| `badge` | 12px | 600 | 1 | (per badge color) | Status badges |
| `button-md` | 15px | 500 | 1 | (per button type) | Standard buttons |
| `button-sm` | 13px | 500 | 1 | (per button type) | Compact/icon buttons |

### RTL Typography Rules

- Font size and weights remain identical in RTL mode
- Letter spacing (`letter-spacing`) should be set to `0` for Arabic/Hebrew text
- Line height should increase slightly to `1.6` for Arabic body text due to script height
- Do not use `font-feature-settings: "kern"` for Arabic text

---

## 1.3 Spacing Scale

Base unit: `4px`

| Token | Value | Usage |
|---|---|---|
| `--space-1` | 4px | Minimal gap, icon-to-label |
| `--space-2` | 8px | Tight component padding, badge padding |
| `--space-3` | 12px | Button vertical padding, form row gaps |
| `--space-4` | 16px | Standard padding, card inner spacing |
| `--space-5` | 20px | Card padding (compact cards) |
| `--space-6` | 24px | Card padding (standard), section gaps |
| `--space-8` | 32px | Large section padding |
| `--space-10` | 40px | Page vertical padding |
| `--space-12` | 48px | Section-to-section spacing |
| `--space-16` | 64px | Major layout divisions |

---

## 1.4 Border Radius

| Token | Value | Usage |
|---|---|---|
| `--radius-sm` | 4px | Badges, pills, input addons |
| `--radius-md` | 6px | Buttons, inputs, dropdowns |
| `--radius-lg` | 8px | Cards, modals, panels |
| `--radius-xl` | 12px | Large cards, drawer panels |
| `--radius-full` | 9999px | Avatar circles, toggle switches, circular icons |

---

## 1.5 Shadows

| Token | Value | Usage |
|---|---|---|
| `--shadow-xs` | `0 1px 2px rgba(67,74,92,0.06)` | Subtle card lift |
| `--shadow-sm` | `0 2px 6px rgba(67,74,92,0.08)` | Standard card |
| `--shadow-md` | `0 4px 16px rgba(67,74,92,0.12)` | Dropdowns, popovers |
| `--shadow-lg` | `0 8px 32px rgba(67,74,92,0.16)` | Modals, drawers |
| `--shadow-xl` | `0 16px 48px rgba(67,74,92,0.20)` | Large overlays |
| `--shadow-primary` | `0 4px 12px rgba(105,108,255,0.30)` | Primary button hover glow |

---

## 1.6 Icon System

### Icon Library: Boxicons (bx, bxs variants)

| Context | Size | Stroke / Style |
|---|---|---|
| Sidebar nav icons | 20px | Line (bx-) |
| Table action icons | 18px | Line (bx-) |
| Header toolbar icons | 20px | Line (bx-) |
| KPI card icons | 28px | Solid colored (bxs-), inside tinted circle 48×48px |
| Badge / status icons | 14px | Solid (bxs-) |
| Button icons | 16px | Line (bx-) |
| Empty state illustration | 80–120px | Solid (bxs-), muted color |
| Notification icons | 16px | Solid (bxs-) |

### KPI Icon Container

Circular container: `width: 48px; height: 48px; border-radius: 50%`  
Background: tinted variant of the status color at 15% opacity  
Icon color: the full status color

---

## 1.7 Grid System

### Page Grid

- Content area uses a **12-column CSS Grid**
- Gutter: `24px`
- Column padding on container: `24px` left/right

### Breakpoints

| Name | Min Width | Behavior |
|---|---|---|
| `xs` | 0px | Single column, sidebar hidden |
| `sm` | 576px | Single column, sidebar collapsible |
| `md` | 768px | Two columns possible |
| `lg` | 992px | Sidebar visible, content 12-col |
| `xl` | 1200px | Full layout with sidebar + content |
| `xxl` | 1400px | Wide content, max-width applied |

### Dashboard Grid Zones

```
[Sidebar 260px fixed] [Content Area: fluid]
  Content Area Inner:
  ┌──────────────────────────────────────────┐
  │  [Header — full width, 64px tall]        │
  │  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐   │
  │  │KPI 1 │ │KPI 2 │ │KPI 3 │ │KPI 4 │   │
  │  └──────┘ └──────┘ └──────┘ └──────┘   │
  │  ┌──────────────┐ ┌─────────────────┐   │
  │  │  Wide Widget │ │ Narrow Widget   │   │
  │  └──────────────┘ └─────────────────┘   │
  │  ┌──────┐ ┌──────┐ ┌──────────────┐    │
  │  │Block │ │Block │ │   Wide Block │    │
  │  └──────┘ └──────┘ └──────────────┘    │
  └──────────────────────────────────────────┘
```

---

## 1.8 Layout Rules

- Sidebar is always fixed-position; content scrolls independently
- Page content has a top padding of `24px` and horizontal padding of `24px`
- Cards never exceed `100%` of their grid column width
- Data tables always sit inside a full-width card container
- Filter bars and search inputs sit above the data table, inside the same card
- Action buttons (Add New, Export) sit in the same row as filter controls, right-aligned
- Page-level titles sit in the header area, not repeated in content body
- Scrollbars are styled: thin (4px), rounded, color `--color-border`

---

## 1.9 RTL Layout Rules

- `dir="rtl"` is set on the `<html>` element when RTL locale is active
- Sidebar moves to the **right** side of the viewport
- All horizontal padding/margin tokens remain the same value but flip sides automatically via logical CSS properties (`padding-inline-start`, `margin-inline-end`, etc.)
- Icons that indicate direction (arrows, chevrons) must be mirrored: use `transform: scaleX(-1)` or RTL-specific icon variants
- Text alignment in tables: right-align in RTL
- Charts that show time-series (left→right) must also mirror their x-axis in RTL
- The breadcrumb separator chevron flips in RTL
- Form labels align right in RTL; input text aligns right
- Sidebar navigation item icon appears on the right side of the label in RTL

---

# PART 2 — NAVIGATION SYSTEM

## 2.1 Sidebar Design

### Structure

```
┌─────────────────────────┐
│  [Logo]     Sneat  [S]  │  ← 64px tall branding header
├─────────────────────────┤
│  [icon] لوحات القيادة > │  ← Parent menu item (Arabic example)
│    • تحليلات            │  ← Active child (dot indicator)
│    • إدارة علاقات العملاء│
│  [icon] التخطيطات     > │
│  [icon] الصفحات الأمامية>│
│  ...                    │
├─────────────────────────┤
│  ─ المكونات ─           │  ← Section divider label
│  [icon] البطاقات  [6]   │  ← Badge showing item count
│  [icon] واجهة المستخدم  │
└─────────────────────────┘
```

### Sidebar Dimensions

| Property | Value |
|---|---|
| Width (expanded) | 260px |
| Width (collapsed/icon-only) | 72px |
| Item height | 42px |
| Section label height | 32px |
| Logo area height | 64px |
| Icon size | 20px |
| Icon-to-label gap | 10px |
| Item left padding | 20px |
| Nested item left indent | 44px |

### Sidebar Colors

| Element | Color |
|---|---|
| Background | `#FFFFFF` |
| Active item background | `rgba(105,108,255,0.08)` |
| Active item text | `#696CFF` |
| Active item icon | `#696CFF` |
| Active child dot | `#696CFF` (filled circle, 6px) |
| Inactive item text | `#434A5C` |
| Inactive item icon | `#8A8FA3` |
| Hover item background | `rgba(67,74,92,0.04)` |
| Section label text | `#8A8FA3`, uppercase, `letter-spacing: 0.08em`, 11px |
| Divider line (section) | `1px solid #F0F1F3` |
| Right border (active indicator) | None — background fill used instead |
| Scrollbar | 4px, `--color-border`, rounded |

### Sidebar States

**Expanded (default):** Full label text visible, chevron icons for expandable items  
**Collapsed:** Icons only, 72px wide, hovering an item shows a tooltip with the label  
**Mobile:** Hidden off-canvas by default; toggled by hamburger icon in header; slides in as an overlay with a backdrop overlay `rgba(0,0,0,0.5)`

### Nested Menu Behavior

- Parent item shows a right-facing chevron (›) that rotates 90° when open
- Open parent items expand inline (no separate flyout)
- Child items are indented 44px from left
- Active child item shows a filled dot (6px circle) before the label
- Inactive child items show an empty/outline dot (6px circle)
- Maximum nesting: 2 levels

### Section Dividers

- A text label acts as a section header (e.g., "المكونات")
- Styled in uppercase, 11px, `--color-text-secondary`
- 8px top padding, 4px bottom padding above first item in section
- No line separator — typography creates the visual break

### Notification Badge on Sidebar Item

- Small pill badge, right-aligned in sidebar item row
- Background: `--color-primary` or `--color-warning`
- Text: white, 10px, bold
- Min-width: 18px, height: 18px, border-radius: 9px

---

## 2.2 Header Layout

### Dimensions

| Property | Value |
|---|---|
| Height | 64px |
| Background | `#FFFFFF` |
| Bottom border | `1px solid #F0F1F3` |
| Left padding | 24px |
| Right padding | 24px |
| Box shadow | none (border suffices) |

### Header Zones (LTR)

```
[Hamburger] [Breadcrumb / Page Title]    [Search] [Icons: notification, grid, theme, globe] [Avatar]
```

### Header Zones (RTL)

```
[Avatar] [Icons] [Search]    [Breadcrumb / Page Title] [Hamburger]
```

### Search Bar

- Trigger: Keyboard shortcut hint displayed `[CTRL + K]`
- Appearance: Pill-shaped input, 40px tall, `border-radius: 20px`
- Background: `#F4F5FB`
- Border: none in resting state, `1px solid #696CFF` on focus
- Width (resting): 240px; expands to 360px on focus
- Icon: search icon (18px) on the right side in LTR (left in RTL)
- Placeholder color: `--color-text-tertiary`
- On Ctrl+K: opens a command palette modal (full search experience)

### Header Action Icons

Icons in top toolbar area (left of avatar):
- Notification bell with badge dot (red `#FF3E1D`, 8px, no number unless overflowing)
- Grid/apps icon (waffle menu for app switching)
- Theme toggle (sun/moon icon)
- Globe icon (language / locale switcher)
- Each icon: 20px, color `--color-text-secondary`, hover color `--color-primary`
- Click area: 36×36px, border-radius: 6px, hover background: `rgba(105,108,255,0.08)`

### User Menu (Avatar)

- Avatar: 36×36px circle, right side of header
- On click: dropdown menu appears below
- Dropdown items: Profile, Settings, Help, Divider, Logout
- Dropdown width: 200px
- Dropdown shadow: `--shadow-md`

---

# PART 3 — DASHBOARD PAGES

## 3.1 KPI Cards (Top Row Statistics)

### Card Container

| Property | Value |
|---|---|
| Background | `#FFFFFF` |
| Border radius | 8px |
| Shadow | `--shadow-sm` |
| Padding | 20px 24px |
| Height (standard) | 100px minimum |
| Min-width per card | Fills 1 of 4 equal columns in a row |

### Card Anatomy

```
[Icon Circle 48px]   [Label — 13px, secondary]
                     [Delta badge] [Primary Number — 28px bold]
                     [Sub-label — 12px, muted]
```

### Delta Badge

- Positive delta: text `(XX%+)`, color `--color-success`, size 12px
- Negative delta: text `(XX%-)`, color `--color-danger`, size 12px
- Placed to the left of the primary number
- No background fill — text-only indicator

### KPI Icon Colors (per card type)

| Card Type | Icon | Background |
|---|---|---|
| Pending Users | Orange user icon | `rgba(255,171,0,0.15)` |
| Active Users | Green user icon | `rgba(113,221,55,0.15)` |
| Paid Users | Pink/red user icon | `rgba(255,62,29,0.15)` |
| Session / Total | Blue user icon | `rgba(105,108,255,0.15)` |

---

## 3.2 Analytics / Chart Cards

### Revenue Chart Card

- Width: spans 2 columns (8/12 grid)
- Shows grouped bar chart (2 series: year comparison)
- Legend at top-right: colored dots with year labels
- Chart bar colors: Series A `#696CFF`, Series B `#03C3EC`
- Grid lines: horizontal only, `1px dashed #F0F1F3`
- Axis labels: 11px, `--color-text-secondary`
- Y-axis: left side (LTR), right side (RTL)

### Company Growth (Donut) Card

- Circular progress indicator, 120px diameter
- Primary percentage text centered: 24px, bold, `--color-text-heading`
- Sub-label: 12px, `--color-text-secondary`
- Track color: `#F0F1F3`; Progress color: `--color-primary`
- Year selector: dropdown inline above chart
- Below chart: two comparison rows showing year labels and values

### Profile Report Card

- Area/line chart, single series
- Line color: `#FFAB00` (amber)
- Area fill: gradient from `rgba(255,171,0,0.15)` to `rgba(255,171,0,0)`
- Badge overlay on chart: `YEAR 2022` pill — amber background, white text
- Below chart: percentage delta and total figure

### Order Statistics Card

- Circular donut with centered figure
- Multiple segments by category
- Legend listed below with color swatches

---

## 3.3 Summary / Action Widgets

### Congratulations Banner Card

- Background: gradient or illustration background
- Headline: bold, 18px, primary color
- Body text: 14px, secondary
- CTA button: secondary outlined style, `border-radius: 6px`
- Illustration: SVG graphic, right-aligned

### Transaction Row Items

- Icon (payment logo): 32×32px circle
- Label + sub-label stacked
- Amount right-aligned: bold, positive green / negative red

---

# PART 4 — DATA TABLES

## 4.1 Table Container

- Sits inside a white card with `--shadow-sm`
- `border-radius: 8px`
- No overflow clipping on the card — table scrolls horizontally inside the card on small screens
- Card top section contains filters and action buttons; table below a `1px` divider

## 4.2 Table Header

| Property | Value |
|---|---|
| Background | `#FFFFFF` (no fill change) |
| Text style | `label` size (12px), uppercase, weight 500, `--color-text-secondary` |
| Letter spacing | 0.06em |
| Border bottom | `2px solid #F0F1F3` |
| Height | 48px |
| Padding | `12px 16px` per cell |
| Sortable column indicator | Small up/down arrows (14px), color `--color-text-tertiary`; active direction highlighted in `--color-primary` |
| Checkbox column | 40px wide, centered |
| Actions column | 100px wide, right-aligned header text |

## 4.3 Table Rows

| Property | Value |
|---|---|
| Row height | 56px |
| Cell padding | `12px 16px` |
| Border bottom | `1px solid #F0F1F3` |
| Text | 14px, `--color-text-primary` |
| Last row border | none (or hidden when card border-radius clips it) |

### Row States

| State | Background |
|---|---|
| Default | `#FFFFFF` |
| Hover | `rgba(67,74,92,0.03)` |
| Selected (checkbox checked) | `rgba(105,108,255,0.06)` |
| Loading skeleton | `#F4F5FB` animated shimmer |

### User Column (Rightmost in screenshots — LTR equivalent: leftmost semantic)

- Name: 14px, `--color-text-primary`, medium weight
- Email: 12px, `--color-text-secondary`, below the name
- Avatar: 36×36px circle, left of the text block

## 4.4 Table Controls Bar

Located inside the card, above the table, below the card title area:

```
[Add New User +]  [↓ Export ↑]    [Search User input]    [10 ▼] (rows per page)
```

### Add New Button

- Style: filled primary `--color-primary`, white text
- Border-radius: 6px
- Height: 38px
- Padding: `8px 16px`
- Leading icon: `+` (16px)

### Export Button

- Style: outlined, border `1px solid --color-border`
- Background: `#FFFFFF`
- Text: `--color-text-primary`
- Leading icon: arrow-down (export) + arrow-up (import) pair, 16px
- Dropdown trigger: chevron, opens export format menu (CSV, XLSX, PDF)

### Search Input

- Height: 38px
- Border: `1px solid --color-border`
- Border-radius: 6px
- Background: `#FFFFFF`
- Placeholder: "Search User"
- Search icon: left side, 16px, `--color-text-tertiary`
- Width: 220px; expands on focus to 300px
- Clear button: `×` appears inside when text is present

### Rows-Per-Page Selector

- Inline dropdown, right-aligned
- Shows current value (10, 25, 50, 100)
- Small chevron icon beside number
- Width: 64px

## 4.5 Search Filters Bar

Three filter dropdowns in a row, each equal width:

```
[↓ Select Status]   [↓ Select Plan]   [↓ Select Role]
```

- Dropdown trigger height: 40px
- Border: `1px solid --color-border`
- Border-radius: 6px
- Placeholder text: `--color-text-secondary`
- Chevron icon: right-aligned inside control
- Dropdown menu: white card, `--shadow-md`, `border-radius: 6px`
- Options list: 40px per option, hover background `rgba(105,108,255,0.06)`
- Active/selected option: text `--color-primary`

## 4.6 Status Badges in Tables

Pill-shaped badges with colored text and background:

| Status | Text Color | Background | Border |
|---|---|---|---|
| Active | `#59AB2C` | `#E8F8DE` | none |
| Inactive | `#8A8FA3` | `#F0F1F3` | none |
| Pending | `#D47F00` | `#FFF3CD` | none |
| Paid | `#2C7FB8` | `#D7F4FC` | none |
| Cancelled | `#CC2E1A` | `#FFE0DB` | none |

Badge specs:
- Height: 24px
- Padding: `4px 12px`
- Border-radius: 12px (pill)
- Font: 12px, weight 600

## 4.7 Row Action Buttons

Three actions per row, left-aligned in ACTIONS column:

| Icon | Size | Color | Action |
|---|---|---|---|
| Vertical dots (⋮) | 18px | `--color-text-secondary` | More actions menu |
| Eye icon | 18px | `--color-text-secondary` | View detail |
| Trash icon | 18px | `--color-danger` on hover | Delete |

- Resting color: `--color-text-secondary`
- Hover: icon color shifts to semantic color (eye → `--color-primary`, trash → `--color-danger`)
- Click area: 32×32px, border-radius: 4px, hover background: `rgba(67,74,92,0.06)`
- Gap between actions: 4px

## 4.8 Sorting

- Click column header to sort ascending
- Second click: descending
- Third click: clear sort
- Indicator: small up/down arrow pair beside label; active arrow highlighted `--color-primary`
- Sorted column header text becomes `--color-text-heading`

## 4.9 Pagination

Located below the table inside the card:

```
Showing 1 to 10 of 120 entries    [‹ Prev]  [1] [2] [3] ... [12]  [Next ›]
```

- Left: entry count text, 13px, `--color-text-secondary`
- Right: page buttons
- Page button: 32×32px, border-radius: 6px, border `1px solid --color-border`
- Active page: background `--color-primary`, text white, no border
- Hover: background `rgba(105,108,255,0.08)`, text `--color-primary`
- Prev/Next: text buttons with chevron icons, disabled state: `--color-text-tertiary`

## 4.10 Empty State

- Centered in the table body area
- Large muted icon: 80px
- Heading: 16px, `--color-text-heading`
- Sub-text: 14px, `--color-text-secondary`
- Optional CTA: primary button

## 4.11 Loading State

- Replace each data row with a skeleton row
- Skeleton cells: rounded bars, 60–90% of cell width, 14px tall
- Background: `#F4F5FB` with shimmer animation (left-to-right gradient sweep)
- Avatar skeleton: 36×36px circle
- Badge skeleton: 60px pill shape
- Animate for max 10 seconds before showing error

## 4.12 Bulk Actions

When one or more rows are selected via checkbox:

- A sticky action bar appears above the table
- Background: `--color-primary-light`
- Border: `1px solid --color-primary`
- Content: "X items selected" + action buttons (Delete Selected, Change Status, Export Selected)
- Appears with a smooth slide-down transition

---

# PART 5 — CRUD PAGES

## 5.1 List Pages

**Page Structure:**
```
[Page Header: Title + Breadcrumb]
[Filters Row]
[Table Card]
  [Table Controls Bar: Add New | Export | Search | Rows-per-page]
  [Filter Dropdowns Row]
  [Data Table]
  [Pagination]
```

The page header is in the main header bar (not repeated in content). Breadcrumb sits below the top navigation bar.

## 5.2 Create Pages

**Page Structure:**
```
[Breadcrumb: List > Create New]
[Form Card — full width or 8/12 columns centered]
  [Card Title: "Add New [Entity]"]
  [Form Sections]
  [Form Footer: Save + Cancel]
```

- Form card max-width: 860px, centered if viewport is wider
- Card padding: 24px
- Section separator: subheading label + `1px` divider line

## 5.3 Edit Pages

- Identical layout to Create pages
- Card title: "Edit [Entity Name]"
- Form fields pre-populated with existing values
- Additional "Delete" danger button in the footer, separated from Save/Cancel

## 5.4 View Detail Pages

**Page Structure:**
```
[Breadcrumb]
[Two-column layout: 8 cols detail + 4 cols sidebar]
  Left: [Entity Overview Card]
        [Related Data Tabs: Overview | Activity | Documents]
        [Related Table (if applicable)]
  Right: [Quick Info Card]
         [Status Card]
         [Action Buttons Card]
```

- Read-only fields rendered as label + value pairs
- Edit button top-right of detail card opens Edit page
- Tabs use underline style (not pill style)

## 5.5 Delete Confirmation

- Triggered by trash icon in table row or Delete button on Edit page
- Opens a **small modal** (see Modals section)
- Heading: "Delete [Entity]?"
- Body: "Are you sure you want to delete [Name]? This action cannot be undone."
- Buttons: [Cancel] [Delete] — Delete is `--color-danger` filled, Cancel is outlined

---

# PART 6 — FORMS

## 6.1 Form Layout

- Form fields use a two-column grid on desktop (two fields per row)
- Wide fields (textarea, description, address) span full width
- Label always above the input, never floating
- Required field indicator: asterisk `*` in `--color-danger`, immediately after the label text
- Form sections: divided by a `heading-sm` title + `1px solid --color-border` line
- Vertical gap between field rows: 20px
- Horizontal gap between fields in a row: 20px
- Form footer: `1px` top border, `16px` top padding, flex row with buttons right-aligned

## 6.2 Text Inputs

| Property | Value |
|---|---|
| Height | 38px |
| Border | `1px solid --color-border` |
| Border-radius | 6px |
| Background | `#FFFFFF` |
| Padding | `8px 14px` |
| Font | 14px, `--color-text-primary` |
| Placeholder | 14px, `--color-text-tertiary` |
| Focus border | `1px solid --color-primary` |
| Focus shadow | `0 0 0 3px rgba(105,108,255,0.12)` |
| Error border | `1px solid --color-danger` |
| Disabled background | `#F4F5FB` |
| Disabled text | `--color-text-tertiary` |

## 6.3 Textareas

- Same border, border-radius, and focus states as text inputs
- Minimum height: 100px
- Resize: vertical only
- Line height: 1.5

## 6.4 Select Dropdowns

- Same visual appearance as text inputs
- Chevron icon right-aligned: 16px, `--color-text-secondary`
- Dropdown panel: white card, `--shadow-md`, `border-radius: 6px`
- Option height: 38px, padding `8px 14px`
- Option hover: `rgba(105,108,255,0.06)` background
- Selected option: `--color-primary` text, checkmark icon right-aligned

## 6.5 Date Pickers

- Input appearance: same as text input
- Calendar icon: right side, 16px, `--color-text-secondary`
- On click: calendar panel drops below input
- Calendar panel: white card, `--shadow-md`, `border-radius: 8px`, 280px wide
- Today highlight: `--color-primary-light` background
- Selected day: `--color-primary` filled circle, white text
- Hover day: `rgba(105,108,255,0.08)` background
- Month/year navigation: chevron arrows left and right of month-year label

## 6.6 Multi-Select

- Input shows selected items as pill tags inside the input field
- Tag: background `--color-primary-light`, text `--color-primary`, `×` remove button
- Tag height: 22px, padding `2px 8px`, `border-radius: 11px`
- Dropdown behavior same as single select
- Checked items show a checkmark icon

## 6.7 Search-Select (Autocomplete)

- Input with search icon left-side
- Typing filters the dropdown options in real time
- If no results: "No options found" in dropdown, 13px, `--color-text-tertiary`
- Loading state: spinner in dropdown

## 6.8 Checkboxes

- Custom styled: 18×18px square, `border-radius: 4px`
- Unchecked: border `1px solid --color-border`, background white
- Checked: background `--color-primary`, border `1px solid --color-primary`, white checkmark icon inside
- Indeterminate: `--color-primary` background, horizontal white dash
- Disabled: background `#F4F5FB`, border `--color-border-light`
- Label: 14px, `--color-text-primary`, 8px left gap

## 6.9 Radio Buttons

- Custom styled: 18×18px circle
- Unchecked: border `1px solid --color-border`
- Checked: outer ring `--color-primary`, filled inner circle (8px) `--color-primary`
- Disabled: same as checkbox disabled

## 6.10 Toggle Switches

- Width: 40px, height: 22px, `border-radius: 11px`
- Off state: background `#DBDDE0`, thumb (18px circle, white) on left
- On state: background `--color-primary`, thumb slides right with transition `0.2s ease`
- Disabled: background `#F0F1F3`, thumb `#B4B9C9`

## 6.11 Validation Messages

- Position: directly below the input field, top margin 4px
- Error text: 12px, `--color-danger`
- Success text: 12px, `--color-success`
- Leading icon: small warning/check circle icon (14px)
- Error input border turns `--color-danger`; error shadow same as focus but danger color

## 6.12 Required Fields

- Label: `Field Label *`
- Asterisk color: `--color-danger`
- No tooltip; asterisk is self-explanatory with a form-level note: "* Required fields"

---

# PART 7 — CARDS

## 7.1 Standard Card

| Property | Value |
|---|---|
| Background | `#FFFFFF` |
| Border-radius | 8px |
| Shadow | `--shadow-sm` |
| Padding | 24px |
| Card title | 16px, weight 600, `--color-text-heading` |
| Card subtitle | 13px, `--color-text-secondary` |
| Title-to-content gap | 16px |
| Border | none (shadow provides lift) |

Card Header Pattern:
- Title left-aligned, optional action (icon button or dropdown) right-aligned
- Divider line `1px solid #F0F1F3` between header and body (optional)

## 7.2 Statistic Cards (KPI)

Already defined in Section 3.1. Additional detail:

- The primary metric number uses `display-xl` (28px, 700 weight)
- Supporting percentage delta uses a colored text badge
- Supporting label uses `caption` (11px, `--color-text-secondary`)
- Icon container is always a circle, never a square

## 7.3 Profile / User Cards

- Avatar: 64px circle (view pages), 36px (list rows)
- Name: 16px, `--color-text-heading`
- Role badge: pill badge, `--color-primary-light` background
- Contact info: 13px, `--color-text-secondary`, with icons

## 7.4 Summary Cards

- Used in sidebars or alongside detail views
- Display key-value pairs: label row 12px secondary + value row 14px primary
- Each pair has `12px` vertical gap
- Section divider lines between groups of pairs

## 7.5 Analytics Cards

- Always include a header with title, optional dropdown (period selector), and optional ellipsis menu (…)
- Chart occupies the body
- Footer (optional): aggregate figures or date range labels
- Period selector: inline dropdown, borderless, `--color-primary` text

---

# PART 8 — MODALS

## 8.1 Modal Overlay

- Background: `rgba(67,74,92,0.5)`
- Backdrop: `blur(2px)` (optional, use with caution on low-power devices)
- Centered in viewport
- Close on overlay click: enabled by default, disabled for destructive actions

## 8.2 Small Modal (Confirmation / Alert)

| Property | Value |
|---|---|
| Width | 400px |
| Max-height | 80vh |
| Border-radius | 8px |
| Padding | 24px |
| Shadow | `--shadow-xl` |

Layout:
```
[Close ×  top-right]
[Icon (optional — large, centered, colored)]
[Title — 18px, centered or left]
[Body text — 14px, secondary, centered or left]
[Button row: Cancel | Confirm]
```

## 8.3 Medium Modal (Form / Details)

| Property | Value |
|---|---|
| Width | 600px |
| Max-height | 85vh |
| Overflow | scroll inside modal body |
| Padding (header) | 20px 24px |
| Padding (body) | 0 24px 24px |
| Padding (footer) | 16px 24px |

Layout:
```
[Modal Header: Title + Close ×]
[Divider line]
[Scrollable Body: Form or content]
[Divider line]
[Modal Footer: Cancel | Submit]
```

## 8.4 Large Modal

| Property | Value |
|---|---|
| Width | 900px or 90vw |
| Max-height | 90vh |

Used for: full form creation inside modal, bulk edit, document preview.

## 8.5 Confirmation Modal

Variant of small modal:
- Danger icon (trash / warning): 48px, `--color-danger` or `--color-warning`
- Heading: bold, 18px
- Body: describes irreversible nature of action
- Primary action button: `--color-danger` filled

## 8.6 Form Modal

Variant of medium modal with standard form inside. All form rules from Part 6 apply. Footer always shows: `[Cancel]` (outlined) and `[Save]` (primary filled).

---

# PART 9 — STATUS SYSTEM

## 9.1 Status Color Reference (Complete)

| Status | Text | Background | Icon |
|---|---|---|---|
| Active | `#59AB2C` | `#E8F8DE` | `bxs-check-circle` |
| Inactive | `#8A8FA3` | `#F0F1F3` | `bx-minus-circle` |
| Pending | `#D47F00` | `#FFF3CD` | `bx-time` |
| Paid | `#2C7FB8` | `#D7F4FC` | `bxs-check-shield` |
| Unpaid | `#CC2E1A` | `#FFE0DB` | `bxs-error` |
| Completed | `#59AB2C` | `#E8F8DE` | `bxs-check-circle` |
| Cancelled | `#CC2E1A` | `#FFE0DB` | `bxs-x-circle` |
| Draft | `#8A8FA3` | `#F0F1F3` | `bx-pencil` |
| Archived | `#6B7B99` | `#E8EAF0` | `bxs-archive` |
| Processing | `#696CFF` | `#E7E7FF` | `bx-loader-alt` (animated spin) |
| Overdue | `#FF3E1D` | `#FFE0DB` | `bxs-alarm-exclamation` |
| Suspended | `#FFAB00` | `#FFF3CD` | `bxs-lock` |

## 9.2 Status Badge Rendering

All statuses use the pill badge format (see Section 4.6). In detail views, badges are slightly larger: 28px height, 13px font.

## 9.3 Status Indicators in Non-Badge Contexts

For compact contexts (timeline, activity log):
- Colored dot: 8px circle, same text color as badge
- For "Processing": spinning 10px circle in `--color-primary`

---

# PART 10 — REPORTS PAGES

## 10.1 Financial Reports Page

**Page Layout:**
```
[Date Range Picker | Period Selector | Export Button]

[Summary Row: 4 KPI cards — Total Revenue / Total Expenses / Net Profit / Outstanding]

[Two-column layout:]
  Left (7 cols): Revenue vs Expense bar chart over time
  Right (5 cols): Expense breakdown donut chart + legend

[Full-width: Transactions Table]
  Columns: Date | Reference | Description | Category | Amount | Status | Actions
```

## 10.2 Client Reports Page

```
[Filters: Date Range | Client | Status]

[Summary: Total Clients | New Clients | Revenue from Clients | Retention Rate]

[Client List Table with embedded mini sparkline column showing revenue trend]

[Client Details Panel (drawer): Opens on row click]
  - Contact info
  - Transaction history table
  - Notes timeline
```

## 10.3 Office / Branch Reports Page

```
[Branch Selector (multi-select)]
[Date Range]

[KPI Row: per-branch or aggregate]

[Comparison Table: branches as rows, metrics as columns]
  Columns: Branch Name | Active Users | Revenue | Transactions | Avg Order | Growth

[Map Widget (optional): geographic distribution]
```

## 10.4 Transaction Reports Page

```
[Filters: Date Range | Type | Method | Status | Amount Range]

[Transaction Table]
  Columns: Date | Transaction ID | Sender | Receiver | Method | Amount | Status | Actions

[Pagination]

[Footer: Aggregate totals row — Total Amount column summed]
```

## 10.5 Report Page Common Patterns

- All reports have an **Export** button (CSV, XLSX, PDF) top-right
- A **Print** button that triggers browser print with report-optimized CSS
- Date range picker is always present, defaulting to current month
- Report header section: title, generated-at timestamp, filter summary line
- All tables in reports use `font-variant-numeric: tabular-nums` for aligned numbers

---

# PART 11 — MOBILE DESIGN RULES

## 11.1 Mobile Navigation

- Sidebar hidden by default on `< lg` breakpoints
- Hamburger menu icon (24px) in header activates slide-in sidebar
- Sidebar overlays content with semi-transparent backdrop
- Close: tap backdrop or press ×
- Mobile header height: 56px (reduced from 64px desktop)
- Mobile bottom navigation bar (optional alternative pattern):
  - 5 icons max: Home, Search, Notifications, Menu, Profile
  - Height: 56px, border-top `1px solid --color-border`
  - Background: `#FFFFFF`

## 11.2 Mobile Tables

- Tables on mobile collapse to **card-list** view
- Each row becomes a card with key fields shown
- Non-essential columns are hidden
- Visible fields: primary identifier, status badge, primary metric, actions
- Action buttons expand into a bottom sheet on mobile (slide-up panel)
- Horizontal scroll is a fallback, not a primary pattern

## 11.3 Mobile Forms

- Single-column layout (all fields full-width)
- Inputs: full-width, height 44px (larger touch target)
- Keyboard-appropriate types: `type="email"`, `type="tel"`, `type="number"` as appropriate
- Date pickers use native device picker on iOS/Android
- Submit button: full-width, 48px tall
- Form sections: collapsible accordions to reduce scrolling

## 11.4 Mobile Cards

- KPI cards: 2-per-row grid on mobile, each showing icon + number + delta only
- Standard cards: full-width with reduced padding (`16px`)
- Chart cards: height reduced; charts become simpler (fewer data points, no legend — legend above chart instead)
- Summary cards: stacked vertically

## 11.5 Mobile-Specific Interactions

- All dropdowns trigger bottom-sheet modals (full-width, slide up) rather than floating panels
- Search expands to full-screen search experience on mobile
- Modal width: 100% on mobile, with rounded top corners only (`border-radius: 16px 16px 0 0`)
- Modals slide up from bottom on mobile (not center-pop)
- Touch target minimum: 44×44px for all interactive elements

---

# PART 12 — PAGE TEMPLATES

## 12.1 Dashboard Template

```
SECTIONS:
1. KPI Row — 4 equal cards
2. Primary Analytics Row — 1 large chart (7 cols) + 1 secondary widget (5 cols)
3. Secondary Data Row — 3 widgets at varying widths
4. Data Table Section (optional — recent transactions or recent activity)

HEADER: Page title "لوحات القيادة" / "Dashboard"
SIDEBAR: "لوحات القيادة" section active
```

## 12.2 Customer Management Template

```
SECTIONS:
1. [Filters: Status | Plan | Role | Date Range]
2. [Table Controls: Add Customer | Export | Search]
3. [Customer Data Table]
   Columns: Customer | Email | Phone | Status | Plan | Revenue | Actions
4. [Pagination]

DETAIL PAGE:
1. [Customer Overview Card] — name, avatar, contact, status
2. [Tabbed Section] — Info | Orders | Payments | Notes | Activity
3. [Right Sidebar] — Quick stats card, Assigned rep card, Tags
```

## 12.3 Operations Management Template

```
SECTIONS:
1. [Status Summary Row] — Pending / In Progress / Completed / Overdue
2. [Operations Table or Kanban toggle]
   Table view: standard data table
   Kanban view: columns by status, cards with title + assignee + due date
3. [Bulk operations: reassign, change status, archive]

CREATE/EDIT:
1. [Operation Details Section] — title, description, type, priority
2. [Assignment Section] — Assignee, Team, Due date
3. [Related Records Section] — linked customers, documents
4. [Notes / Comments Section] — threaded comments
```

## 12.4 Vendors & Offices Template

```
LIST VIEW:
1. [Filter: Type | Status | Region]
2. [Vendor/Office Table]
   Columns: Name | Type | Location | Contact | Balance | Status | Actions

DETAIL VIEW:
1. [Vendor Profile Card] — logo, name, type, rating
2. [Financial Summary] — Outstanding balance, Total transacted, Last activity
3. [Tabs] — Contacts | Bank Accounts | Transactions | Documents | Notes
```

## 12.5 Treasury & Banks Template

```
SECTIONS:
1. [Account Summary Cards] — one per bank account / currency
   Each card: Bank logo, Account name, Balance, Currency flag
2. [Transaction Feed Table]
   Columns: Date | Bank | Reference | Type | Amount | Running Balance | Status
3. [Reconciliation Tools Row] — Import statement | Auto-match | Manual reconcile
4. [Charts] — Cash flow over time per account

TRANSFER / VOUCHER PAGE:
1. [Two-column form: From Account | To Account]
2. [Amount + Currency + Date fields]
3. [Memo / Reference field]
4. [Attachment upload]
5. [Submit for approval flow — optional]
```

## 12.6 Receipts & Vouchers Template

```
LIST VIEW:
Columns: Voucher # | Date | Type | Related Party | Amount | Status | Actions

CREATE VOUCHER PAGE:
1. [Voucher Header]: Number (auto) | Date | Type (Receipt/Payment) | Currency
2. [Related Party]: Search-select Customer or Vendor
3. [Line Items Table]:
   Rows: Description | Amount | Account | Notes
   Footer: Subtotal | Tax | Total
4. [Payment Method Section]
5. [Notes / Memo]
6. [Attachment Zone] — drag-and-drop, supports PDF/image
7. [Footer Buttons]: Save Draft | Submit | Cancel

PRINT VIEW:
- Full-page formatted receipt
- Logo top-left, company info top-right
- Voucher details centered
- Line items table
- Signature lines bottom
```

## 12.7 Reports Template

```
COMMON LAYOUT:
1. [Report Controls Bar] — Report type selector | Date range | Group-by | Export | Print
2. [Filter Chips Row] — Shows active filters, each removable with ×
3. [Summary KPI Row] — 3–5 cards with key totals
4. [Chart Section] — Primary visualization
5. [Detail Table] — Full data backing the chart
6. [Table Footer] — Totals row, entry count

REPORT HEADER (print mode):
- Company name + logo
- Report title
- Generated date + Generated by
- Active filter summary
```

## 12.8 Settings Template

```
LAYOUT:
[Two-column layout: 3 cols settings menu | 9 cols settings content]

SETTINGS MENU (left panel):
- Vertical list of setting categories
- Active category: left border accent `--color-primary`, text `--color-primary`
- Same visual rules as sidebar nested menu items

SETTINGS CATEGORIES:
- Profile
- Account
- Security
- Notifications
- Appearance
- Billing & Plans
- Integrations
- Team Members
- Roles & Permissions
- Audit Log
- API Keys

EACH SETTINGS SECTION:
1. [Section Title + Description]
2. [Settings form using card containers per logical group]
3. [Save Changes button at bottom of each section]

DANGER ZONE (end of Account Settings):
- Red-bordered card
- Title: "Danger Zone" in `--color-danger`
- Actions: Delete Account, Suspend Account — all `--color-danger` outlined buttons
```

---

# PART 13 — ADDITIONAL COMPONENT SPECIFICATIONS

## 13.1 Breadcrumb

- Location: below header, above content area
- Font: 13px, `--color-text-secondary`
- Separator: `›` character, `--color-text-tertiary`
- Last item (current page): `--color-text-primary`, not a link
- Hover on links: `--color-primary`, no underline
- Max breadcrumb depth visible: 3 levels; deeper paths are truncated with `...`
- RTL: separator flips to `‹`

## 13.2 Alerts / Toasts

Toast notifications appear top-right (bottom-center on mobile):

| Type | Left border / icon color | Background |
|---|---|---|
| Success | `--color-success` | `#FFFFFF` |
| Error | `--color-danger` | `#FFFFFF` |
| Warning | `--color-warning` | `#FFFFFF` |
| Info | `--color-info` | `#FFFFFF` |

- Width: 360px (desktop), 100% minus 24px padding (mobile)
- Border-radius: 8px
- Shadow: `--shadow-md`
- Left border: 4px solid (semantic color)
- Content: Icon (20px) + Title (14px bold) + Body (13px secondary)
- Auto-dismiss: 5 seconds with progress bar
- Manual dismiss: `×` button top-right
- Stack: up to 4 visible, older ones push down

## 13.3 Tabs

Two styles:

**Underline tabs (used in detail views):**
- Tab labels: 14px, `--color-text-secondary`
- Active tab: `--color-text-heading`, 2px bottom border `--color-primary`
- Hover: `--color-text-primary`
- Gap between tabs: 24px
- Tab bar bottom border: `1px solid --color-border-light`

**Pill tabs (used in filter/segment controls):**
- Container: `background: #F4F5FB`, `border-radius: 6px`, `padding: 4px`
- Tab: `border-radius: 4px`, height: 30px, padding `6px 14px`
- Active: `background: #FFFFFF`, shadow `--shadow-xs`, text `--color-text-heading`
- Inactive: background transparent, text `--color-text-secondary`

## 13.4 Dropdown Menus (Context Menus)

Triggered by `⋮` (vertical ellipsis) icons in tables and card headers:

- Width: 180px
- Border: `1px solid --color-border`
- Shadow: `--shadow-md`
- Border-radius: 6px
- Item height: 36px
- Item padding: `8px 14px`
- Item font: 14px, `--color-text-primary`
- Item leading icon: 16px, `--color-text-secondary`
- Hover: background `rgba(105,108,255,0.06)`, icon color `--color-primary`
- Dividers: `1px solid #F0F1F3`, full-width
- Danger item (Delete/Remove): text `--color-danger`, icon `--color-danger`

## 13.5 Avatars

| Size | Dimension | Usage |
|---|---|---|
| XL | 80×80px | Profile pages, modal headers |
| LG | 48×48px | Detail view header |
| MD | 36×36px | Table rows, comments |
| SM | 28×28px | Notification items, chips |
| XS | 20×20px | Inline mention, compact lists |

- Always `border-radius: 50%` (circle)
- Fallback: initials, 1–2 characters, from the color palette (Section 1.1)
- Image: `object-fit: cover`
- Group avatars (stacked): offset by -8px, outlined with 2px white border

## 13.6 File / Attachment Upload Zone

- Dashed border: `2px dashed --color-border`
- Background: `#F9FAFB`
- Border-radius: 8px
- Height: 120px minimum
- Center content: upload icon (32px, `--color-text-tertiary`) + "Drag and drop files or Click to browse"
- Drag-over state: border color `--color-primary`, background `--color-primary-muted`
- Accepted file previews shown as chip rows below the zone
- File chip: file icon + name + size + × remove button

## 13.7 Progress Bars

- Height: 8px
- Background track: `#F0F1F3`
- Filled: `--color-primary` (or status color)
- Border-radius: 4px (pill)
- Label: `13px` above or beside, showing `XX%`

## 13.8 Tooltips

- Background: `#2E2F45` (dark)
- Text: `#FFFFFF`, 12px
- Border-radius: 4px
- Padding: `4px 8px`
- Max-width: 200px
- Delay: 300ms on hover
- Arrow: 4px triangle pointing to target element
- Position: above target by default; auto-flips if near viewport edge

---

# PART 14 — ANIMATION & MOTION

## 14.1 Transition Defaults

| Context | Duration | Easing |
|---|---|---|
| Button hover | 120ms | ease-out |
| Input focus | 150ms | ease-out |
| Dropdown open | 180ms | cubic-bezier(0.16,1,0.3,1) |
| Modal open | 220ms | cubic-bezier(0.16,1,0.3,1) |
| Sidebar expand/collapse | 240ms | ease-in-out |
| Toast enter/exit | 200ms | ease-out |
| Tab switch | 150ms | ease |
| Page transition | 200ms | ease |
| Chart draw | 600ms | ease-out |

## 14.2 Sidebar Animation

- Expanding: width animates from 72px to 260px; labels fade in after width transition
- Collapsing: labels fade out first, then width animates
- Mobile: `transform: translateX(100%)` → `translateX(0)` for RTL; reverse for LTR

## 14.3 Skeleton Loading Animation

- Background: linear-gradient sweeping left to right
- Colors: `#F0F1F3` → `#E4E5EA` → `#F0F1F3`
- Duration: 1.5s, `ease-in-out`, infinite
- Applied to skeleton shape elements only, not containers

## 14.4 Reduced Motion

Respect `prefers-reduced-motion: reduce`:
- Disable all transition durations > 0ms (set to 1ms)
- Disable chart animations
- Disable sidebar slide animations (instant show/hide)
- Toasts appear immediately without slide

---

# PART 15 — ACCESSIBILITY SPECIFICATIONS

## 15.1 Color Contrast Requirements

- All body text on white: minimum 4.5:1 ratio (WCAG AA)
- Large text (18px+ or 14px+ bold): minimum 3:1 ratio
- `--color-primary` on white: passes AA for large text; use `--color-primary-dark` for body text
- Badge text on colored backgrounds: always test individually — success green background with green text must use a darker shade
- Status text in badges has been designed to use darker shades of the base hue for this reason

## 15.2 Focus States

All interactive elements must have a visible focus indicator:
- Default browser outline removed, replaced with custom:
- `outline: 3px solid rgba(105,108,255,0.5); outline-offset: 2px`
- Applied on `:focus-visible` only (not `:focus`, to avoid mouse-click outlines)
- Focus color matches component type:
  - Default components: `--color-primary` ring
  - Danger components (delete buttons): `--color-danger` ring

## 15.3 ARIA Requirements

- Sidebar nav: `<nav aria-label="Main navigation">`
- Data tables: `<table role="grid">`, column sort: `aria-sort="ascending/descending/none"`
- Modals: `role="dialog"`, `aria-modal="true"`, `aria-labelledby` pointing to title
- Status badges: include `role="status"` or wrap in `<span aria-label="Status: Active">`
- Form validation errors: `aria-describedby` linking input to its error message
- Loading states: `aria-live="polite"` on content areas
- Icon-only buttons: `aria-label` required on all icon buttons

---

# APPENDIX A — QUICK REFERENCE: COMPONENT SIZING GRID

| Component | Height | Border Radius | Font Size |
|---|---|---|---|
| Primary button (MD) | 38px | 6px | 14px |
| Primary button (SM) | 30px | 6px | 13px |
| Primary button (LG) | 44px | 6px | 15px |
| Text input | 38px | 6px | 14px |
| Select dropdown | 38px | 6px | 14px |
| Search input (header) | 40px | 20px | 14px |
| Table row | 56px | — | 14px |
| Table header | 48px | — | 12px |
| Status badge | 24px | 12px | 12px |
| Standard card | auto | 8px | — |
| Modal (SM) | auto | 8px | — |
| Sidebar item | 42px | 6px | 14px |
| Header bar | 64px | — | — |
| Avatar (MD) | 36px | 50% | — |
| Toggle switch | 22px | 11px | — |
| Checkbox | 18px | 4px | — |
| Toast notification | auto | 8px | 13px |
| Tab (underline) | 40px | 0 | 14px |
| Tab (pill) | 30px | 4px | 13px |

---

# APPENDIX B — NAMING CONVENTIONS

All CSS custom properties follow: `--category-variant-modifier`

Example: `--color-primary-light`, `--space-6`, `--shadow-md`, `--radius-lg`

Page-level class naming: `page-[section]` (e.g., `page-users`, `page-dashboard`)  
Component class naming: `c-[component]` (e.g., `c-card`, `c-badge`, `c-modal`)  
State modifiers: `is-[state]` (e.g., `is-active`, `is-loading`, `is-disabled`)  
RTL modifier: Applied via `[dir="rtl"]` attribute selector, not separate classes

---

*End of Enterprise Design Specification — Version 1.0*  
*This document covers the complete design language for a commercial SaaS application.*  
*All values are derived from visual analysis of the Sneat dashboard UI system.*
