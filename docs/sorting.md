# Sorting

A Zag dropdown that sets the order of a query rather than filtering it.

Element name `zag-sort`, class `Zag\Element_Zag_Sort`, in the builder as
**Zag - Sort** under Filters.

## Usage

Add the element, pick the **Target query**, and fill in the **Sort options**
repeater — the same repeater Bricks uses, with a label, a source and a direction
per row. Everything else (label, placeholder, icon, caption, placement, styling)
is inherited from the Zag select and behaves identically.

Values are Bricks' own `<key>_<ORDER>` format: `title_ASC`, `date_DESC`,
`selling_price_DESC`. The key may contain underscores; the direction is taken
from the end.

## How it relates to the select

Bricks models sorting as an **action on `filter-select`/`filter-radio`**, not as
an element of its own (`filter-base.php:1136`). `Element_Zag_Sort` therefore
subclasses `Element_Zag_Filter_Select` and changes only the packaging:

- `filterAction` is forced to `sort` in `render()` and its control removed
- the filter-only controls are dropped rather than hidden — Bricks gates them on
  `[ 'filterAction', '=', [ '', 'filter' ] ]`, a condition that can never be
  evaluated once the control it reads is gone
- `is_multiple()` returns false unconditionally
- the sort options repeater is moved to the top of the panel

It renders `.zag-select` and mounts through `mountSelect`, so there is no second
UI, adapter or stylesheet to keep in step.

**Multi-select is impossible by construction.** A query has one order, and
`get_selected_sort_option()` parses a scalar — an array value would silently stop
sorting rather than error.

## Why the query vars are ours

Sorting is gated twice, both times on the element name:

```php
// query-filters.php:2104
if ( $filter_action === 'sort' ) {
    if ( ! in_array( $instance_name, [ 'filter-select', 'filter-radio' ], true ) ) {
        continue;                                    // ← us
    }
    $query_vars = self::build_sort_query_vars( … );
    $query_vars = apply_filters( 'bricks/query_filters/sort_query_vars', … );
```

The dedicated `sort_query_vars` hook sits **after** that `continue`, so it is
unreachable for a custom element. Sorting therefore rides the same general
`bricks/query_filters/filter_query_vars` hook as every other Zag filter, and
`Query_Filters_Bridge::build_sort_query_vars()` reproduces the private builder.

`get_selected_sort_option()` is public, so only the builder is copied.

One deliberate omission: the native builder rewrites `orderby` as a string when
`$generating_object_type === 'term'`, because `WP_Term_Query` requires it. That
property is private and Bricks supports post queries only for filtering in this
version, so the branch is left out rather than guessed at.

## Sorting is not a filter

Two places treat it as an action rather than a value:

- **Active filter chips skip it.** `filterTargets()` in `src/bricks.js` drops any
  instance whose `filterAction` is not `filter`. A sort is always set to
  something, so there is no cleared state to offer — removing the chip would
  leave the query unordered rather than back at its default.
- **Smart search never sets it.** Same guard, same reason: a typed sentence
  describes what someone is looking for, not what order to read it in.

## Known limitations

- **Results per page is still inert.** The inherited Action control offers it,
  and it is gated identically (`query-filters.php:2127`). It would need the same
  treatment — reproducing `build_per_page_query_vars()`, which unlike the sort
  builder also calls `Filter_Element::get_per_page_options_array()`. Nothing in
  this plugin presents it as supported. The bridge returns early on it with a
  comment saying so.
- **A Zag select can still be switched to Sort** via its own Action control, and
  now works, since the bridge keys off `filterAction` rather than the element
  name. `zag-sort` is the discoverable packaging, not a gate.

## Testing

`php tests/impersonation-test.php` covers the builder: post-field and custom-field
sources, numeric versus text meta, direction taken from the value, override of an
existing `orderby`, clause recording, and five no-op cases. Native sorts are
asserted to pass through untouched.

The stub's `get_selected_sort_option()` is a verbatim mirror of the real method —
the bridge calls the real one in production, so a stub that drifts would make
these tests meaningless.

`element_map()` lives in `inc/element-map.php` precisely so the harness requires
the real map. It used to be duplicated in the test, and the copy went stale the
moment `zag-sort` was added: the bridge skipped the element and every sort
assertion failed. A missing entry fails quietly in production — the element is
simply treated as native.
