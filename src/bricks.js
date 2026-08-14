/**
 * Everything that talks to Bricks' filter runtime.
 *
 * Both Zag elements share this: they differ only in how a value is read from
 * and written to their machine, which they supply as an adapter.
 *
 * Source of truth for a filter's value is Bricks' own
 * window.bricksData.filterInstances[filterId].currentValue. Everything that can
 * change it from outside our code — the reset button, the active filters
 * element, browser back/forward — writes there and then triggers an AJAX round
 * trip, so we re-sync from it on bricks/ajax/query_result/displayed rather than
 * intercepting each path.
 */

/**
 * Live instances, keyed by their root element.
 *
 * Each entry is { root, machine, filterInstance, adapter }, where the adapter
 * provides getValue/setValue/captureState/restoreState.
 */
export const mounted = new Map()

/**
 * Interaction state carried across a server re-render, keyed by filter id.
 *
 * Bricks replaces the entire filter element node when the response carries
 * updated_filters (frontend.js:11961), which is how our option counts refresh.
 * That destroys the machine, so anything the user was mid-way through has to be
 * captured and reapplied.
 */
const pendingRestore = new Map()

export function getFilterInstance(root) {
	const instances = window.bricksData?.filterInstances

	if (!instances) {
		return false
	}

	return Object.values(instances).find((filter) => filter.filterElement === root) || false
}

/** Normalise any filter value to a comparable array of strings. */
export function toArray(value) {
	if (Array.isArray(value)) {
		return value.map(String)
	}

	return value === '' || value === null || value === undefined ? [] : [String(value)]
}

export function isSameValue(a, b) {
	const left = toArray(a)
	const right = toArray(b)

	return left.length === right.length && left.every((value) => right.includes(value))
}

/**
 * Put a freshly built control into the element, keeping the caption last.
 *
 * The caption is rendered server-side and is therefore already the final child.
 * Appending the control would leave the status line reading *above* the field it
 * describes, so insert ahead of it when there is one.
 */
export function insertControl(root, control, positioner) {
	const caption = root.querySelector('[data-zag-caption]')

	if (caption) {
		caption.before(control, positioner)

		return
	}

	root.append(control, positioner)
}

export function register(instance) {
	mounted.set(instance.root, instance)

	/**
	 * Ends the pre-mount state.
	 *
	 * Until this lands the stylesheet keeps the native control invisible but
	 * holding the field's box, so the real one arrives in the same footprint and
	 * nothing shifts.
	 */
	instance.root.setAttribute('data-zag-ready', '')

	const filterId = instance.filterInstance?.filterId

	if (!filterId || !pendingRestore.has(filterId)) {
		return
	}

	const previous = pendingRestore.get(filterId)
	pendingRestore.delete(filterId)

	instance.adapter.restoreState?.(previous)
}

/**
 * Seed Bricks' value tracking from what the server rendered.
 *
 * bricksFiltersFn registers an instance by reading element.value, which is
 * undefined on our wrapper div — so a filter restored from the URL would
 * otherwise register as empty and be dropped from the next request.
 */
export function seedFilterInstance(filterInstance, value, emptyValue) {
	if (!filterInstance) {
		return
	}

	filterInstance.currentValue = value
	filterInstance.originalValue = emptyValue
}

/**
 * Push new values into Bricks and run the affected queries.
 *
 * `changes` is a list of { filter, value }. Every value is written first, then
 * one request goes out per distinct query — bricksGetQueryResult reads each
 * filter's currentValue off the selectedFilters registry at fetch time
 * (filters.js:440), not from arguments, so a batch of changes to one query
 * costs exactly one round trip. The search element depends on that: a single
 * sentence can set several filters at once.
 *
 * Guarded against the value Bricks already holds: resync() drives machines
 * programmatically, which fires their change callbacks and lands back here.
 * Comparing rather than using a flag keeps this correct regardless of when
 * those callbacks fire.
 */
