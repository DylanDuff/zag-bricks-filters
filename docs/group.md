# Group

A nestable container that reads whatever Zag select/date picker elements are
dropped inside it and hands their current values to an arbitrary JS function
when told to submit. It powers custom UI, not filtering — no query, no
Bricks filter runtime, no `element_map()` entry.

Element name `zag-group`, class `Zag\Element_Zag_Group`, in the builder as
**Zag - Group** under Filters.

This is the "standalone Zag element for custom JS callbacks" that was scoped
and deferred alongside the two filter elements — see `zag-plugin-next-phase`
in project memory for the earlier, abandoned filter-targeting design.

## Usage

1. Drop a **Zag - Group** on the page and nest whichever Zag select/date
   picker elements you want inside it. For a dropdown that only needs to feed
   the group's callback, use [`zag-select`](select.md) (manual/taxonomy/post
   options, no query involved at all) rather than `zag-filter-select` — the
   filter element still needs a Source configured to render anything, even
   with no target query. `zag-filter-select` / `zag-filter-datepicker` work
   too, and **don't need a target query** either — see "Nested elements don't
   need a query" below — useful when you want the date picker specifically,
   or an element that's also filtering a real query elsewhere on the page.
2. Set the group's **Function** control to a global function name, e.g.
   `myApp.onSubmit`.
3. Wire up a trigger, whichever fits your layout — tried in this order on
   every page click:
   1. A native **submit button nested inside the group**. No configuration —
      drop a Bricks button set to render as `<button type="submit">` (or any
      plain `<button>` with no `type` set, since that's the browser's own
      default) anywhere inside the group.
   2. The group's own **Trigger selector** setting — a plain CSS selector
      matched anywhere on the page. For a trigger that can't be nested inside
      the group, or markup you'd rather not restructure.

   The group renders no button of its own; both are opt-in.
4. Clicking that trigger calls your function with the current values of every
   nested Zag element.

| Control | Notes |
|---|---|
| Function | Global function name, dot-path resolved off `window` at submit time, e.g. `myApp.onSubmit`. Called as `fn(values, meta)` |
| Trigger selector | Optional CSS selector. Clicking anything matching it, anywhere on the page, submits this group |

Everything else on the panel — Layout, Background, Border, etc. — is Bricks'
standard container styling, inherited by extending `\Bricks\Element_Container`
the same way Bricks' own Div/Block do. Nothing Zag-specific to style; the group
itself draws no chrome.

## The callback

```js
function onSubmit(values, meta) {
  // values: { [key]: value }
  // meta.entries: [{ key, value, describe, source: { type, slug } }]
  // meta.root: the group's own element
  // meta.trigger: whatever was clicked to submit
}
```

- `values` is a plain object. Each nested Zag element contributes one entry,
  keyed by its DOM id. **Set a custom CSS ID** (Advanced → CSS ID) on a nested
  element for a friendlier key than the default Bricks id.
- A value is whatever that element's adapter reports: a string for a
  single-select, an array of strings for a multi-select or the date picker
  (which returns a one-item array holding a comma-joined ISO string, or an
  empty array). Same shapes the filter elements already use internally — see
  `src/select.js` / `src/datepicker.js`.
- `meta.entries` carries the same data plus:
  - `describe` — each element's own `describe()`, the label(s) it would show
    in a chip, useful if the callback wants to display what was picked rather
    than just act on the raw value.
  - `source` — what kind of thing the value *is*, since a raw value alone (a
    term id, a post id) doesn't say. `{ type, slug }`, where `type` is
    `'manual'` / `'taxonomy'` / `'post'` for a `zag-select`, or Bricks' own
    `filterSource` (`'taxonomy'` / `'wpField'` / `'customField'`) for a
    `zag-filter-select`, and `slug` is the taxonomy name, post type, wpField
    group (`post`/`user`/`term`), or custom field key — whichever applies.
    Both are `null` for a manual list or anything that isn't select-based
    (the date picker holds a date range, not a post/term reference, so
    there's nothing to report).
- Nothing is validated. An empty select reports an empty value; it's the
  function's job to decide whether that's acceptable.

## Nested elements don't need a query

Traced through `src/select.js`: when a `zag-filter-select` has no Bricks
target query, `getFilterInstance()` returns `false`, and every place that
would drive a query (`seedFilterInstance()`, `applyFilters()`) guards on that
and no-ops. The Zag machine, `adapter.getValue()` and `adapter.describe()` all
work regardless. So the same elements work as plain UI controls with zero
changes to them — a select nested in a group is not a different kind of
select, just one nobody told to filter anything.

A nested element *can* also target a real query at the same time — the two
uses aren't exclusive. The group only reads whatever value is currently
showing.

`zag-select` (see [select.md](select.md)) reads the same way: it renders the
identical `data-zag-select` markup and mounts through the same `select.js`, so
`collectValues()` never needs to know which of the two elements it's looking
at.

## How it works

- **No mount, no rendering.** Unlike every other element here, the group
  never repaints and isn't part of the pre-mount "hidden until ready" CSS
  trick — that exists for form controls with a native fallback to hide until
  Zag replaces it, and a plain container has nothing to hide. `src/group.js`
  registers one delegated `document` click listener at module load and does
  nothing else until a matching trigger fires.
- **Reads `mounted` (`src/bricks.js`), never joins it.** That map holds every
  live select/date picker instance, and `collectValues()` filters it to
  `root.contains(instance.root)` at submit time — no server round trip, no
  separate registry to keep in sync. The group itself is deliberately never
  added to `mounted`: that map's `reap()` unconditionally expects an
  `adapter`/`captureState` shape the group doesn't have.
- **The trigger is a plain click listener**, delegated from `document` so it
  works for triggers added after the group mounts. `findTarget()` tries both
  mechanisms in order on every click: a nested
  `button:not([type="button"]):not([type="reset"])` (closest ancestor
  `.zag-group` wins), then each group's own `triggerSelector` (parsed off
  `data-zag-group`, so this is the path that reads every group's config on a
  click that matched no nested button). Whichever matches,
  `event.preventDefault()` follows — the whole point of either is "submit,"
  never "navigate," which matters for a bare Bricks button/link.

## Known limitations

- **Values are read at click time, not tracked live.** There's no "current
  values" event — if you need to react to every change rather than only on
  submit, listen for `zag/filter/change` on the nested elements directly
  (already dispatched by every Zag filter; see the root `CLAUDE.md`).
- **One function per group.** If two triggers need different behaviour for the
  same set of values, give them separate groups, or branch inside the one
  function using `meta.trigger`.

## Testing

Not covered by `tests/impersonation-test.php` — that harness stubs the Bricks
PHP surface, and this element has no server-side query or filter logic to
test. `collectValues()` and `resolveFunction()` are pure apart from the
`mounted` map and `window` lookup and would test well alongside the search
parser once `src/` grows a Node test setup; see `docs/smart-search.md`.
