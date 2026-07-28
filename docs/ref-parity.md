# Ref parity — visual discrepancies

Comparison of the shipped elements against the Claude Design prototypes in
`ref/`:

- `ref/Booking Location Dropdown.dc.html` → `zag-filter-select`
- `ref/Booking Date Picker.dc.html` → `zag-filter-datepicker`

Ours is compared against the ref's **`.loc--standard`** variant, which is the
one that matches a full-width form field. The pill variant is treated separately
below.

Three categories, because they need different decisions:

- **Intentional** — we diverged on purpose. Listed so nobody "fixes" them.
- **Gap** — the ref has something we don't. Needs a build decision.
- **Drift** — same intent, different numbers. Should mostly just be aligned.

## Status

**All drift is resolved**, along with the two defects (D2, D4). Every value that
varied is now a custom property defaulted to the ref, and the Bricks **Style →
Zag** group writes those same properties — so an untouched element matches the
ref and any override wins without a specificity fight.

**Outstanding: the feature-level gaps** — S1, S2, S3, S4, D1, D3. Each needs new
markup or controls rather than CSS, so they're grouped as one decision below.

Resolved rows are struck through and kept for reference.

---

## Intentional divergences

| Area | Ref | Ours | Why |
|---|---|---|---|
| Typography | Sanomat Sans / Apud, explicit sizes | inherits from theme | A Bricks element must take the site's type, not impose a design system's |
| Accent token | `--loc-accent` / `--dp-accent` | `--zag-accent` shared | One token across elements; per-element override still possible |
| Width | fixed `320px` / `340px` | `100%` | Bricks controls layout width |
| Range selection | not present | `data-in-range` / `range-start` / `range-end` | Ref prototype is single-date only |
| Option counts | not present | `.brx-option-count`, disabled states | Comes from Bricks' index; no prototype equivalent |

---

## Select — gaps

| # | Item | Ref | Status |
|---|---|---|---|
| S1 | **Field label** | `.loc-label`, 14px, shown above the field in the standard variant | No label control at all |
| S2 | **Caption / helper text** | `.loc-caption` with a status dot that fills with accent when set | Absent |
| S3 | **Pill variant** | Base `.loc` is a 99px-radius pill, `padding:0 16px 0 4px` | Only the 8px "standard" form field exists |
| S4 | **Leading icon slot** | `.loc-icon`, 40px white circle inside the pill | Absent — needed for the pill variant to read correctly |
| ~~S5~~ | **Option gap** | menu is `flex-direction:column; gap:2px` | Options stack flush |

## Select — drift

| # | Property | Ref | Ours |
|---|---|---|---|
| ~~S6~~ | Menu shadow | `0 0 10px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.10)` | `0 1px 2px rgba(11,31,59,.06), 0 12px 32px rgba(11,31,59,.12)` |
| ~~S7~~ | Menu padding | `4px` | `6px` |
| ~~S8~~ | Option height | fixed `48px`, padding `16px 12px` | `min-height:44px`, padding `10px 12px` |
| ~~S9~~ | Option radius | `8px` | `6px` |
| ~~S10~~ | Value letter-spacing | `0.02em` | not set |
| ~~S11~~ | Value colour when selected | `rgba(11,31,59,0.95)` | full `--zag-fg` |
| ~~S12~~ | Multi checkbox radius | `2px` | `3px` |
| ~~S13~~ | Chevron transition | `.22s` | `.2s` |
| ~~S14~~ | Stacking | positioner `z-index:1`, `1000` when open | no positioner z-index; root gets `z-index:20` when open |

`S14` is worth a note: the ref puts the z-index on the **positioner**, we put it
on the **root**. Ours is why the closed positioner could overlay siblings — see
the pointer-events gotcha in `CLAUDE.md`. Both work now; the ref's is arguably
the more contained approach.

---

## Date picker — gaps

