# Zag Filters for Bricks

Bricks query filter elements whose UI is driven by [Zag.js](https://zagjs.com)
state machines instead of native form controls.

Line references below point into `wp-content/themes/bricks/` and are the first
thing to re-check after a Bricks update.

**They were taken against Bricks 2.1.4 and have drifted** — on 2.4-beta2
`filter_controls_elements()` is at `:862`, not `:707`. Trust the function names,
verify the numbers. The described behaviour has held across both.

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

`element_map()` in `inc/element-map.php` is the single source of truth for which
native element each Zag element stands in for. Note the mapping is also
**per-instance**: a multi-select impersonates `filter-checkbox` (array values), a
single select impersonates `filter-select` (scalar values). See
`Element_Zag_Filter_Select::impersonates()`.

It sits in its own WordPress-free file so `tests/` can require the real map
rather than keep a copy. **A stale copy fails silently**: a missing entry makes
the bridge treat the element as native and skip it, which looks like nothing
happening rather than an error. That is exactly what happened when `zag-sort` was
added.

**`zag-smart-search` and `zag-active-filters` are the exceptions and need none of
this.** Neither holds a filter value — one drives the *other* elements' filters,
the other displays them — so both are deliberately absent from `element_map()`,
which is what keeps them out of every hook in `Query_Filters_Bridge`. Both extend
`\Bricks\Element`, not `\Bricks\Filter_Element`. Not being filters also means
Bricks never DOM-replaces them after a response, so the search input keeps focus
and caret while results update. See `docs/smart-search.md` and
`docs/active-filters.md`.

The smart search's opt-in keyword pass does not change that. It adds query vars
from a hook of its own (`Keyword_Search`, below); the element still registers no
filter and holds no value.

**`zag-group` is a third exception, for a different reason.** It doesn't drive
or display filters at all — it's a nestable container that reads whatever Zag
elements are dropped inside it and hands their values to an arbitrary JS
function on submit, for building UI that has nothing to do with a query. It
extends `\Bricks\Element_Container`, not `\Bricks\Element` — the same base
Bricks' own Div/Block use — which is what makes it nestable and is the first
use of that pattern in this plugin. A nested `zag-filter-select` needs no
target query to work inside a group: see `src/select.js`, where a missing
`filterInstance` is guarded everywhere it would drive a query, but the machine,
`adapter.getValue()` and `adapter.describe()` all still work. See
`docs/group.md`.

**`zag-select` is a fourth exception**, and the reason a nested
`zag-filter-select` needs no target query still isn't enough on its own: the
filter element also unconditionally requires a **Source**
(taxonomy/wpField/customField) before it renders anything, since that's what a
real filter needs to build its query — with none set it shows Bricks' own "No
filter source selected." placeholder regardless of whether a query is
targeted. `zag-select` is the same field with none of that: options come from
a manual list, a taxonomy or a post type, chosen directly, no query or filter
machinery anywhere. The two elements share their field markup and
label/caption/icon/placement/multi-select controls via
`inc/select-shared.php` (`zag_select_controls()`, `render_zag_select()`) —
extracted out of `Element_Zag_Filter_Select`, not duplicated, so **change
either element's field rendering there, not in both files**. See
`docs/select.md`.

The first two are **client-rendered on purpose**. Bricks' own active filters element is
the cautionary example: it gates one-chip-per-value on
`$instance_name === 'filter-checkbox'` (`filter-active-filters.php:280`) and date
formatting on `'filter-datepicker'` (`:406`), and reads a value already collapsed
by `sanitize_filter_value()` — so a Zag multi-select renders as a single, usually
wrong, chip. Reading `filterInstances[…].currentValue` in JS avoids all three,
because that value is never sanitised and an array stays an array.

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

All three filter sources are ported: `taxonomy`, `wpField` and `customField`.

**Sorting is gated the same way, and its own hook is unreachable.** `:2104`
bails on any element that isn't `filter-select`/`filter-radio` and `continue`s —
*before* `apply_filters('bricks/query_filters/sort_query_vars', …)` two lines
later. So sorting rides the general hook too, via
`Query_Filters_Bridge::build_sort_query_vars()`. `get_selected_sort_option()` is
public, so only the builder is reproduced. `zag-sort` subclasses the select and
locks the action; see `docs/sorting.md`. `per_page` is gated identically at
`:2127` and is deliberately left inert.

The native builders branch on `instance_name === 'filter-checkbox'` to pick
array-vs-scalar semantics. Our name never matches, so every such branch keys off
`is_multi()` (the `zagMultiple` setting) instead. Three places depend on it:
`post_author` choosing `author__in` over `author`, the default compare operator
(`IN` vs `=`), and the AND-logic clause splitting.

Each builder must write its clause back onto
`Bricks\Query_Filters::$active_filters[$query_id][$index]` as `query_vars` /
`query_type`. `set_options_with_count()` reads those off *sibling* filters to
compute counts that account for what else is active; skipping the write-back
gives every other filter on the query wrong counts.

## Frontend

`src/` → `assets/js/zag-filters.js` via esbuild (`pnpm build`). The bundle **is
committed** so the plugin works from a plain checkout.

| Module | Role |
|---|---|
| `zag-filters.js` | Entry: one `BricksFunction` per element type |
| `bricks.js` | Everything touching Bricks' filter runtime — registry, lifecycle, value plumbing |
| `select.js` | Select machine, trigger and listbox |
| `datepicker.js` | Date picker machine, calendar views, flatpickr shim |
| `search.js` | Smart search: parses a sentence and drives the filters above |
| `active-filters.js` | Chips for whatever those filters currently hold |
| `group.js` | Reads nested Zag elements' values into an arbitrary JS callback on submit |
| `link.js` / `link-core.js` | Linked selects: hides options in a group's taxonomy selects that share no posts with a sibling's pick |
| `builder-preview.js` | The Bricks builder canvas opt-in — see "Invisible in the builder canvas" below |

Note `src/bricks.js` is **ours** — the adapter layer named for what it talks to.
Bricks' own filter runtime is `themes/bricks/assets/js/filters.js`, and nothing
in this plugin modifies the theme.

Both filter elements share one lifecycle and differ only in how a value is read
from and written to their machine, which each supplies as an **adapter**
(`getValue` / `setValue` / `describe` / `captureState` / `restoreState`). Add a
third element by writing a mount function and an adapter; don't duplicate the
lifecycle.

`describe()` is what keeps element-specific labelling out of the chips element:
a select returns one descriptor per selected value, the date picker returns one
for the whole range. `icon()` is the same idea for chrome — it hands back the
element's leading icon node so a chip can clone the glyph.

`search.js` and `active-filters.js` are not filters and have no adapters. They
consume the others through three exports:

- `filterTargets(queryId)` — every live Zag filter, read from `mounted`. Call it
  fresh each time; targets are new objects after every response. `options` is
  empty for the date picker, so `search.js` filters those out and
  `active-filters.js` keeps them.
- `driveFilters(plan)` — set several filters at once, **one request per query**.
  The write-then-repaint order is load-bearing; the reason is commented there.
- `applyFilters(changes)` — the same, without the repaint. What `applyFilter`
  wraps for the single-filter case.

The date picker exposes a flatpickr-shaped object on
`filterInstance.datepicker`, because `resetFilterValue` (`filters.js:256`) calls
`.clear()` and `.setDate()` on it — and **bails early if it's missing**, so
without the shim reset would silently not clear the date rather than error.

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

**Pre-mount state.** An inline script at `wp_head` priority 1 puts `zag-js` on
`<html>` before first paint; `register()` then sets `data-zag-ready` on each root
as it mounts. Between the two, the stylesheet holds the native control at
`visibility: hidden` with the field's box geometry — invisible, but still
occupying the space the mounted control will take.

`visibility`, not `display: none`: hiding it outright collapses the box and makes
everything below it jump when the real control arrives, which is the whole point.
Painting nothing also avoids having to match the field's appearance, and takes
the multi-select's list-box **scrollbar** with it — that cannot be styled away,
so any approach that leaves the element painted will show it. `height` rather
than `min-height`, because a `<select multiple>` otherwise reserves several rows.

No fade: the boxes are identical, and Bricks replaces our filter nodes on every
response, so an entry animation would re-run on every filter click.

The script is inline and unconditional because it must beat first paint, and
Bricks renders elements during the body — at `wp_head` there is no way to know
whether the page has one. Without JS the class never appears and the native
controls render untouched.

### Invisible in the builder canvas

Every Zag element rendered as **nothing** in the Bricks builder — not
non-interactive, not unstyled, just blank space — until this was diagnosed and
fixed. Two independent, individually reasonable choices collided:

1. `zag-filters.js`'s `BricksFunction` runners set `frontEndOnly: true`,
   copying the pattern Bricks' own interactive elements use to avoid fighting
   the canvas' click-to-select handling. Bricks enforces that flag by checking
   for a `bricks-is-frontend` **body class**, which never exists in canvas —
   `bricks_is_frontend()` is literally `!bricks_is_builder()`
   (`themes/bricks/functions.php:260`) — so `run()` no-ops there
   (`frontend.js:1472`) and the bundle never mounts at all.
2. The pre-mount CSS above hides the native control unconditionally once
   `.zag-js` is set, and that script (above) is printed **unconditionally**,
   builder included.

Point 1 alone would just make the elements static previews of their native
fallback — reasonable. Combined with point 2, the native fallback is hidden
and nothing ever un-hides it, so canvas shows nothing.

**The fix is a per-element mount decision, not a binary one.** The same
`wp_head` callback also prints `zag-builder` on `<html>` when
`bricks_is_builder_iframe()` is true (the canvas request specifically, not the
builder's outer page). Every Zag element additionally carries its own
**"Preview in builder" checkbox** (`inc/builder-preview.php`,
`zagBuilderPreview` setting), which — only inside canvas —
`apply_builder_preview_attribute()` turns into a `data-zag-preview` attribute
on that one instance's root:

- Real frontend (`zag-builder` absent): mount and hide-until-ready, as always.
  `data-zag-preview` is never rendered here at all.
- Canvas, box unchecked: **don't** mount, and the stylesheet skips the
  pre-mount hide for that element (`assets/css/zag-filters.css`'s pre-mount
  rules all read `:not(.zag-builder)` / `.zag-builder[data-zag-preview]`), so
  the plain native control shows instead of nothing.
- Canvas, box checked: mount that one instance for real — the actual styled
  UI, fully interactive, including firing real filter AJAX requests on click.

It's a **per-element** setting rather than a page-wide toggle on purpose: an
editor usually wants to preview the one control they're actively styling, not
turn every Zag element on the page into a live, request-firing control while
editing something unrelated.

`makeRunner()` (`zag-filters.js`) sets `frontEndOnly: false` and gates
`eachElement` on `shouldMount(element)` (`builder-preview.js`) instead, since
`frontEndOnly: true` blocks `run()` itself — gating inside `eachElement` alone
would never be reached. `shouldMount()` just checks
`element.hasAttribute('data-zag-preview')` once `.zag-builder` says we're in
canvas at all.

`zag_builder_preview_control()` is merged into every element's controls the
same way the other shared control builders are: through `zag_select_controls()`
for the two select-shared elements (`inc/select-shared.php`, so
`zag-filter-select`, `zag-sort` and `zag-select` all get it from one place),
and added individually in `filter-datepicker.php`, `smart-search.php` and
`active-filters.php`. **Not** on `zag-group` — it renders no native/enhanced
dual state of its own to preview, so the control would do nothing there.

**A second, independent bug hid behind the first one.** With the box checked,
`data-zag-preview` was rendering correctly and `.zag-builder` was present, but
elements still never mounted — no console error, `window.bricksFunctions`
showed the runners registered with the right `frontEndOnly: false`, and
calling `.run()` on one by hand from the console mounted it immediately. So
the mount logic itself was fine; `run()` just never got called automatically.

First hypothesis, disproven live rather than assumed: that `startLifecycle()`
(`bricks.js`) only ever *listening* for `DOMContentLoaded` was the problem,
because that event had supposedly already fired in canvas by the time the
script runs. Checking `performance.timing` in the canvas iframe's own console
context ruled that out directly —
`domContentLoadedEventEnd - navigationStart` and `loadEventEnd` were both
normal, few-second values well after `navigationStart`, meaning both events
fire on the ordinary timeline. The `readyState !== 'loading'` immediate-run
fallback shipped anyway (free insurance, and it's what the real frontend
relies on), but it wasn't the actual fix.

**The real cause**, found by ruling out everything else in `functionCanRun()`
(only `frontEndOnly` and `windowVariableCheck` gate it — `windowVariableCheck`
was empty, so neither applied) and confirming `window.bricksFunctions` showed
the runner registered with the right settings: `_initializedElements` on the
`BricksFunction` instance stayed an empty `Set` even after DOMContentLoaded
and load had both fired. Bricks' own `run()` adds an element to that set
*before* calling `eachElement` — unconditionally, regardless of what our own
`_customEachElement` decides — so an empty set means
`document.querySelectorAll(selector)` found **zero matches** every time
`run()` executed automatically. Calling `.run()` by hand afterward mounted it
immediately, with the element easily found.

Conclusion: canvas populates an element's actual markup into the DOM on a
timeline of its own, decoupled from the page's own load events — some
client-side render pass with no equivalent on the real frontend, where the
server-rendered HTML is simply already there by any of these events. None of
the four Bricks events already subscribed to
(`bricks/ajax/pagination/completed`, `load_page/completed`, `popup/loaded`,
`query_result/displayed`) correspond to whatever that moment actually is, and
it wasn't worth chasing further given a robust alternative exists.

**Fix**: a debounced `MutationObserver` on `document.documentElement`
(`childList`, `subtree`), scoped to canvas only via `isBuilderCanvas()`
(`builder-preview.js`) — the real frontend has never shown this problem, so it
doesn't pay for watching every DOM mutation on the page. Timing-agnostic by
construction: it doesn't matter when or how canvas injects the markup, only
that a mutation happens, at which point `run()` fires again. Safe to call
repeatedly — `eachElement`'s own dedup guard (`registry.has(element)`) skips
anything already mounted, so this never double-mounts or fights the
query-result listener below it.

**Diagnosing this class of bug** (element in canvas, `data-zag-preview`
present, no console errors, nothing mounts) — all done from the canvas
iframe's own console context (DevTools' frame dropdown, or `Inspect` from a
right-click inside canvas):

1. `window.bricksFunctions.filter(f => f?._settings?.selector?.includes('zag'))`
   — confirms the runners exist with current settings (rules out a stale
   cached bundle; check `frontEndOnly` reads `false`).
2. Grab one instance and check `._initializedElements` — empty means
   `run()` executed but found nothing, which is the signature of this bug.
3. Call `.run()` on it directly. If that mounts the element, the mount logic
   itself is fine and the bug is purely about *when* `run()` gets called.
4. `performance.timing` (as an object, not three separate statements — only
   the last one's value survives a multi-line console paste) rules
   DOMContentLoaded/load timing in or out as the cause, rather than assuming.

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

### 5. Refreshing counts — re-rendering after dispatch

`get_updated_filters()` (`:3013`) checks `dynamic_update_elements()` against the
page-data element name, so it skips us and our counts would freeze after the
first filter action. `Query_Filters_Bridge::inject_updated_filters()` re-renders
our elements on `rest_post_dispatch` and adds them to the response's
`updated_filters`, leaving native filters to Bricks.

Counts are correct at that point because `Query_Filters::$active_filters` is
still populated, which is what `set_options_with_count()` reads.

The consequence is that our elements now go through Bricks' DOM replacement
(`frontend.js:11961`), which destroys the Zag instance. `reap()` in
`zag-filters.js` tears down orphaned instances on each response and carries open
and highlighted state across via `pendingRestore` — without that, a multi-select
menu (which stays open across a selection) slams shut mid-interaction.

### Gotcha: reading elements must use `onFiltersSettled`, not the raw event

`startLifecycle()` adds its `bricks/ajax/query_result/displayed` listener **when
it is called**, at the bottom of `zag-filters.js` — not when `bricks.js`
evaluates. Every imported module has therefore already evaluated, so a
module-level listener anywhere in `src/` lands **ahead** of the one that runs
`reap()` and `resync()`.

For an element that reads the filters rather than being one, that means
`filterTargets()` returns instances whose roots Bricks has already discarded:
stale machines, stale `filterInstance` objects, stale values. The render paints
the *previous* state, so the UI sits exactly one interaction behind.

The tell is nasty, because the immediate `zag/filter/change` render is correct
and the stale one overwrites it a moment later: the chip vanishes and returns,
which reads as "clearing doesn't work" rather than "renders twice". It only
appears to fix itself on the next interaction, when the stale render finally
catches up to the change before it.

Use `onFiltersSettled(callback)` from `bricks.js`. It runs after reap, resync and
remount, which is the only point at which `mounted` describes the live page.

### Gotcha: never style :focus on a focusable element

Bricks ships this on the frontend:

```css
body.bricks-is-frontend.bricks-is-frontend :focus { outline: none; box-shadow: none }
```

At **(0,3,1)** it strips the shadow from whatever actually holds focus. Any
border or focus ring placed on a trigger, button or input therefore disappears
the moment it is focused, and returns when focus moves away — which reads as an
intermittent styling glitch rather than a cascade problem.

Both elements put their field box on the **control wrapper** instead. A `div` is
only ever `:focus-within`, never `:focus`, so the rule cannot reach it. The
trigger is a transparent hit area with no visuals of its own.

Don't try to out-specify it. If a focus style is needed, move it to a
non-focusable ancestor.

**It catches the popovers too.** Zag makes both focusable — the select menu
carries `tabindex="0"`, the calendar `tabindex="-1"` — and focuses them on open,
so their shadow was stripped for exactly as long as they were open. The
diagnostic tell is that blurring the browser window restores it, because
`:focus` stops matching.

There's no non-focusable wrapper to move that one to (the positioner is unstyled
and the transition lives on the surface), so it's `!important` on the
declaration — with the value still resolving through `--zag-popover-shadow`, so
the style control keeps working.

Bricks pairs it with an outline on `:focus-visible` at the same specificity.
That one *is* suppressed with `!important`, but only on the field-level controls
(trigger, input, icon button), because their wrapper already draws the focus
ring. The calendar's day cells and nav buttons keep it — they sit in the popover
with no wrapper of their own.

### Gotcha: Bricks' icon colour control recolours our chrome

Setting an icon colour emits a blanket `svg` rule scoped to the element, which
catches everything we draw in SVG, not just the user's icon. Guarded so far:

| Element | Pinned to |
|---|---|
| `.zag-select__indicator svg` (chevron) | `--zag-fg` |
| `.zag-select:not([data-zag-multiple]) .zag-select__check svg` | `--zag-accent` |
| `.zag-select[data-zag-multiple] .zag-select__check svg` | `--zag-check-color`, default `#fff` |

The tick is split by mode deliberately — single is an accent tick on the menu
background, multi is a white tick inside the filled accent checkbox, so one rule
for both fixes one and breaks the other.

Pin to an **explicit colour, never `currentColor`**: the blanket rule may set
`color`, which `currentColor` would happily inherit, leaving a guard that looks
right and does nothing.

**The root cause is a missing `css` key, not the control itself.**
`Assets::generate_css_from_controls()` (`assets.php:1662`) gives an `icon`
control that declares no `css` of its own a default rule scoped to `svg` —
element-wide. Declaring a selector suppresses that default entirely:

```php
'css' => [ [ 'selector' => '.zag-datepicker__icon > *, .zag-datepicker__iconbtn > *' ] ],
```

The date picker's `zagIcon` does exactly that, which is why it can offer an icon
control without recolouring its nav arrows — whose hover colour change would
otherwise be pinned flat. Target the glyph (`> *`, covering `<svg>`, `<img>` and
an icon-font `<i>`) rather than the slot, so the control's width, height and
colour land where Bricks' own default would have put them.

The select still uses the guard table above instead, because it predates this
finding. Giving `Element_Zag_Filter_Select::$controls['zagIcon']` a scoped `css`
key would make all three guards redundant — worth doing, but it is a behaviour
change to shipped elements, so it hasn't been done blind.

### Gotcha: the positioner must never take pointer events

`render()` keeps the positioner mounted while closed (`removeAttribute('hidden')`)
so the menu's close transition can play, and the menu hides with `visibility`
rather than `display` so it keeps its layout box. The result is a full-size,
absolutely positioned, invisible box sitting over whatever follows the select.

Without `pointer-events: none` on `.zag-select__positioner`, that box silently
swallows clicks on anything beneath it — a reset button placed under a filter
simply stops working. It only manifests **after the dropdown has been opened
once**, because Zag applies the positioner's inline absolute positioning on
first open, so a freshly loaded page behaves fine. That asymmetry makes it look
like a filter-state bug rather than a stacking one; it cost a long debugging
session.

If anything below a Zag element ever stops responding to clicks, check
`document.elementFromPoint()` over it before suspecting filter state.

## Keyword search — the one place this plugin writes a query

`inc/keyword-search.php`, behind the smart search's **Also search post content**
setting. Off, nothing in this file runs. On, the typed sentence stops being only
a lookup key for sibling filters and becomes a query condition, and the results
are the union of the two:

```
posts matching the active filters  ∪  posts matching the typed words
```

**The union has to be computed, not expressed.** Taxonomy and custom field
filters compile to `INNER JOIN`s, so `OR ID IN (…)` in the `WHERE` cannot rescue
a row the join already dropped. Two `fields => 'ids'` queries and a `post__in` is
the only shape that answers correctly — and the reason this is an opt-in setting
rather than the default.

Hooks `bricks/posts/query_vars` at **priority 1000**, one past the merge both
Bricks paths register at 999 (`query-filters.php` for page loads, `api.php` for
filter requests) and comment must come last. Running after it is what makes
`Query_Filters::$query_vars_before_merge[$query_id]` — the query element's own
vars, before any filter — available to rebuild from. Rebuilding from the merged
set instead would AND the filters back in and lose everything matched by words
alone.

Two rules that are decisions, not fallout:

- **Nothing constraining the query ⇒ plain `s`.** A union against an
  unconstrained filter set is every post. `has_constraining_filters()` reads
  `query_type` off `$active_filters` and treats `sort`, `per_page` and
  `pagination` as narrowing nothing; a filter left empty never gets a
  `query_type` at all.
- **Everything active sits on the left of the OR**, hand-picked values included.
  Ownership tracking decides what the search may *clear*, not what the query
  means.

**The term travels by URL, because the element has no other channel.** It is not
a filter, so nothing of it rides in `selectedFilters`. On a page load that is
`$_GET`; on a filter request it is the request's `urlParams` (Bricks 2.3.8+),
which is why `syncUrl()` runs before any fetch in `run()`. Older Bricks falls
back to the referrer, which `replaceState` keeps current.

**A sentence matching no option label must still fetch.** It changes no filter,
so `driveFilters()` never fires and nothing would refresh. `run()` tracks the
last sentence searched for and calls `fetchQueries()` for the queries
`driveFilters()` did not cover.

Element discovery is a page-data scan (`page_elements()`), because the settings
live on a sibling element the query knows nothing about. It does not walk
component instances, so a smart search built into a component is not found.

### Gotcha: anything added here must be kept out of `data-original-query-vars`

`base.php:4246` renders that attribute from
`Query_Filters::$query_vars_before_merge`, and the frontend treats an empty one
as "unknown" and falls back to `data-query-vars` — the **final** vars, ours
included (`frontend.js:13311`). Whatever it lands on is posted back as
`originalQueryVars` on every later request, where
`restore_original_query_vars_from_frontend()` reinstates it.

Bricks only populates `$query_vars_before_merge` from its own merge callback,
and `add_active_filters_query_vars()` does not register one when no filter is
active. A page loaded on `?zag-search=…` alone therefore shipped the keyword
clause back as the query's *original* state and replayed it forever: **the
search could not be cleared**. So `apply()` writes the property itself before
touching anything, which is exactly what it means.

The tell is that it only reproduces from a **refresh** with the term already in
the URL. Typing into a freshly loaded page is fine, because the AJAX path in
`api.php` registers that callback unconditionally and gets it right by itself —
which reads as a clearing bug rather than a page-render one.

Any future hook that adds query vars past priority 999 inherits this. Check the
attribute before assuming a stuck filter is a JS problem.

## Known limitations

- **The date picker is date-only, by design.** The query layer fully supports
  time (hour/minute components for `wpField`, `DATETIME` for `customField`) and
  that code is tested and kept, but Zag has no time widget so the UI never
  produces one. The `enableTime` control is therefore **deliberately not
  exposed** — offering it would let someone change the comparison semantics
  without changing what they can pick. Re-add the control together with a time
  input, never on its own.
- **The bundle is one file, ~179kb minified.** Pages using only a select still
  download the date picker. Splitting per element is straightforward if that
  matters.
- **The chips element covers Zag filters only.** Labels come from our elements'
  own option config; a native Bricks filter on the same page would need its
  label read out of the DOM, per filter type.
- **Smart search can't drive a date picker.** It matches words against option
  labels, and a calendar has none. Natural-language dates ("next week") would
  need a parser and a different adapter path; the reconciler already skips date
  pickers so they are never wrongly cleared.
- **Smart search vocabulary is per element instance.** Two search elements on
  two pages don't share a synonym table. If one table ever needs to serve a
  whole site, move it behind a filter hook rather than duplicating rows.
- **Keyword search costs two unbounded ID queries per request**, and option
  counts don't reflect the union — they still describe the filtered half only,
  because `set_options_with_count()` reads the active filters, which the union
  never touches. Fine for a directory; think before enabling it over tens of
  thousands of posts.

## Testing

`php tests/impersonation-test.php` — stubs the Bricks surface, no WP or DB.
Covers control impersonation, the multi/single mapping, the indexer replay,
query var building for taxonomy, wpField, customField, dates and sorting, and
the keyword search's two pure decisions (union vs. plain `s`, and which query
vars survive the rebuild around `post__in`).

