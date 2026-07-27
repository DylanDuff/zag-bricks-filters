# Zag Filters for Bricks

Bricks query filter elements whose UI is driven by [Zag.js](https://zagjs.com)
state machines instead of native form controls.

Built against **Bricks 2.1.4**. Line references below point into
`wp-content/themes/bricks/` and are the first thing to re-check after a Bricks
update.

## The central problem

Bricks gates its query filter pipeline on **hardcoded element-name lists**, none
of which has a filter hook:

| Function | Location | Gates |
|---|---|---|
| `filter_controls_elements()` | `includes/query-filters.php:707` | whether `Filter_Element::get_filter_controls()` returns anything |
| `dynamic_update_elements()` | `:724` | whether the element is re-rendered after a filter AJAX request |
| `indexable_elements()` | `:742` | whether the element is written to the filter index |

A custom element name is invisible to all three. Everything unusual in this
codebase exists to work around that, by **impersonating a native filter element**
at each integration point.

`element_map()` in `plugin.php` is the single source of truth for which native
element each Zag element stands in for. Note the mapping is also
**per-instance**: a multi-select impersonates `filter-checkbox` (array values), a
single select impersonates `filter-select` (scalar values). See
`Element_Zag_Filter_Select::impersonates()`.

### 1. Builder controls — name spoofing

`Filter_Element::get_filter_controls()` (`filter-base.php:1073`) bails unless
`$this->name` is in the allow-list. `$name` is a public property, so we swap it
across the parent call and restore it after.

The method also branches on name *internally* (`:1136`, `:1374`, `:1397`,
`:1762`), so no single spoof yields every control. We call it twice — once as
`filter-select`, once as `filter-checkbox` — and take the union, then prune the
controls describing native markup we don't render (`UNUSED_CONTROLS`).

Controls are static per element, so they cannot vary by the `zagMultiple`
setting. That is why we take the union rather than picking one.

### 2. The index — replaying the indexer

Bricks registers its indexer at `add_action('update_post_metadata', …, 11, 5)`
(`query-filters.php:63`). We register at **priority 12** and call the same public
method again with a doctored copy of the elements array.

A rewrite is impossible: Bricks' callback is a separate subscriber on the same
filter and receives the original `$meta_value` regardless of what we do — WP
passes filter arguments by value and only chains the first. So we replay.

**Order is load-bearing.** `update_element_table()` deletes any element-table row
absent from its input. Bricks' pass at 11 would therefore delete our rows if we
ran first. Running at 12 with a superset (native elements untouched + Zag
elements renamed) reinstates everything in one write.

This is the most fragile part of the plugin. If counts stop working after a
Bricks update, check this first.

### 3. Query vars — reimplementation

`generate_query_vars_from_active_filters()` (`:2136`) switches on the element
name read from **page data**, not the element table, so our filters fall through
it. `apply_filters('bricks/query_filters/filter_query_vars', …)` fires
immediately after, which is where we pick them up.

The native builders (`build_taxonomy_query_vars` et al, `:2444` onward) are all
`private static` and so must be reproduced rather than called. Keep the copies
structurally close to the originals so divergence is easy to spot.

**Currently only `filterSource = taxonomy` is implemented.** `wpField` (`:2497`,
~106 lines) and `customField` (`:2603`, ~92 lines) are not yet ported — a filter
configured with either source will render but silently fail to filter.

Each builder must write its clause back onto
`Bricks\Query_Filters::$active_filters[$query_id][$index]` as `query_vars` /
`query_type`. `set_options_with_count()` reads those off *sibling* filters to
compute counts that account for what else is active; skipping the write-back
gives every other filter on the query wrong counts.

## Frontend

`src/zag-filters.js` → `assets/js/zag-filters.js` via esbuild (`pnpm build`). The
bundle **is committed** so the plugin works from a plain checkout.

Key facts about Bricks' filter JS (`assets/js/filters.js`):

- `window.bricksUtils.updateSelectedFilters(queryId, filterInstance)` then
  `fetchFilterResults(queryId)` is how you trigger a filter. Both are global.
- `window.bricksData.filterInstances[filterId].currentValue` is the **source of
  truth** for a filter's value. Scalar for `filterType: 'select'`, array for
  `'checkbox'`.
- `bricksFiltersFn` registers instances by reading `element.value`, which is
  `undefined` on our wrapper `<div>`. We seed `currentValue` ourselves on mount,
  otherwise a filter restored from the URL registers as empty.
- Reset, active-filters clear and popstate all write `currentValue` and then
  trigger an AJAX round trip. Rather than intercepting each, we re-sync the UI
  from `currentValue` on `bricks/ajax/query_result/displayed`.
- `BricksFunction` (global, `frontend.js:826`) auto-re-runs on
  `bricks/ajax/query_result/displayed`. Bricks replaces the whole filter DOM node
  after AJAX (`frontend.js:11961`) — but **only for elements in
  `dynamic_update_elements()`**, which excludes ours. So our Zag instances
  survive, at the cost of option counts going stale until reload.

The server-rendered `<select>` inside each element is the progressive-enhancement
baseline. JS hides it on mount and mirrors selections into it, so the filter
still works without the bundle.

### 4. Multi-select values — recovery from the request

`sanitize_filter_value()` (`:1979`) also switches on the element name. Ours
matches no case, so a multi-select array hits the `default` branch and is
collapsed with `reset()` — first value kept, the rest dropped. This happens
inside `set_active_filters()`, which offers no hook.

`Query_Filters_Bridge::recover_multi_value()` goes back to the untouched
request: the filter AJAX payload (captured via `rest_request_before_callbacks`)
first, then the URL parameter.

It has to be applied in **two** places, because the collapse breaks two paths:
query building in the bridge, and the selected state on page load in the element
(`filterValue` is injected downstream of the same call, already collapsed).

## Known limitations

Ordered roughly by how likely they are to bite.

- **`filterSource` = `wpField` / `customField` don't filter.** Only `taxonomy`
  is ported. Such a filter renders and looks normal but silently does nothing.
  See section 3.
- **AND-mode counts are wrong for multi-select.** `set_options_with_count()`
  gates its AND-combine branch on `$this->name === 'filter-checkbox'`
  (`filter-base.php:580`). Filtering is correct — the bridge builds AND clauses
  itself — but counts are computed as though the logic were OR. Filtering and
  counting are separate code paths here. *Not yet verified in practice.*
- **Counts freeze after a filter action.** We're not in
  `dynamic_update_elements()`, so Bricks never returns fresh HTML for our
  element. Page-load counts are right; they don't update as the query narrows.
  Fixable: `Query_Filters::get_updated_filters()` is public static and
  `rest_post_dispatch` is a core hook, so our re-rendered HTML can be injected
  into the response.
- **No date picker yet.** `build_datepicker_query_vars` (`:2799`, ~120 lines) is
  the heaviest port, and Bricks stores the flatpickr instance on the filter
  instance and calls `.clear()` / `.setDate()` on reset — so a Zag date picker
  needs to expose a shim with that shape.

## Testing

`php tests/impersonation-test.php` — stubs the Bricks surface, no WP or DB.
Covers control impersonation, the multi/single mapping, the indexer replay and
taxonomy query var building.

The `Filter_Element` stub mirrors the name-gated branches of the real
`filter-base.php`. **A green run against a stale stub proves nothing** — update
the stub when Bricks changes which controls sit behind which name.

## Conventions

- `Version:` in `plugin.php` is the single source of truth; read via
  `get_file_data()` in `Zag\version()`, never a hardcoded constant.
- Elements register on `init` **priority 20**. Bricks loads `filter-base.php` in
  `Elements::init_elements()` on `init` at priority 10, so anything earlier
  fatals on the missing parent class.
- `pnpm` with a 15-day minimum release age (`.npmrc`).