| # | Item | Ref | Status |
|---|---|---|---|
| D1 | **Typeable input** | `.dp-control` holds a real `<input>` plus a separate 24px calendar trigger — you can type a date | Ours is a single button; keyboard users must use the calendar |
| ~~D2~~ | **Focus ring on cells** | `[data-focus]` → `inset 0 0 0 2px rgba(94,180,225,.5)` on days and grid cells | Absent — keyboard focus is invisible while navigating the grid |
| D3 | **Caption / helper text** | `.dp-caption` with status dot | Absent |
| ~~D4~~ | **Highlight guards** | `[data-highlighted]:not([data-disabled]):not([data-selected])` | Ours highlights disabled and selected cells too |

`D2` is the one I'd treat as a bug rather than a gap — arrow-key navigation
currently gives no visible indication of where you are.

## Date picker — drift

| # | Property | Ref | Ours |
|---|---|---|---|
| ~~D5~~ | Popover padding | `20px` | `14px` |
| ~~D6~~ | Popover radius | `16px` | `12px` |
| ~~D7~~ | Popover shadow | `0 0 10px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.10)` | `0 1px 2px …, 0 12px 32px …` |
| ~~D8~~ | Popover width | `width:max-content` | not set |
| ~~D9~~ | Section spacing | `flex column, gap:14px` | `margin-bottom:10px` on the view control |
| ~~D10~~ | Day cell | `40×40`, `border-radius:9999px` (circular), weight 300, 15px | `36×36`, `radius:8px` |
| ~~D11~~ | Weekday header | 12px, weight 400, **not uppercase**, `width:40px;height:30px` | 11px, weight 600, uppercase, `letter-spacing:.06em` |
| ~~D12~~ | View title | padding `6px 12px`, radius 8px, regular weight, auto width | `height:32px`, weight 500, `flex:1` |
| ~~D13~~ | Nav buttons | `34px`, `border-radius:9999px` (circular) | `32px`, `radius:6px` |
| ~~D14~~ | Grid cell (month/year) | `min-width:88px`, `height:44px`, radius `10px` | auto width, `height:36px`, radius `8px` |
| ~~D15~~ | Table cell padding | day `1px`, grid `3px` | none |
| ~~D16~~ | Day selected weight | `400` | `500` |
| ~~D17~~ | Outside-range days | `color:rgba(11,31,59,0.28)` | `opacity:0.35` |

`D10`, `D11` and `D13` together are what make the ref calendar read as soft and
rounded where ours reads as square and dense — circular days and nav buttons,
larger cells, and a lighter weekday row. They're the highest-impact drift items.

---

## Remaining work

Only the feature-level gaps are left. They share one decision: how much of the
prototype's surrounding chrome the elements should own.

- **S1 / D3 — field label and caption.** The ref shows a 14px label above the
  field and a caption below with a status dot that fills with accent once set.
  Both need new controls and markup.
- **S2 — see S1.**
- **S3 / S4 — pill display mode.** The ref's base select is a 99px pill with a
  40px white circular icon slot on the left. Needs a display-mode control and an
  icon control; the radius alone is already reachable via the Field radius
  control, but the icon slot is not.
- **D1 — typeable date input.** The ref's `.dp-control` wraps a real `<input>`
  plus a small calendar trigger, so a date can be typed. Ours is a single
  button. This changes the element's interaction model and its progressive
  enhancement baseline, so it wants its own discussion.

<details>
<summary>Original suggested order (superseded)</summary>

1. **D2** — invisible keyboard focus in the calendar grid; accessibility, not polish.
2. **D10 / D11 / D13 / D5 / D6** — the shape of the calendar. Biggest visual delta for the least work.
3. **S6 / D7** — shadows. One shared token would fix both.
4. **S8 / S9 / S5** — option metrics in the select menu.
5. **S1 / S2 / D3** — label and caption. Needs new controls, so a feature decision rather than a CSS pass.
6. **S3 / S4** — pill variant with leading icon. The largest piece; a new display mode.
7. **D1** — typeable date input. Structural change to the element, worth its own discussion.

</details>
