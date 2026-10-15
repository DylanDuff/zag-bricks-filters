/**
 * Which options each linked select should hide. Pure, no DOM, so it runs under
 * `node --test` (tests/link-core.test.mjs).
 *
 * A member is { key, postType, values, items: [{ value, posts }] }, where
 * `posts` is the ids of the posts carrying that option's term.
 *
 * Within one select, a multi-selection reads as OR (the union of its options'
 * posts). Across selects it reads as AND: an option stays visible only if it
 * shares at least one post with every other select that has a selection. Only
 * selects sharing a post type constrain each other, since post ids from two
 * different types never describe the same thing.
 *
 * A selection whose options carry no posts at all is ignored rather than
 * hiding everything.
 *
 * A selected option is never hidden. With three or more selects a later pick
 * can rule out an earlier one, and hiding it would leave a value the visitor
 * can't see or clear from the menu.
 *
 * @returns {Map<string, Set<string>>} member key => option values to hide
 */
export function computeHidden(members) {
	const selectedPosts = new Map()

	for (const member of members) {
		if (!member.values.length) {
			continue
		}

		const chosen = new Set(member.values.map(String))
		const posts = new Set()

		for (const item of member.items) {
			if (chosen.has(String(item.value))) {
				for (const id of item.posts || []) {
					posts.add(id)
				}
			}
		}

		/**
		 * A pick with no posts behind it constrains nothing. Read literally it
		 * would rule out every sibling option, which is never what a visitor
		 * means and is exactly how missing post data looks: with an empty
		 * payload, any pick emptied the other select.
		 */
		if (posts.size) {
			selectedPosts.set(member.key, posts)
		}
	}

	const hidden = new Map()

	for (const member of members) {
		const constraints = members
			.filter((other) => other !== member && other.postType === member.postType && selectedPosts.has(other.key))
			.map((other) => selectedPosts.get(other.key))

		const chosen = new Set(member.values.map(String))
		const hide = new Set()

		if (constraints.length) {
			for (const item of member.items) {
				const value = String(item.value)

				if (chosen.has(value)) {
					continue
				}

				const posts = item.posts || []
				const fits = constraints.every((allowed) => posts.some((id) => allowed.has(id)))

				if (!fits) {
					hide.add(value)
				}
			}
		}

		hidden.set(member.key, hide)
	}

	return hidden
}