export function applyFilters(changes) {
	const queryIds = new Set()

	changes.forEach(({ filter, value }) => {
		if (!filter || isSameValue(filter.currentValue, value)) {
			return
		}

		filter.currentValue = value

		// 'click' means a submit button applies the filter, not the input itself.
		if ((filter.filterApplyOn || 'change') !== 'change') {
			return
		}

		const queryId = filter.targetQueryId

		if (!window.bricksData?.queryLoopInstances?.[queryId]) {
			return
		}

		window.bricksUtils.updateSelectedFilters(queryId, filter)
		queryIds.add(queryId)
	})

	fetchQueries(queryIds)

	return queryIds
}

/**
 * Re-run queries without changing any filter value.
 *
 * Filtering normally implies a value changed, so this exists for the one case
 * where it hasn't: the smart search's keyword pass puts what was typed in the
 * URL and lets the server read it there, so a sentence matching no option label
 * still changes the results while every filter on the page stays exactly as it
 * was. Nothing in Bricks' own runtime would issue that request.
 *
 * Guarded on the query loop being registered, the same way applyFilters is —
 * fetchFilterResults throws on an unknown id.
 */
export function fetchQueries(queryIds) {
	queryIds.forEach((queryId) => {
		if (!window.bricksData?.queryLoopInstances?.[queryId]) {
			return
		}

		window.bricksUtils.fetchFilterResults(queryId)
	})
}

/** Single-filter case: what an element does when its own value changes. */
export function applyFilter(instance, value) {
	applyFilters([{ filter: instance.filterInstance, value }])
}

/**
 * Every live Zag filter that something else can read or write.
 *
 * This is what lets a controller element be dropped onto a page with no
 * configuration and find the filters already there: `mounted` is rebuilt as
 * elements mount, and reap() prunes it when Bricks swaps a node out, so a fresh
 * call always reflects the current DOM. Never cache the result across a request.
 *
 * `options` is empty for filters that offer none — the date picker. Callers that
 * need options must say so: search.js matches words against them and filters
 * these out, while active-filters.js only ever describes a current value and
 * keeps them.
 *
 * @param {string} [queryId] Restrict to filters targeting one query.
 */
export function filterTargets(queryId) {
	const targets = []

	mounted.forEach((instance) => {
		const filter = instance.filterInstance

		if (!filter?.targetQueryId) {
			return
		}

		/**
		 * Sorting and per-page are actions, not filters.
		 *
		 * They are always set to something, so there is no cleared state to
		 * return them to: a chip offering to remove the sort would leave the
		 * query unordered rather than back at its default, and a typed sentence
		 * has no business changing the order. Both callers want only filters.
		 */
		if ((filter.filterAction || 'filter') !== 'filter') {
			return
		}

		if (queryId && filter.targetQueryId !== queryId) {
			return
		}

		targets.push({
			filter,
			options: Array.isArray(instance.config?.items) ? instance.config.items : [],
			multiple: !!instance.multiple,
			/** Shape a list of option values the way this filter's type expects. */
			shape: (values) => (instance.multiple ? values.map(String) : String(values[0] ?? '')),
			repaint: (values) => instance.adapter.setValue(values),
			/** { value, label } per chip for whatever this filter currently holds. */
			describe: () => instance.adapter.describe?.() || [],
			/** The element's leading icon node, if it renders one. */
			icon: () => instance.adapter.icon?.() || null
		})
	})

	return targets
}

/**
 * Set several filters at once from outside the elements that own them.
 *
 * `plan` is a list of { target, values }, where values are option values.
 *
 * Returns the queries it actually fetched, so a caller that has its own reason
 * to refresh a query can tell which ones are already covered.
 */
