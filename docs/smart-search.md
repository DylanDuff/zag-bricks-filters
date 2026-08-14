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
| Also search post content | Opt-in. The typed words become a query condition of their own — see below |
| Show interpretation hint | The "Read as **Podiatry** · **Penrith**" line |
| Hint: prefix / Hint: no match | Wording for the above |
| URL parameter | Defaults to `zag-search` |

**Style** — the shared palette and field controls, plus icon size/colour/gap and
the two hint colours. The popover controls are absent: this element has no
popover.

## Also search post content

Off by default, and the one setting that changes what this element *is*.

Without it the typed text never reaches the database. It is a lookup key: words
are matched against the options the sibling filters offer, those filters' values
are set, and Bricks builds the query from the filters alone. A word no filter
covers — a practitioner's name, a phrase in a post body — finds nothing, because
there is no filter holding it.

Switched on, the sentence becomes a query condition in its own right and the
results are the **union**:

```
posts matching the active filters  ∪  posts matching the typed words
```

So *"podiatry smith"* returns every Podiatry post **plus** everything matching
"smith", rather than the intersection. That is deliberate: the field takes a
whole sentence, and ANDing *"a podiatrist in penrith who accepts ndis"* as a
keyword search would return nothing.

Two rules fall out of it:

**With no filter narrowing the query, the words stand alone.** A union against
an unconstrained filter set is every post, which would make the field do nothing
on exactly the words it exists to catch. So when nothing is active — or only a
sort or a per-page — the term is applied as a plain `s` and the element behaves
like an ordinary search box. This is what "closer to the default search" means
in practice.

**Everything active is on the left of the OR**, including choices the visitor
made by hand in a dropdown. A manual selection therefore stops narrowing the
moment words are typed. Ownership tracking is not consulted here: it decides
what the search may *clear*, not what the query means.

### The words are OR'd, not AND'd

`WP_Query::parse_search()` joins its terms with `AND`, so `s` asks for a post
containing *every* word. On a field built to take a sentence that is fatal:

```
"penrith in crossing"  →  posts containing penrith AND in AND crossing  →  none
union = (Penrith posts) ∪ ∅                                             →  Penrith only
```

which reads as the filters beating the search, when really the keyword half
returned nothing. So `Keyword_Search` replaces core's clause through
`posts_search` and matches **any** word against `post_title`, `post_excerpt` and
`post_content`. `"penrith in crossing"` then returns the Penrith posts *and* the
Ropes Crossing ones.

The clause is replaced rather than extended because the `AND` is built into how
core assembles its terms — there is nothing to loosen from outside. It is scoped
by a `zag_keyword_words` query var rather than by adding and removing the
filter, so it can never touch a query this plugin doesn't own. Core's
password-protected-post guard is carried over with it.

**Stopwords matter more here than in a normal search.** One surviving `in` ORs
in the entire post type. Words under 3 characters go, and so does a small
stopword list. Both are filterable, and the useful list is site specific — a
practitioner directory wants `practitioner` in it:

```php
add_filter( 'zag/keyword_search/stopwords', fn( $words ) => [ ...$words, 'practitioner' ] );
add_filter( 'zag/keyword_search/min_word_length', fn() => 4 );
```

A sentence that survives to nothing — `"who are the"` — adds no clause at all,
rather than one matching everything.

**Long sentences get broad.** `"a podiatrist in penrith who accepts ndis"`
reduces to `podiatrist`, `penrith`, `accepts`, `ndis` and OR's them, which on a
large site pulls in a lot. If that becomes a problem, the refinement is to
search only the words that matched *no* filter option — the ones the filter half
already covers are redundant in the keyword half — which needs the client to
send its match set with the term.

### What it costs

Two extra `fields => 'ids'` queries per request on the target query, unpaged.

A true OR cannot be expressed in one `WP_Query`. Taxonomy and custom field
filters compile to `INNER JOIN`s, so an `OR ID IN (…)` bolted onto the `WHERE`
cannot rescue a row the join has already dropped. The only shape that gives the
right answer is running both halves as ID-only queries and unioning them into
`post__in` — which is what `Zag\Keyword_Search` does, at
`bricks/posts/query_vars` priority 1000, one past Bricks' own merge at 999.