The `Filter_Element` stub mirrors the name-gated branches of the real
`filter-base.php`, and the `Query_Filters` stub mirrors
`get_selected_sort_option()` verbatim — the bridge calls the real one in
production. **A green run against a stale stub proves nothing** — update the
stubs when Bricks changes which controls sit behind which name, or how a sort
value parses.

`node --test tests/link-core.test.mjs` covers the linked-select rule
(`src/link-core.js`), which was split from its DOM shell (`src/link.js`) for
exactly this reason. `pnpm test` runs both suites.

**The rest of the frontend has no tests.** The smart search's matching, vocabulary
and reconciliation are pure functions and would run under `node --test` with no
new dependencies, but they currently share a module with the DOM wiring, which
touches `document` at evaluation. Splitting `src/search.js` into a pure
`search-parse.js` plus a DOM shell is the prerequisite — see
`docs/smart-search.md` for the cases worth covering.

## Conventions

- **Never give a checkbox `default => true`.** `default` is a builder-UI
  affordance and never reaches `$settings`, so an unchecked box and an untouched
  one are indistinguishable — a default-on option can't be switched off, and any
  `!isset(…) || …` fallback written to preserve the default makes the off state
  unreachable. Phrase boolean options as the exception instead (*Uncapped menu
  height*, *Auto menu width*, *Hide label visually*), so unset is the default
  behaviour and the read is a plain `! empty()`. Bricks does the same with
  `filterHideEmpty` and `hideOnNoFilter`.

