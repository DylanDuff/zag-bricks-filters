import { VanillaMachine, normalizeProps, spreadProps } from '@zag-js/vanilla'
import * as select from '@zag-js/select'

/**
 * Zag select UI over a Bricks query filter.
 *
 * The element renders a real <select> server-side. That stays in the DOM as the
 * progressive-enhancement baseline and as a plain form control; we hide it on
 * mount and mirror every selection into it.
 *
 * Source of truth for the *filter* value is Bricks' own
 * window.bricksData.filterInstances[filterId].currentValue. Everything that can
 * change a filter value from outside this file — the reset button, the active
 * filters element, browser back/forward — writes there and then triggers an
 * AJAX round trip. So rather than intercepting each of those paths separately,
 * we re-sync from currentValue on every bricks/ajax/query_result/displayed.
 */

const mounted = new Map()

/**
 * Interaction state carried across a server re-render, keyed by filter id.
 *
 * Bricks replaces the entire filter element node when the response carries
 * updated_filters (frontend.js:11961), which is how our option counts refresh.
 * That destroys the Zag instance — and for multi-select, where the menu stays
 * open across a selection, it would otherwise slam the dropdown shut mid-use.
 */
const pendingRestore = new Map()

function getFilterInstance(root) {
	const instances = window.bricksData?.filterInstances

	if (!instances) {
		return false
	}

	return Object.values(instances).find((filter) => filter.filterElement === root) || false
}

/**
 * Normalise a filter value to the array shape Zag's select machine wants.
 *
 * Bricks keeps a scalar for filterType 'select' and an array for 'checkbox'.
 */
function toArray(value) {
	if (Array.isArray(value)) {
		return value.map(String)
	}

	return value === '' || value === null || value === undefined ? [] : [String(value)]
}

function mount(root) {
	let config

	try {
		config = JSON.parse(root.dataset.zagSelect)
	} catch (e) {
		console.error('[zag] Could not parse element config', e, root)
		return
	}

	const native = root.querySelector('[data-zag-native]')
	const filterInstance = getFilterInstance(root)
	const multiple = !!config.multiple

	/**
	 * Seed Bricks' currentValue from the server-rendered value.
	 *
	 * bricksFiltersFn reads element.value when it registers an instance, which
	 * is undefined on our wrapper div — so a filter restored from the URL would
	 * otherwise register as empty and get dropped from the next request.
	 */
	if (filterInstance) {
		filterInstance.currentValue = multiple ? toArray(config.value) : toArray(config.value)[0] || ''
		filterInstance.originalValue = multiple ? [] : ''
	}

	const collection = select.collection({
		items: config.items || [],
		itemToString: (item) => item.label,
		itemToValue: (item) => item.value,
		isItemDisabled: (item) => !!item.disabled
	})

	const machine = new VanillaMachine(select.machine, {
		id: root.id || `zag-${Math.random().toString(36).slice(2, 8)}`,
		collection,
		multiple,
		closeOnSelect: !multiple,
		/**
		 * defaultValue, not value.
		 *
		 * `value` makes the machine controlled: it defers to the prop and never
		 * mutates its own state, so selections fire onValueChange but never
		 * repaint. We drive the value imperatively through api.setValue() in
		 * resync(), which is the uncontrolled pattern.
		 */
		defaultValue: toArray(config.value),
		positioning: {
			placement: config.placement || 'bottom-start',
			sameWidth: config.sameWidth !== false,
			gutter: 8,
			flip: true
		},
		onValueChange: (details) => onValueChange(root, details)
	})

	machine.start()

	const parts = buildParts(root, config)

	native.hidden = true
	native.setAttribute('aria-hidden', 'true')
	native.tabIndex = -1

	const instance = { machine, root, native, config, parts, multiple, filterInstance }

	instance.render = () => render(instance)

	machine.subscribe(instance.render)
	instance.render()

	mounted.set(root, instance)

	restoreInteraction(instance)
}

/**
 * Put back the open menu and highlighted option after a server re-render.
 *
 * Deliberately after mounted.set(), so the setOpen below goes through the
 * normal render path.
 */
function restoreInteraction(instance) {
	const filterId = instance.filterInstance?.filterId

	if (!filterId || !pendingRestore.has(filterId)) {
		return
	}

	const previous = pendingRestore.get(filterId)
	pendingRestore.delete(filterId)

	if (!previous.open) {
		return
	}

	const api = select.connect(instance.machine.service, normalizeProps)
	api.setOpen(true)

	if (previous.highlightedValue) {
		api.setHighlightValue(previous.highlightedValue)
	}
}

/**
 * Tear down instances whose element Bricks has swapped out.
 *
 * Runs before BricksFunction re-runs and mounts the replacement, because our
 * listener is registered at module evaluation and theirs on DOMContentLoaded.
 */
function reap() {
	mounted.forEach((instance, root) => {
		if (root.isConnected) {
			return
		}

		const filterId = instance.filterInstance?.filterId

		if (filterId) {
			const api = select.connect(instance.machine.service, normalizeProps)

			pendingRestore.set(filterId, {
				open: api.open,
				highlightedValue: api.highlightedValue
			})
		}

		try {
			instance.machine.stop()
		} catch (e) {
			// A machine that failed to start has nothing to stop.
		}

		mounted.delete(root)
	})
}

/**
 * Build the trigger/listbox DOM.
 *
 * Rendered client-side rather than server-side because it is pure chrome: none
 * of it should exist for a user without JS, who gets the native <select>.
 */