`no_found_rows` drops the `SQL_CALC_FOUND_ROWS` pass, which is the expensive
half, but this is still two unbounded queries. Fine for a directory; think twice
before switching it on over tens of thousands of posts.

**With no filter active it costs nothing.** That branch only adds query vars —
the main query does the work — so a page being used as a plain search box runs
exactly the queries it did before.

**The two queries are per request, not per query build.**
`bricks/posts/query_vars` fires more than once on a page: every filter element's
`prepare_sources()` renders `{query_results_count:…}` to force the target query
to run, and the render runs it again. The union is memoised by query and term so
the cost stays fixed instead of scaling with how many filters are on the page.

**More requests, though.** Before this setting, a sentence that moved no filter
issued nothing. Now every debounced change fetches, so the round trips scale with
typing rather than with matches. `DEBOUNCE_MS` in `src/search.js` is the dial;
300ms is tuned for the no-op case and is aggressive for one that runs two
unbounded queries.

### Which query it searches

**Limit to query** set, that query. Left empty, every query a Zag filter on the
page points at — the same rule the client side uses to decide which filters to
drive, which is what stops the words leaking into an unrelated loop that happens
to share the page.

A page with a keyword search and **no Zag filters at all** is a valid setup —
it is then just a search box — but there is nothing to infer the query from, so
**Limit to query must be set**. The builder says so under the checkbox.

### How the term reaches the server

The element is not a Bricks filter, so its value never rides in
`selectedFilters`. The URL is its only channel:

- **Page load** — `$_GET`, the same parameter the field is seeded from.
- **Filter request** — the `urlParams` payload, which carries the query string
  `search.js` wrote with `replaceState` *before* firing. Bricks added
  `urlParams` in **2.3.8**; on older versions `Keyword_Search` falls back to
  parsing the referrer, which `replaceState` keeps current. A referrer policy
  that strips it would leave the words applying on load and not afterwards.

`syncUrl()` runs before any fetch in `run()` for exactly this reason.

**The clause must also be kept out of the query's recorded "original" state.**
Bricks renders `data-original-query-vars` from
`Query_Filters::$query_vars_before_merge` and falls back to the *final* vars
when it is empty — and it only populates that property when a filter is active.
A page loaded on `?zag-search=…` alone would otherwise post the keyword clause
back as the query's original state on every later request, making the search
impossible to clear. `Keyword_Search::apply()` writes the property itself before
touching anything. It reproduces only from a refresh with the term already in
the URL, which is what makes it look like a clearing bug.

### A sentence that matches nothing still issues a request

Normally a sentence resolving to no option label is a no-op — correctly, there
is nothing to filter by. With this on, the words narrow the query server side,
so the request has to go out even though every filter on the page is untouched.
`run()` tracks the last sentence it searched for and calls `fetchQueries()` for
whatever `driveFilters()` did not already cover.

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

**A response never overwrites what is in the field.** With the keyword pass on,
every debounced change fetches, so a response routinely lands while the visitor
is still typing — and writing its sentence back into the input would delete
whatever they added in the meantime, which reads as the field fighting them.
The input wins whenever it disagrees with the URL the element last wrote, since
only typing can put those out of step. Comparing against the URL alone cannot
tell the cases apart: Bricks rebuilds the query string from its own filter
params on every response, so the parameter is routinely missing at that moment
for reasons that have nothing to do with the visitor.

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
root `CLAUDE.md` about impersonating native filter elements. (Keyword search
does not change this: it adds query vars from a hook of its own, and the element
still registers no filter and holds no value.) It is absent from
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

`tests/impersonation-test.php` covers the keyword search's two pure decisions:
whether anything is constraining the query (union vs. plain `s`), and which
query vars survive the rebuild around `post__in`. Both fail silently in
production — a wrong "yes" returns every post, a wrong key list drops a sort or
reinstates a filter — and all three read as the search being broken.

None of the matching logic is covered — that harness stubs the Bricks PHP
surface, and this half is all JS.

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
- keyword search: an unmatched sentence still triggering one request, and the
  same sentence twice triggering none