- `Version:` in `plugin.php` is the single source of truth; read via
  `get_file_data()` in `Zag\version()`, never a hardcoded constant.
- Elements register on `init` **priority 20**. Bricks loads `filter-base.php` in
  `Elements::init_elements()` on `init` at priority 10, so anything earlier
  fatals on the missing parent class.
- `pnpm` with a 15-day minimum release age (`.npmrc`).

## Releases and updates

Installed copies update over the air from GitHub releases, the same way as
wp-toolkit. `inc/updater.php` wires the vendored Plugin Update Checker
(`plugin-update-checker/`, upstream v5.6, unmodified; don't edit it) to this
repository's `main` branch with release assets enabled.

```bash
bash release.sh patch "Changelog entry"   # or minor|major|x.y.z
```

Or, with no local `gh` login (agents included), start the **Release** workflow
(`.github/workflows/release.yml`) with inputs `bump` and `changelog` (one entry
per line): from the Actions tab, `gh workflow run release.yml -f bump=patch -f
changelog="…"`, or the GitHub MCP `actions_run_trigger` tool. It runs this same
script on a runner with the workflow's own token, so there is one release path,
not two.

Run from a clean `main`. It rebuilds the bundle and runs the PHP tests first,
refusing to release a stale `assets/js`, then bumps `Version:` in `plugin.php`,
commits and pushes, and creates a `vX.Y.Z` GitHub release with
`zag-filters-for-bricks-X.Y.Z.zip` attached. Needs `gh` (authenticated) and
`zip`.

Only `plugin.php`, `assets/`, `inc/` and `plugin-update-checker/` are packaged.
The zip's top-level folder is the plugin slug, so it must stay
`zag-filters-for-bricks` or WordPress installs the update beside the old copy.

The repository (`DylanDuff/zag-bricks-filters`) is public, so sites need no
token. If it is ever private, each site needs a read-only token (fine-grained,
*Contents: read*) as `define( 'ZAG_GITHUB_TOKEN', '…' );` in `wp-config.php` or
the `ZAG_GITHUB_TOKEN` environment variable, or GitHub returns 404 and no update
appears. Never commit it.

The repository name and the plugin slug differ on purpose. The slug is the
installed folder name, so it stays `zag-filters-for-bricks`.
