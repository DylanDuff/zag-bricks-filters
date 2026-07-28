/**
 * Smart search: a field that sets other filters rather than filtering itself.
 *
 * What the visitor types is matched against the options the Zag filters on the
 * page already offer, plus an authored synonym table, and every match is
 * applied as one batched request.
 *
 * Two things make this element unlike the others: it holds no filter value of
 * its own, and it is never re-rendered by Bricks after a response — so it keeps
 * focus and caret while results update, and needs no state carried across a
 * re-render.
 *
 * @see inc/elements/smart-search.php
 */

import { filterTargets, driveFilters, isSameValue, onFiltersSettled, toArray } from './bricks.js'

/** Live search elements, keyed by root. Mirrors `mounted` for the filters. */
export const searchInstances = new Map()

/**
 * Applied after typing stops.
 *
 * Every apply is a server round trip, unlike the prototype's in-memory filter,
 * so this is the difference between one request and one per keystroke. Enter
 * flushes it immediately for anyone who would rather not wait.
 */
const DEBOUNCE_MS = 300

/** Lowercase, punctuation to spaces, collapsed. */
function normalise(text) {
	return String(text)
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, ' ')
		.trim()
}

/**
 * Match a phrase at the start of a word.
 *
 * The haystack is space padded and single spaced, so testing for a leading
 * space is a word-start test without a regex: "physio" catches
 * "physiotherapist" but "myphysio" catches nothing.
 */
function matchesPhrase(haystack, phrase) {
	return haystack.includes(' ' + phrase)
}

/** The text an option is matched against — never the count-bearing label. */
function optionText(option) {
	return option.match || option.label || ''
}

/**
 * Build the phrase table for the filters currently on the page.
 *
 * Rebuilt per parse rather than cached: Bricks replaces our filter nodes after
 * every response, so the targets — and their option counts — are new objects
 * each time. It is a loop over a few dozen options behind a debounce.
 */
function buildVocabulary(targets, synonyms) {
	const entries = []

	/**
	 * Every option matches its own label, with no authoring at all.
	 *
	 * This is the half the prototype had to hardcode, because it had no filters
	 * to ask. It covers every discipline and suburb trigger in that table.
	 */
	targets.forEach((target) => {
		target.options.forEach((option) => {
			const phrase = normalise(optionText(option))

			if (!phrase) {
				return
			}

			entries.push({ phrases: [phrase], matches: [{ target, value: String(option.value) }] })
		})
	})

	synonyms.forEach((row) => {
		const phrases = (row.phrases || []).map(normalise).filter(Boolean)
		const matches = []
		const unresolved = []

		// One row may name several options, and one label may exist on several
		// filters — both resolve to every option that carries the label.
		;(row.targets || []).forEach((label) => {
			const wanted = normalise(label)
			const found = []

			targets.forEach((target) => {
				target.options.forEach((option) => {
					if (normalise(optionText(option)) === wanted) {
						found.push({ target, value: String(option.value) })
					}
				})
			})

			if (found.length) {
				matches.push(...found)
			} else {
				unresolved.push(label)
			}
		})

		if (unresolved.length) {
			// Silence here would look exactly like a parser that doesn't work.
			console.warn('[zag] Smart search: no option labelled', unresolved, '— check the Vocabulary rows against the filter options on this page.')
		}

		if (phrases.length && matches.length) {
			entries.push({ phrases, matches })
		}
	})

	return entries
}

/** What a sentence resolves to: a set of option values per filter. */
function parse(text, targets, synonyms) {
	const haystack = ' ' + normalise(text) + ' '
	const wanted = new Map()

	buildVocabulary(targets, synonyms).forEach((entry) => {
		if (!entry.phrases.some((phrase) => matchesPhrase(haystack, phrase))) {
			return
		}

		entry.matches.forEach(({ target, value }) => {
			if (!wanted.has(target)) {
				wanted.set(target, new Set())
			}

			wanted.get(target).add(value)
		})
	})

	return wanted
}

/** Ownership record: which values this search put where, by filter id. */
function ownershipFrom(wanted) {
	const owned = new Map()

	wanted.forEach((values, target) => {
		owned.set(target.filter.filterId, [...values])
	})

	return owned
}

/**
 * Work out what each filter should now hold.
 *
 * The prototype replaces the whole filter state on every keystroke, so clearing
 * the box clears everything — including dropdown choices made by hand. Here the
 * search only ever owns what it set: anything else present is the visitor's and
 * survives both a new sentence and an emptied field.
 */
function reconcile(instance, targets, wanted) {
	const plan = []

	targets.forEach((target) => {
		const previous = instance.owned.get(target.filter.filterId) || []
		const current = toArray(target.filter.currentValue)
		const parsed = [...(wanted.get(target) || [])]
		const manual = current.filter((value) => !previous.includes(value))

		/**
		 * A single select has one slot to give, so the two sources can't be
		 * merged: the sentence was typed just now, so it takes the slot, and a
		 * manual choice only stands while the sentence resolves to nothing.
		 */
		const next = target.multiple
			? manual.concat(parsed.filter((value) => !manual.includes(value)))
			: (parsed.length ? parsed.slice(0, 1) : manual)

		if (!isSameValue(current, next)) {
			plan.push({ target, values: next })
		}
	})

	instance.owned = ownershipFrom(wanted)

	return plan
}

/** The option labels a sentence resolved to, for reading back in the hint. */
function matchedLabels(wanted) {
	const labels = []

	wanted.forEach((values, target) => {
		target.options.forEach((option) => {
			const text = optionText(option)

			if (values.has(String(option.value)) && text && !labels.includes(text)) {
				labels.push(text)
			}
		})
	})

	return labels
}

