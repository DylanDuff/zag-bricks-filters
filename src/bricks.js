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

export function register(instance) {
	mounted.set(instance.root, instance)

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
 * Push a new value into Bricks and run the filter.
 *
 * Guarded against the value Bricks already holds: resync() drives machines
 * programmatically, which fires their change callbacks and lands back here.
 * Comparing rather than using a flag keeps this correct regardless of when
 * those callbacks fire.
 */
export function applyFilter(instance, value) {
	const filter = instance.filterInstance

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
	window.bricksUtils.fetchFilterResults(queryId)
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
	})
}
