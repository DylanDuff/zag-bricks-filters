/**
 * Linked selects: inside a zag-group with "Link select options" on, picking an
 * option in one taxonomy zag-select hides the options in its siblings that
 * share no posts with it.
 *
 * The server does the only expensive part: each linked select's config carries
 * `link: { postType }` and every item a `posts` list (inc/elements/select.php).
 * Everything here is a set intersection over what's already on the page, so
 * nothing round-trips on a change.
 *
 * @see src/link-core.js for the rule itself
 * @see docs/group.md
 */

import { mounted } from './bricks.js'
import { computeHidden } from './link-core.js'

const pending = new Set()

function linkingGroup(root) {
	const group = root.closest('.zag-group')

	// Innermost group decides, matching how the server chose to emit `posts`.
	return group && group.hasAttribute('data-zag-link') ? group : null
}

export function relink(group) {
	const instances = []

	mounted.forEach((instance) => {
		if (instance.config?.link && instance.setHidden && linkingGroup(instance.root) === group) {
			instances.push(instance)
		}
	})

	const members = instances.map((instance, index) => ({
		key: String(index),
		postType: instance.config.link.postType || '',
		values: instance.adapter.getValue().map(String),
		items: instance.config.items || []
	}))

	const hidden = computeHidden(members)

	instances.forEach((instance, index) => instance.setHidden(hidden.get(String(index))))
}

/**
 * Batched to a microtask so a page mounting several linked selects relinks
 * each group once, after all of them have registered.
 */
export function scheduleRelink(root) {
	const group = linkingGroup(root)

	if (!group) {
		return
	}

	if (!pending.size) {
		queueMicrotask(() => {
			const groups = [...pending]
			pending.clear()
			groups.forEach(relink)
		})
	}

	pending.add(group)
}

document.addEventListener('zag/filter/change', (event) => {
	if (event.target instanceof Element && mounted.get(event.target)?.config?.link) {
		scheduleRelink(event.target)
	}
})
