# Select

The same Zag select UI as `zag-filter-select` — trigger, listbox, single or
multi-select — with no query and no filter involved. Options come from a
manual list, a taxonomy, or a post type, chosen directly on the element.

Element name `zag-select`, class `Zag\Element_Zag_Select`, in the builder as
**Zag - Select** under Filters.

## Why this exists instead of a fourth `filterSource`

`zag-filter-select` unconditionally requires a Source (taxonomy/WP
field/custom field) before it renders anything — that's also what a real
filter needs to build its query, so the two are entangled. With no source it
shows Bricks' own "No filter source selected." placeholder
(`inc/elements/filter-select.php:281-287`), which is exactly what a select
dropped in a [`zag-group`](group.md) showed before this element existed: there
was no way to give it options without pretending to filter something.

`zag-select` is the fix: a separate element with none of `Filter_Element`'s
machinery (no `filterQueryId`, no indexing, no impersonation, no
`data-brx-filter`), so it can't accidentally end up half-configured as a
filter. It's the natural control to nest inside a `zag-group`, though nothing
stops it being used standalone.

## Usage

| Control | Notes |
|---|---|
| Source | **Manual**, **Taxonomy**, or **Posts**. Determines which of the controls below apply |
| Options *(Manual)* | Repeater of Label/Value rows. Value defaults to the label when left empty |
| Taxonomy *(Taxonomy)* | Any registered taxonomy. Options are its terms, `term_id` as the value |
| Hide empty terms *(Taxonomy)* | Off by default, same as a manual list — nothing to "count" here since there's no query |
| Post type *(Posts)* | Any public post type. Options are its published posts ordered by title, post ID as the value |

Everything else — Label, Caption, Icon, Multi-select, Counter label,
Placeholder, Placement, menu sizing, **Preview in builder**, and the whole
Style tab — is identical to `zag-filter-select`, because it's the same code:
see "How it works" below. "Preview in builder" is what mounts the real,
interactive UI in the Bricks builder canvas rather than the plain native
fallback — see CLAUDE.md's "Invisible in the builder canvas" for why that
distinction exists at all.

**No persisted value.** Unlike the filter element, nothing restores a
selection on load — there's no query state to restore it from. The field
always starts empty (or with nothing checked, for multi-select).

## How it works

`inc/select-shared.php` holds the two things `zag-filter-select` and
`zag-select` share, extracted out of the filter element rather than
duplicated:

- `zag_select_controls()` — the label/caption/icon/multi-select/placement/menu
  controls, identical settings keys in both elements.
- `render_zag_select()` — builds the `data-zag-select` config and renders the
  field markup (label, icon, native `<select>` fallback, caption), given a
  normalized `$options` list and `$current_value`. Neither element's specific
  logic — how options are sourced, whether a value persists — lives here.

**No JS changes were needed.** `src/select.js` was already source-agnostic: it
mounts from `data-zag-select` JSON and never asks how `items` was built.
`zag-filters.js`'s existing runner
(`makeRunner('.zag-select[data-zag-select]', mountSelect)`) matches on class
and attribute alone, so it picks up a `zag-select` element the same way it
picks up a `zag-filter-select` — because `render_zag_select()` renders the
exact same markup for both. Same reasoning as `zag-group` nesting a
`zag-filter-select` with no target query: `getFilterInstance()` returns
`false` when there's no `data-brx-filter`, and every place that would drive a
query guards on that and no-ops.

Options are rebuilt on every render — there's no index or query history to
read back, unlike a filter's `get_populated_options()`.

`render_zag_select()` also takes an optional `$source_type` / `$source_slug`
pair, which each caller derives from its own settings (`source_type()` here;
`filterSource`/`filterTaxonomy`/etc. for `zag-filter-select`) and which lands
in `data-zag-select` as `sourceType`/`sourceSlug` verbatim. `zag-group` reads
those off the mounted instance's `config` to tell its callback what kind of
thing a value is — see `docs/group.md`'s "The callback".

## Known limitations

- **No hierarchy for taxonomy terms.** Every term renders at depth 0, flat —
  `zag-filter-select` shows parent/child indentation because it's driven by
  Bricks' own taxonomy query args (`hierarchical`, include/exclude, order);
  none of that is exposed here yet.
- **No counts.** There's no query to count against, so options never show a
  result count the way a real filter's can.
- **Posts source has no further filtering.** All published posts of the
  chosen type, ordered by title. No taxonomy/meta narrowing, no limit control.
- **`zag-filter-datepicker` has the same "No filter source selected." gate**
  and would hit the same problem nested in a group. No standalone date picker
  equivalent exists yet — only the select was asked for.

## Testing

Not covered by `tests/impersonation-test.php` — that harness stubs Bricks'
`Filter_Element` surface specifically, and this element doesn't extend it.
`options_from_manual()`/`options_from_taxonomy()`/`options_from_posts()` are
otherwise straightforward WP data mapping, not filter logic.
