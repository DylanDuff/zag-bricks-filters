import { VanillaMachine, normalizeProps, spreadProps } from '@zag-js/vanilla'
import * as select from '@zag-js/select'

import { applyFilter, emitChange, getFilterInstance, mounted, register, seedFilterInstance, toArray } from './bricks.js'

/**
 * Zag select UI over a Bricks query filter.
 *
 * The element renders a real <select> server-side. That stays in the DOM as the
 * progressive-enhancement baseline and as a plain form control; we hide it on
 * mount and mirror every selection into it.
 */

export function mountSelect(root) {
	let config

	try {
		config = JSON.parse(root.dataset.zagSelect)
	} catch (e) {
		console.error('[zag] Could not parse select config', e, root)
		return
	}

	const native = root.querySelector('[data-zag-native]')
	const filterInstance = getFilterInstance(root)
	const multiple = !!config.multiple
	const initial = toArray(config.value)

	seedFilterInstance(filterInstance, multiple ? initial : initial[0] || '', multiple ? [] : '')

	const collection = select.collection({
		items: config.items || [],
		itemToString: (item) => item.label,
		itemToValue: (item) => item.value,
		isItemDisabled: (item) => !!item.disabled
	})

	const machine = new VanillaMachine(select.machine, {
		id: root.id || `zag-select-${Math.random().toString(36).slice(2, 8)}`,
		collection,
		multiple,
		closeOnSelect: !multiple,
		/**
		 * defaultValue, not value.
		 *
		 * `value` makes the machine controlled: it defers to the prop and never
		 * mutates its own state, so selections fire onValueChange but never
		 * repaint. We drive the value imperatively instead.
		 */
		defaultValue: initial,
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

	const api = () => select.connect(machine.service, normalizeProps)

	const instance = {
		root,
		machine,
		native,
		config,
		parts,
		multiple,
		filterInstance,
		adapter: {
			getValue: () => api().value || [],
			setValue: (value) => api().setValue(toArray(value)),
			captureState: () => ({ open: api().open, highlightedValue: api().highlightedValue }),
			restoreState: (state) => {
				if (!state.open) {
					return
				}

				const current = api()
				current.setOpen(true)

				if (state.highlightedValue) {
					current.setHighlightValue(state.highlightedValue)
				}
			}
		}
	}

	instance.render = () => render(instance, api())

	machine.subscribe(instance.render)
	instance.render()

	register(instance)
}

/**
 * Build the trigger and listbox.
 *
 * Client-side rather than server-side because it is pure chrome: none of it
 * should exist for a user without JS, who gets the native <select>.
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

	/**
	 * Relocate the server-rendered icon into the trigger.
	 *
	 * Moving the node keeps Bricks' icon markup and classes intact, and means no
	 * arbitrary HTML is ever assigned through innerHTML.
	 */
	const icon = root.querySelector('[data-zag-icon]')

	if (icon) {
		trigger.append(icon)
		trigger.classList.add('has-icon')
	}

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

function render(instance, api) {
	const { root, parts, config, multiple } = instance

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

		parts.value.append(counter, document.createTextNode(' selected'))
	} else {
		parts.value.textContent = api.valueAsString
	}

	parts.value.classList.toggle('is-selected', values.length > 0)
}

function onValueChange(root, details) {
	const instance = mounted.get(root)

	// Fires once during machine.start(), before register().
	if (!instance) {
		return
	}

	const values = details.value || []

	syncNative(instance, values)

	emitChange(instance, {
		value: instance.multiple ? values : values[0] || '',
		items: details.items || []
	})

	applyFilter(instance, instance.multiple ? values.map(String) : String(values[0] || ''))
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