/**
 * Rewrite the hint line.
 *
 * Built from nodes rather than innerHTML — the labels are site content and the
 * prefix is an editor setting, and neither has any business being parsed as
 * markup.
 */
function updateHint(instance, text, labels) {
	const { hint, config } = instance

	if (!hint) {
		return
	}

	hint.textContent = ''

	if (!text.trim()) {
		hint.hidden = true

		return
	}

	hint.hidden = false

	if (!labels.length) {
		hint.textContent = config.hintEmpty

		return
	}

	hint.append(config.hintPrefix + ' ')

	labels.forEach((label, index) => {
		if (index > 0) {
			hint.append(' · ')
		}

		const strong = document.createElement('strong')
		strong.textContent = label
		hint.append(strong)
	})
}

/**
 * Keep the typed sentence in the URL.
 *
 * Written with replaceState on top of whatever Bricks last pushed, and rewritten
 * again after every response: Bricks rebuilds the query string from its own
 * filter params when it pushes history, which would otherwise drop this on the
 * first apply. history.state is passed through so Bricks' own entry survives.
 */
function syncUrl(instance) {
	const url = new URL(window.location.href)
	const text = instance.input.value.trim()

	if (text) {
		url.searchParams.set(instance.config.param, text)
	} else {
		url.searchParams.delete(instance.config.param)
	}

	if (url.href !== window.location.href) {
		window.history.replaceState(window.history.state, '', url)
	}
}

function urlValue(instance) {
	return new URL(window.location.href).searchParams.get(instance.config.param) || ''
}

/**
 * The filters a sentence can act on.
 *
 * Option-less filters — the date picker — are dropped here rather than in
 * filterTargets(), which the chips element needs to see them. Excluding them
 * this early is also what guarantees reconcile() can never clear a date: it
 * only ever visits what this returns.
 */
function matchableTargets(instance) {
	return filterTargets(instance.config.queryId || undefined).filter((target) => target.options.length > 0)
}

/** Parse what's typed, apply it, and tell the visitor what it was read as. */
function run(instance) {
	const text = instance.input.value
	const targets = matchableTargets(instance)
	const wanted = parse(text, targets, instance.config.synonyms)
	const plan = reconcile(instance, targets, wanted)

	updateHint(instance, text, matchedLabels(wanted))
	syncUrl(instance)

	if (plan.length) {
		driveFilters(plan)
	}
}

/**
 * Adopt a value that arrived with the page rather than from typing.
 *
 * On first load and on back/forward the filters are already set — Bricks
 * restores them from its own URL params — so this records what the sentence
 * owns without applying anything. Skipping it would leave those values looking
 * like manual choices that clearing the box could never remove.
 */
function adopt(instance, text) {
	instance.input.value = text

	const targets = matchableTargets(instance)
	const wanted = parse(text, targets, instance.config.synonyms)

	instance.owned = ownershipFrom(wanted)
	updateHint(instance, text, matchedLabels(wanted))
}

export function mountSearch(root) {
	let config

	try {
		config = JSON.parse(root.dataset.zagSearch)
	} catch (e) {
		console.error('[zag] Could not parse search config', e, root)

		return
	}

	const input = root.querySelector('[data-zag-search-input]')

	if (!input) {
		return
	}

	const instance = {
		root,
		input,
		config,
		hint: root.querySelector('[data-zag-search-hint]'),
		owned: new Map(),
		timer: null
	}

	const schedule = () => {
		window.clearTimeout(instance.timer)
		instance.timer = window.setTimeout(() => run(instance), DEBOUNCE_MS)
	}

	const flush = () => {
		window.clearTimeout(instance.timer)
		run(instance)
	}

	input.addEventListener('input', schedule)

	// The native clear button on type="search" fires this, not 'input', in Safari.
	input.addEventListener('search', flush)

	input.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			// Nothing here submits, and a bare input in no form would reload.
			event.preventDefault()
			flush()

			return
		}

		if (event.key === 'Escape' && input.value !== '') {
			// Stop Escape bubbling to anything that reads it as "close me".
			event.stopPropagation()
			input.value = ''
			flush()
		}
	})

	searchInstances.set(root, instance)

	// Whatever the server rendered into the field came from the URL.
	if (input.value.trim()) {
		adopt(instance, input.value)
	}
}

/**
 * Re-assert the URL parameter, and follow the URL when something else changed it.
 *
 * Runs through onFiltersSettled rather than a direct listener on
 * bricks/ajax/query_result/displayed: adopt() calls filterTargets(), which is
 * only accurate once reap() and resync() have reconciled the DOM swap.
 */
onFiltersSettled(() => {
	searchInstances.forEach((instance) => {
		const fromUrl = urlValue(instance)

		if (fromUrl === instance.input.value) {
			// Ours was the change: put the parameter back after Bricks' rewrite.
			syncUrl(instance)

			return
		}

		// A popstate landed us on a different sentence.
		adopt(instance, fromUrl)
	})
})

window.addEventListener('popstate', () => {
	searchInstances.forEach((instance) => {
		const fromUrl = urlValue(instance)

		/**
		 * Value only, no apply.
		 *
		 * Bricks handles popstate for its own filters and refetches, and the
		 * displayed handler above re-adopts once that lands. Applying here would
		 * race it with a second request.
		 */
		if (fromUrl !== instance.input.value) {
			instance.input.value = fromUrl
		}
	})
})
