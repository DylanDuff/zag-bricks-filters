/**
 * Zag - Group element.
 *
 * Reads the current value out of whatever Zag select/date picker elements are
 * nested inside a zag-group and hands them to an arbitrary JS function when
 * told to submit. Holds no value of its own and never touches Bricks' filter
 * runtime or any query.
 *
 * Unlike every other element in this plugin, this one renders nothing and
 * never repaints, so there is no mount step and no BricksFunction
 * registration: just a single delegated click listener registered once at
 * module load, and a read of the shared `mounted` map at submit time.
 *
 * `mounted` is reserved for instances with the adapter/captureState shape
 * reap() expects (src/bricks.js) — a group is deliberately never added to it,
 * only read from it.
 *
 * @see inc/elements/group.php
 */

import { mounted } from './bricks.js'

/** Dot-path lookup off window, e.g. "myApp.onSubmit". */
function resolveFunction(name) {
	return name.split('.').reduce((target, part) => (target == null ? undefined : target[part]), window)
}

/**
 * Every mounted Zag control physically nested inside this group's root.
 *
 * DOM containment, not Bricks' element tree: `mounted` is rebuilt as elements
 * mount and pruned as they're torn down, so this always reflects who is
 * actually on the page, with no server round trip to ask.
 */
function collectValues(root) {
	const values = {}
	const entries = []

	mounted.forEach((instance) => {
		if (!root.contains(instance.root)) {
			return
		}

		const key = instance.root.id

		// Can't report a value under a key that doesn't exist.
		if (!key) {
			return
		}

		const value = instance.adapter.getValue()
		const describe = instance.adapter.describe?.() || []

		/**
		 * A raw value alone (a term id, a post id) doesn't say what kind of
		 * thing it is. select.js/filter-select.js stash sourceType/sourceSlug
		 * on `config` verbatim from PHP (inc/select-shared.php) — 'manual' |
		 * 'taxonomy' | 'post' for zag-select, or Bricks' own filterSource
		 * ('taxonomy' | 'wpField' | 'customField') for zag-filter-select.
		 * Absent on anything that isn't select-based (the date picker), where
		 * there's nothing meaningful to report.
		 */
		const source = {
			type: instance.config?.sourceType || null,
			slug: instance.config?.sourceSlug || null
		}

		values[key] = value
		entries.push({ key, value, describe, source })
	})

	return { values, entries }
}

function parseConfig(root) {
	try {
		return JSON.parse(root.dataset.zagGroup)
	} catch (e) {
		console.error('[zag] Could not parse group config', e, root)

		return null
	}
}

function submit(root, trigger) {
	const config = parseConfig(root)

	if (!config) {
		return
	}

	const fnName = (config.fn || '').trim()

	if (!fnName) {
		return
	}

	const fn = resolveFunction(fnName)

	if (typeof fn !== 'function') {
		console.error(`[zag] Group submit function "${fnName}" was not found on window.`, root)

		return
	}

	const { values, entries } = collectValues(root)

	fn(values, { root, trigger, entries })
}

/**
 * Which group (if any) a click should submit, trying each mechanism in the
 * order described in inc/elements/group.php: a nested submit button first
 * (no configuration needed), then each group's own "Trigger selector"
 * setting.
 */
function findTarget(event) {
	// A <button> defaults to type="submit" natively when no type is set at
	// all — only "button" and "reset" opt out — so match both, not only an
	// explicit type="submit".
	const submitButton = event.target.closest('button:not([type="button"]):not([type="reset"])')
	const nestedRoot = submitButton?.closest('.zag-group[data-zag-group]')

	if (nestedRoot) {
		return { root: nestedRoot, trigger: submitButton }
	}

	for (const root of document.querySelectorAll('.zag-group[data-zag-group]')) {
		const selector = parseConfig(root)?.triggerSelector

		if (selector && event.target.closest(selector)) {
			return { root, trigger: event.target }
		}
	}

	return null
}

document.addEventListener('click', (event) => {
	const target = findTarget(event)

	if (!target) {
		return
	}

	// The whole point of any of these is "submit", never "navigate".
	event.preventDefault()

	submit(target.root, target.trigger)
})
