import { VanillaMachine, normalizeProps, spreadProps } from '@zag-js/vanilla'
import * as select from '@zag-js/select'

import { applyFilter, emitChange, getFilterInstance, insertControl, mounted, register, seedFilterInstance, toArray, updateCaption } from './bricks.js'
import { scheduleRelink } from './link.js'

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

	/**
	 * Values a linked sibling has ruled out (src/link.js). Read live by the
	 * collection, so a hidden option is also skipped by keyboard navigation and
	 * typeahead rather than merely invisible.
	 */
	let hidden = new Set()

	const collection = select.collection({
		items: config.items || [],
		itemToString: (item) => item.label,
		itemToValue: (item) => item.value,
		isItemDisabled: (item) => !!item.disabled || hidden.has(String(item.value))
	})

	/**
	 * Bricks' element id, which must survive mounting.
	 *
	 * getRootProps() sets an id of its own (`select:<machineId>`) and
	 * spreadProps applies it, renaming the element out from under the
	 * stylesheet. Bricks generates style control CSS as `#brxe-<id> { … }`
	 * (assets.php:2897), so once renamed that rule matches nothing and every
	 * style control silently stops working. Handing Zag the id back through
	 * `ids.root` keeps both happy.
	 */
	const bricksId = root.id

	const machine = new VanillaMachine(select.machine, {
		id: bricksId || `zag-select-${Math.random().toString(36).slice(2, 8)}`,
		ids: bricksId ? { root: bricksId } : undefined,
		collection,
		multiple,
		closeOnSelect: !multiple,
		/**
		 * A linked single select has to be clearable: its pick narrows the
		 * group's other selects, and with no way to clear it the visitor could
		 * only swap one constraint for another. Clicking the chosen option
		 * again clears it.
		 */
		deselectable: !!config.link,
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
		isHidden: (value) => hidden.has(String(value)),
		setHidden: (values) => {
			hidden = new Set(values || [])
			instance.render()
		},
		adapter: {
			getValue: () => api().value || [],
			setValue: (value) => api().setValue(toArray(value)),
			/**
			 * One descriptor per selected value, for the active filters chips.
			 *
			 * Labelled with the count-free text, so a chip never reads
			 * "Penrith (4)". A value with no matching option still gets a chip
			 * showing the raw value — better a bare chip that can be removed
			 * than a filter the visitor can see working but not clear.
			 */
			/**
			 * The element's own leading icon, for anything drawing chips.
			 *
			 * Live node, not a clone — buildParts() relocated it into the
			 * trigger, so it is still inside the root. Callers clone what they
			 * need; nothing may move it again.
			 */
			icon: () => root.querySelector('[data-zag-icon]'),
			describe: () => {
				const byValue = new Map((config.items || []).map((item) => [String(item.value), item]))

				return (api().value || []).map((value) => {
					const item = byValue.get(String(value))

					return {
						value: String(value),
						label: item ? item.match || item.label : String(value)
					}
				})
			},
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

	if (config.link) {
		scheduleRelink(root)
	}
}

/**
 * Build the trigger and listbox.
 *
 * Client-side rather than server-side because it is pure chrome: none of it
 * should exist for a user without JS, who gets the native <select>.
 */
function buildParts(root, config) {
	// Rendered server-side; keep it first so it reads above the field.
	const label = root.querySelector('[data-zag-label]')

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
	insertControl(root, control, positioner)

	return { label, control, trigger, value, positioner, content, items }
}

function render(instance, api) {
	const { root, parts, config, multiple } = instance

	spreadProps(root, api.getRootProps())

	/**
	 * Binds the label to the control. Without this Zag's aria-labelledby on the
	 * trigger points at an id nothing carries, leaving the combobox with no
	 * accessible name.
	 */
	if (parts.label) {
		spreadProps(parts.label, api.getLabelProps())
	}

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
		el.hidden = instance.isHidden(item.value)
	})

	// Keep the fallback control honest about what can be picked.
	Array.from(instance.native.options).forEach((option) => {
		if (option.value !== '') {
			option.hidden = instance.isHidden(option.value)
		}
	})

	const values = api.value || []

	if (!values.length) {
		parts.value.textContent = config.placeholder
	} else if (multiple && values.length > 1) {
		parts.value.innerHTML = ''

		const counter = document.createElement('span')
		counter.className = 'zag-select__counter'
		counter.textContent = values.length

		parts.value.append(counter, document.createTextNode(` ${config.counterLabel || 'selected'}`))
	} else {
		parts.value.textContent = api.valueAsString
	}

	parts.value.classList.toggle('is-selected', values.length > 0)

	updateCaption(root, api.valueAsString)
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
