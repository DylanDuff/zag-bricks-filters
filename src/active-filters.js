/**
 * Active filters: a removable chip per active filter value.
 *
 * Built from the live frontend state — each filter's currentValue and its own
 * describe() — rather than from anything the server rendered. That is the point
 * of the element: the server-side value has been through
 * sanitize_filter_value(), which collapses a multi-select array to its first
 * entry, so chips built from it are wrong for exactly the case that needs them
 * most.
 *
 * Like the smart search, this element holds no filter value and is never
 * re-rendered by Bricks after a response.
 *
 * @see inc/elements/active-filters.php
 */

import { driveFilters, filterTargets, onFiltersSettled, toArray } from './bricks.js'

/** Live chip elements, keyed by root. Mirrors `mounted` for the filters. */
export const activeFilterInstances = new Map()

const REMOVE_GLYPH = '×'

/**
 * Every active value across the filters this element covers.
 *
 * A chip is only ever produced by a filter's own describe(), so a value the
 * owning element cannot label is a value that cannot appear — deliberate. The
 * alternative is a chip reading a bare term id.
 */
function collectChips(instance) {
	const chips = []

	filterTargets(instance.config.queryId || undefined).forEach((target) => {
		target.describe().forEach((chip) => {
			chips.push({ target, value: String(chip.value), label: chip.label })
		})
	})

	return chips
}

/**
 * Drop one value, keeping everything else that filter holds.
 *
 * Multi-selects lose only the clicked value. Single selects and the date picker
 * hold one value, so removing it empties them.
 */
function removeChip(chip) {
	const remaining = toArray(chip.target.filter.currentValue).filter((value) => value !== chip.value)

	driveFilters([{ target: chip.target, values: remaining }])
}

/** Clear every filter this element covers, in one request per query. */
function clearAll(instance) {
	const plan = filterTargets(instance.config.queryId || undefined)
		.filter((target) => toArray(target.filter.currentValue).length > 0)
		.map((target) => ({ target, values: [] }))

	if (plan.length) {
		driveFilters(plan)
	}
}

/**
 * Copy the owning filter's leading icon into a chip.
 *
 * The glyph is cloned, not the element's own icon wrapper: that wrapper carries
 * the select's badge sizing — a 40px circle with its own background — which is
 * meaningless at chip scale. Taking the children instead gives a bare glyph the
 * chip styles for itself.
 *
 * Cloning also means Bricks' icon colour rule, which is scoped to the filter
 * element, no longer reaches it. That's deliberate: inside a chip the icon
 * should read as chip text, not as the field's icon.
 */
function chipIcon(target) {
	const source = target.icon()

	if (!source || !source.childNodes.length) {
		return null
	}

	const wrap = document.createElement('span')
	wrap.className = 'zag-active-filters__icon'

	// Decorative: the chip's own text is what carries the meaning.
	wrap.setAttribute('aria-hidden', 'true')

	source.childNodes.forEach((node) => wrap.append(node.cloneNode(true)))

	return wrap
}

function buildChip(instance, chip) {
	const item = document.createElement('li')
	item.className = 'zag-active-filters__chip'

	if (!instance.config.hideIcons) {
		const icon = chipIcon(chip.target)

		if (icon) {
			item.append(icon)
			item.classList.add('has-icon')
		}
	}

	const text = document.createElement('span')
	text.className = 'zag-active-filters__text'
	text.textContent = chip.label
	item.append(text)

	const remove = document.createElement('button')
	remove.type = 'button'
	remove.className = 'zag-active-filters__remove'

	// The glyph is decorative; the accessible name carries the chip's own text.
	remove.textContent = REMOVE_GLYPH
	remove.setAttribute('aria-label', `${instance.config.removeText} ${chip.label}`)
	remove.addEventListener('click', () => removeChip(chip))

	item.append(remove)

	return item
}

/**
 * Rebuild the whole row.
 *
 * Wholesale rather than diffed: the row is a handful of nodes, it changes only
 * on a filter action, and nothing inside it holds focus that a rebuild could
 * steal — the remove button that triggered the change is gone by definition.
 */
function render(instance) {
	const { root, config } = instance
	const chips = collectChips(instance)

	root.textContent = ''

	if (!chips.length) {
		// Hidden rather than emptied, so no gap is left in the layout.
		root.hidden = true

		return
	}

	root.hidden = false

	if (config.label) {
		const label = document.createElement('span')
		label.className = 'zag-active-filters__label'
		label.textContent = config.label
		root.append(label)
	}

	const list = document.createElement('ul')
	list.className = 'zag-active-filters__list'
	chips.forEach((chip) => list.append(buildChip(instance, chip)))
	root.append(list)

	if (config.clearAll) {
		const clear = document.createElement('button')
		clear.type = 'button'
		clear.className = 'zag-active-filters__clear'
		clear.textContent = config.clearAllText
		clear.addEventListener('click', () => clearAll(instance))
		root.append(clear)
	}
}

export function mountActiveFilters(root) {
	let config

	try {
		config = JSON.parse(root.dataset.zagActiveFilters)
	} catch (e) {
		console.error('[zag] Could not parse active filters config', e, root)

		return
	}

	const instance = { root, config }

	activeFilterInstances.set(root, instance)
	render(instance)
}

function renderAll() {
	activeFilterInstances.forEach(render)
}

/**
 * Two triggers, deliberately.
 *
 * `zag/filter/change` fires the moment a selection is made, so a chip appears
 * or disappears with the click rather than after the round trip.
 *
 * onFiltersSettled is the authoritative pass, covering resets, popstate, the
 * smart search and this element's own removals. It must NOT be a plain
 * `bricks/ajax/query_result/displayed` listener: that would run before the
 * lifecycle reconciles the DOM swap, and every render would read discarded
 * instances and paint the previous state.
 */
document.addEventListener('zag/filter/change', renderAll)
onFiltersSettled(renderAll)
