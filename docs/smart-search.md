# Smart search

A search field that **sets the other Zag filters** instead of filtering
anything itself. Someone types *"a podiatrist in Penrith who accepts NDIS"* and
the Podiatry, Penrith and NDIS options select themselves in whichever filters
carry them.

Element name `zag-smart-search`, class `Zag\Element_Zag_Smart_Search`, in the
builder as **Zag - Smart search** under Filters.

Modelled on `ref/search/search.html`.

## Usage

1. Drop the element anywhere on a page that already has Zag filters.
2. That's it.

There is no target to configure. On mount it reads every live Zag filter from
the shared registry, learns every option label they offer, and starts matching
against them. Adding, removing or reconfiguring a filter later needs no change
here.

Fill in the **Vocabulary** repeater only for words that are *not* themselves
option labels.

### What matches with no configuration

Every option label on the page, matched from the **start of a word**:

| Typed | Matches |
|---|---|
| `penrith` | the option labelled "Penrith" |
| `physio` | "Physiotherapy" — word-start, so a prefix is enough |
| `myphysio` | nothing — not a word start |
| `Penrith (4)` | the count is stripped before matching, so counts never interfere |

Case, punctuation and extra whitespace are all normalised away. A label that
exists on more than one filter selects the option in **each** of them.

### The Vocabulary repeater

Two fields per row, both comma separated:

| When they type | Select these options |
|---|---|
| `bunion, heel, plantar, ingrown` | `Podiatry` |
| `gold card, veteran, white card` | `DVA` |
| `blue mountains, the mountains` | `Blaxland, Springwood, Katoomba` |
| `my mum, my nan, elderly, pensioner` | `Older adults` |

**Targets are option labels, not values.** A taxonomy option's value is a term
id, which is not something an editor can see or type; the label is. Labels are
matched leniently (case and punctuation insensitive) but must otherwise be the
whole label — `Podiatry` matches the option "Podiatry", not "Podiatry services".

A row whose target matches no option on the page logs a console warning naming
the label, rather than failing silently. Check that first when a row seems
inert.

One row targeting several labels is how the prototype's `LOC_GROUPS` behaviour
is expressed: *"blue mountains"* selecting three suburbs at once.

### Controls

**Functionality**

| Control | Notes |
|---|---|
| Label | Empty uses the placeholder as a screen-reader-only name |
| Hide label visually | Still announced |
| Placeholder | A worked example reads best — it teaches the feature |
| Limit to query | Empty drives every Zag filter on the page. Set it only when a page carries two independent filtered queries |
| Icon | Replaces the built-in magnifier |
| No icon | Hides it entirely |
| Show interpretation hint | The "Read as **Podiatry** · **Penrith**" line |
| Hint: prefix / Hint: no match | Wording for the above |
| URL parameter | Defaults to `zag-search` |

**Style** — the shared palette and field controls, plus icon size/colour/gap and
the two hint colours. The popover controls are absent: this element has no
popover.

## Behaviour worth knowing

**It only owns what it set.** Selections made by hand in the dropdowns are left
alone — a new sentence won't clear them, and neither will emptying the search
box. Only the values the search itself last applied are withdrawn. This is a
deliberate divergence from the prototype, which replaces the whole filter state
on every keystroke.

**One request per query, not one per filter.** A sentence setting three filters
issues a single AJAX round trip. See `driveFilters()` in `src/bricks.js`.

**Typing is debounced 300ms.** Enter applies immediately; Escape clears the
field and applies.

**The sentence survives a reload.** It is kept in the URL (`?zag-search=…`) and
re-rendered into the field server-side, so a shared or bookmarked link restores
both the results and the words that produced them. Back and forward work.

**Single-select filters take the sentence's answer.** With one slot to give, a
freshly typed match wins it; a manual choice stands only while the sentence
resolves to nothing for that filter.

**Date pickers are never touched.** They offer no options to match against, so
they are skipped — including by the ownership reconciler, which will never clear
a date it could not have set.

## Why this element is structurally simpler than the others

It holds **no filter value**, which exempts it from everything documented in the
root `CLAUDE.md` about impersonating native filter elements. It is absent from
`Zag\element_map()`, and every hook in `Query_Filters_Bridge` keys off that map,
so:

- nothing is written to the filter index
- no query vars are built for it
- `sanitize_filter_value()` never sees it
- Bricks never re-renders it after a filter response — which is precisely what
  lets the input keep focus and caret position while the results update

It extends `\Bricks\Element`, not `\Bricks\Filter_Element`.

## How it works

`src/search.js`, roughly in order:

1. **Discover** — `filterTargets(queryId)` (`src/bricks.js`) walks the `mounted`
   registry and returns every live Zag filter that offers options, each with its
   options, its `multiple` flag, and functions to shape and repaint a value.
   Called fresh on every parse: Bricks replaces filter nodes after each
   response, so cached targets go stale immediately.
2. **Build the vocabulary** — option labels first (one phrase each), then the
   authored synonym rows, resolved against those same labels.
3. **Parse** — normalise the sentence to a space-padded haystack and test each
   phrase for a leading space. No regex; same approach as the prototype's
   `hasTrigger`.
4. **Reconcile** — diff what the sentence wants against what each filter holds,
   subtracting the values this search set last time to find what the visitor set
   by hand.
5. **Apply** — `driveFilters()` writes every `currentValue`, registers each with
   `updateSelectedFilters`, fires one `fetchFilterResults` per distinct query,
   then repaints the UIs.

Step 5's ordering is load-bearing and commented in `bricks.js`: repainting a
machine fires its own change callback back into `applyFilter`, which no-ops only
because `currentValue` already holds the new value. Repaint first and the fetch
is skipped entirely.

## Testing

Not covered by `tests/impersonation-test.php` — that harness stubs the Bricks
PHP surface, and all of this logic is JS.

The matching, vocabulary and reconciliation functions are pure and would test
well with `node --test` and no new dependencies, but they currently sit in the
same module as the DOM wiring, which imports `document` at evaluation. Splitting
`src/search.js` into a pure `search-parse.js` plus the DOM shell is the
prerequisite. Cases worth covering when that happens:

- word-start matching, including the `myphysio` negative
- count-bearing labels matched via `match`
- one label present on two filters selecting both
- a synonym row expanding to several options
- ownership: a manual selection surviving a new sentence and an emptied field
- single vs multi slot behaviour