function buildParts(root, config) {
	const control = document.createElement('div')
	control.className = 'zag-select__control'

	const trigger = document.createElement('button')
	trigger.type = 'button'
	trigger.className = 'zag-select__trigger'

	const value = document.createElement('span')
	value.className = 'zag-select__value'
	value.textContent = config.placeholder

	const indicator = document.createElement('span')
	indicator.className = 'zag-select__indicator'
	indicator.innerHTML =
		'<svg width="12" height="8" viewBox="0 0 12 8" fill="none" aria-hidden="true"><path d="M1 1.5 L6 6.5 L11 1.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>'

	trigger.append(value, indicator)
	control.append(trigger)

	const positioner = document.createElement('div')
	positioner.className = 'zag-select__positioner'

	const content = document.createElement('ul')
	content.className = 'zag-select__menu'

	const items = (config.items || []).map((item) => {
		const li = document.createElement('li')
		li.className = 'zag-select__option'

		if (item.depth) {
			li.classList.add(`depth-${item.depth}`)
		}

		const label = document.createElement('span')
		label.className = 'zag-select__option-label'
		label.textContent = item.label

		const check = document.createElement('span')
		check.className = 'zag-select__check'
		check.innerHTML =
			'<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.5 8.5 L6.5 11.5 L12.5 4.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>'

		li.append(label, check)
		content.append(li)

		return { el: li, check, item }
	})

	positioner.append(content)
	root.append(control, positioner)

	return { control, trigger, value, positioner, content, items }
}

function render(instance) {
	const { machine, root, parts, config, multiple } = instance
	const api = select.connect(machine.service, normalizeProps)

	spreadProps(root, api.getRootProps())
	spreadProps(parts.control, api.getControlProps())
	spreadProps(parts.trigger, api.getTriggerProps())
	spreadProps(parts.positioner, api.getPositionerProps())
	spreadProps(parts.content, api.getContentProps())

	// Keep the menu mounted so the close transition can play.
	parts.positioner.removeAttribute('hidden')
	parts.content.removeAttribute('hidden')

	// Only the open dropdown floats above its neighbours.
	root.dataset.open = api.open ? 'true' : 'false'

	parts.items.forEach(({ el, check, item }) => {
		spreadProps(el, api.getItemProps({ item }))
		spreadProps(check, api.getItemIndicatorProps({ item }))
	})

	const values = api.value || []

	if (!values.length) {
		parts.value.textContent = config.placeholder
	} else if (multiple && values.length > 1) {
		parts.value.innerHTML = ''

		const counter = document.createElement('span')
		counter.className = 'zag-select__counter'
		counter.textContent = values.length

		parts.value.append(counter, document.createTextNode(` ${config.pluralLabel || 'selected'}`))
	} else {
		parts.value.textContent = api.valueAsString
	}

	parts.value.classList.toggle('is-selected', values.length > 0)
}

function onValueChange(root, details) {
	const instance = mounted.get(root)

	// Fires once during machine.start(), before mounted.set().
	if (!instance) {
		return
	}

	const values = details.value || []

	syncNative(instance, values)

	const detail = {
		filterId: instance.filterInstance?.filterId || null,
		queryId: instance.filterInstance?.targetQueryId || null,
		value: instance.multiple ? values : values[0] || '',
		items: details.items || [],
		element: root
	}

	/**
	 * Emitted before the filter runs so a listener can act on the selection
	 * regardless of whether it also triggers a query.
	 */
	root.dispatchEvent(new CustomEvent('zag/filter/change', { detail, bubbles: true }))

	applyFilter(instance, values)
}

function syncNative(instance, values) {
	const { native } = instance

	Array.from(native.options).forEach((option) => {
		option.selected = values.includes(option.value)
	})

	if (!instance.multiple) {
		native.value = values[0] || ''
	}

	// For anything listening to the real form control rather than our event.
	native.dispatchEvent(new Event('change', { bubbles: true }))
}

function isSameValue(a, b) {
	const left = toArray(a)
	const right = toArray(b)

	return left.length === right.length && left.every((value) => right.includes(value))
}

function applyFilter(instance, values) {
	const filter = instance.filterInstance

	if (!filter) {
		return
	}

	const next = instance.multiple ? values.map(String) : String(values[0] || '')

	/**
	 * resync() drives the machine with api.setValue(), which also fires
	 * onValueChange and lands us here. Comparing against the value Bricks
	 * already holds keeps a programmatic sync from kicking off a second,
	 * redundant round trip — without depending on when onValueChange fires
	 * relative to setValue returning.
	 */
	if (isSameValue(filter.currentValue, next)) {
		return
	}

	filter.currentValue = next

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
 * Pull the UI back in line with Bricks' filter state.
 *
 * Covers the reset button, the active-filters element and popstate in one go:
 * all of them set currentValue and then trigger this event.
 */
function resync() {
	mounted.forEach((instance) => {
		const filter = instance.filterInstance

		if (!filter) {
			return
		}

		const api = select.connect(instance.machine.service, normalizeProps)

		if (!isSameValue(filter.currentValue, api.value || [])) {
			api.setValue(toArray(filter.currentValue))
		}
	})
}

const zagFiltersFn = new window.BricksFunction({
	parentNode: document,
	selector: '.zag-select[data-zag-select]',
	frontEndOnly: true,
	eachElement: (element) => {
		if (mounted.has(element)) {
			return
		}

		mount(element)
	}
})

document.addEventListener('DOMContentLoaded', () => {
	// After Bricks' own DOMContentLoaded handler has registered filter instances.
	zagFiltersFn.run()
})

document.addEventListener('bricks/ajax/query_result/displayed', () => {
	// Reap first so resync only ever sees live instances, then mount whatever
	// replaced them. BricksFunction subscribes to this event itself and will
	// call run() again after us; the mounted guard in eachElement makes that a
	// no-op. Calling it here keeps the ordering ours rather than dependent on
	// listener registration order.
	reap()
	resync()
	zagFiltersFn.run()
})