export function driveFilters(plan) {
	const fetched = applyFilters(plan.map(({ target, values }) => ({ filter: target.filter, value: target.shape(values) })))

	/**
	 * Repaint after applying, never before.
	 *
	 * Driving a machine fires its own onValueChange, which lands back in
	 * applyFilter — by then currentValue already holds the new value, so the
	 * isSameValue guard swallows it and no second request goes out. Repainting
	 * first would invert the problem: applyFilters would see nothing changed and
	 * skip the fetch entirely, leaving the UI ahead of the results. This is the
	 * same ordering resync() depends on.
	 */
	plan.forEach(({ target, values }) => target.repaint(values))

	return fetched
}

/**
 * Announce a selection so custom JS can act on it alongside the normal filter.
 */
export function emitChange(instance, detail) {
	instance.root.dispatchEvent(
		new CustomEvent('zag/filter/change', {
			detail: {
				filterId: instance.filterInstance?.filterId || null,
				queryId: instance.filterInstance?.targetQueryId || null,
				element: instance.root,
				...detail
			},
			bubbles: true
		})
	)
}

/**
 * Update the caption line, if the element renders one.
 *
 * `text` is what's currently selected; empty falls back to the configured
 * empty-state wording stored on the node.
 */
export function updateCaption(root, text) {
	const caption = root.querySelector('[data-zag-caption]')

	if (!caption) {
		return
	}

	const target = caption.querySelector('[data-zag-caption-text]')
	const filled = !!text

	if (target) {
		target.textContent = filled ? text : caption.dataset.emptyText || ''
	}

	caption.classList.toggle('is-set', filled)
}

/**
 * Callbacks for elements that read the filters rather than being one.
 *
 * These must run *after* reap() and resync(), and registering a plain
 * `bricks/ajax/query_result/displayed` listener does not achieve that: the
 * lifecycle's own listener is added when startLifecycle() is called from
 * zag-filters.js, which is after every imported module has evaluated. A module
 * level listener therefore lands ahead of it and sees `mounted` still holding
 * instances whose roots Bricks has already discarded — stale values, one
 * interaction behind.
 */
const settledCallbacks = []

/** Run `callback` after each response, once the DOM swap is reconciled. */
export function onFiltersSettled(callback) {
	settledCallbacks.push(callback)
}

/** Tear down instances whose element Bricks has swapped out. */
function reap() {
	mounted.forEach((instance, root) => {
		if (root.isConnected) {
			return
		}

		const filterId = instance.filterInstance?.filterId
		const state = instance.adapter.captureState?.()

		if (filterId && state) {
			pendingRestore.set(filterId, state)
		}

		try {
			instance.machine.stop()
		} catch {
			// A machine that failed to start has nothing to stop.
		}

		mounted.delete(root)
	})
}

/** Pull each live UI back in line with Bricks' filter state. */
function resync() {
	mounted.forEach((instance) => {
		const filter = instance.filterInstance

		if (!filter) {
			return
		}

		if (!isSameValue(filter.currentValue, instance.adapter.getValue())) {
			instance.adapter.setValue(filter.currentValue)
		}
	})
}

/**
 * Wire the shared lifecycle.
 *
 * Registered at module evaluation, which puts this ahead of every one of
 * Bricks' own query_result/displayed subscribers — those register on
 * DOMContentLoaded. That ordering is deliberate: reap and resync must see the
 * post-replacement DOM before anything else re-runs.
 */
export function startLifecycle(runFns) {
	document.addEventListener('DOMContentLoaded', () => {
		// After Bricks' own DOMContentLoaded handler has registered instances.
		runFns.forEach((run) => run())
	})

	document.addEventListener('bricks/ajax/query_result/displayed', () => {
		// Reap first so resync only ever sees live instances, then mount
		// whatever replaced them. BricksFunction subscribes to this event too
		// and will re-run after us; the mounted guard makes that a no-op.
		reap()
		resync()
		runFns.forEach((run) => run())

		// Only now is `mounted` an accurate picture of the page.
		settledCallbacks.forEach((callback) => callback())
	})
}
