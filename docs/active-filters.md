# Active filters

One removable chip per active filter value, with an optional clear-all.

Element name `zag-active-filters`, class `Zag\Element_Zag_Active_Filters`, in
the builder as **Zag - Active filters** under Filters.

Modelled on the `.afchip` row in `ref/search/search.html`.

## Usage

Drop it on a page with Zag filters. Nothing to configure — it finds them, shows
a chip per active value, and hides itself entirely when nothing is active.

| Control | Notes |
|---|---|
| Leading label | Shown before the chips, e.g. "Filtering by:". Empty for none |
| Limit to query | Empty covers every Zag filter on the page |
| No chip icons | Chips repeat the owning filter's leading icon by default; this switches that off |
| Show clear all | A button after the chips clearing every covered filter at once |
| Clear all: text | Wording for the above |
| Remove: label | Screen-reader wording; the chip text is appended, giving "Remove Penrith" |

**Style** — the shared palette, plus chip background, text, border, radius and
gap, and the remove button's resting and hover colours. Field and popover
controls are absent: this element draws neither.

## Why not Bricks' own active filters element

It mislabels our filters in three separate places, all in
`themes/bricks/includes/elements/filter-active-filters.php`:

| Line | Gate | Effect on us |
|---|---|---|
| `:280` | `$instance_name === 'filter-checkbox'` splits an array into one chip per value | Ours is `zag-filter-select`, so a **multi-select renders as one chip** holding the whole array |
| — | the value read has already been through `sanitize_filter_value()` | whose `default` branch collapses an array to its first element, so that one chip is usually **wrong as well** |
| `:406` | `$instance_name === 'filter-datepicker'` selects date formatting | our date chips fall through to **generic labelling** |

The first two are the same root cause as limitation #4 in the root `CLAUDE.md`:
a hardcoded element-name switch we cannot join.

Reading `window.bricksData.filterInstances[…].currentValue` instead sidesteps
all three at once. That value is the untouched frontend one — never sanitised,
never collapsed — and an array stays an array.

## How it works

Client-rendered, like the smart search, and for the same reason: rendering
server-side would mean reading the same collapsed, name-gated data, and would
then need the element added to `dynamic_update_elements()` to ever refresh —
which Bricks does not allow.

1. `filterTargets(queryId)` (`src/bricks.js`) returns every live Zag filter.
2. Each supplies its own chips through `adapter.describe()`:
   - **select** — one per selected value, labelled with the count-free option
     text so a chip never reads "Penrith (4)". A value with no matching option
     still gets a chip showing the raw value, so it can always be removed.
   - **date picker** — **one chip for the whole value**, labelled with the same
     formatter the field itself uses. A range is a single clause; removing half
     of it would leave a state the calendar cannot represent, so removing the
     chip clears the field.
3. If the filter has a leading icon, `adapter.icon()` hands back the live node
   and the chip clones its **children** — the glyph only, never the select's own
   icon wrapper, which carries 40px badge sizing that is meaningless at chip
   scale. Cloned glyphs are `aria-hidden`; the chip text carries the meaning.
4. Removing a chip drops that one value and re-applies what remains through
   `driveFilters()`. Clear-all empties every covered filter in one request per
   query.

Both filter elements can supply one. The date picker only does so when its Icon
control is set — the built-in calendar glyph is chrome rather than something the
editor chose, so it stays unmarked and never reaches a chip.

A side effect of cloning is that Bricks' icon colour rule — scoped to the filter
element — no longer reaches the copy, so a chip icon takes the chip's own colour.
That is the intent: inside a chip it should read as chip text.

A filter that cannot label a value produces no chip. That is deliberate — the
alternative is a chip reading a bare term id.

### Re-render triggers

Two, deliberately:

- `zag/filter/change` — fires on selection, so a chip appears or disappears with
  the click rather than after the round trip.
- `onFiltersSettled()` — the authoritative pass. Also covers resets, popstate,
  the smart search, and this element's own removals.

**Not** a plain `bricks/ajax/query_result/displayed` listener. That runs before
the lifecycle reconciles Bricks' DOM swap, so every render reads discarded
instances and paints the previous state — see the gotcha of the same name in the
root `CLAUDE.md`.

Rendering twice per interaction is free: it rebuilds a few nodes from state that
is already correct both times.

The row is rebuilt wholesale rather than diffed. Nothing in it holds focus that
a rebuild could steal — the remove button that triggered the change is gone by
definition.

## Known limitations

- **Native Bricks filters are ignored.** Labels come from our elements' own
  option config; a native select or checkbox filter on the same page would need
  its label read out of the DOM, per filter type. Use Bricks' own element for
  those, or don't mix.
- **`filterApplyOn: click` filters update late.** With a submit button,
  `currentValue` changes without a fetch, so chips follow on the next response
  rather than immediately. The `zag/filter/change` trigger covers the common
  case; this one waits for the submit.

## Testing

Not covered by `tests/impersonation-test.php` — that harness stubs the Bricks
PHP surface, and this is all frontend. `collectChips` and `removeChip` are pure
apart from the target objects and would test well alongside the search parser
once `src/` grows a Node test setup; see `docs/smart-search.md`.
