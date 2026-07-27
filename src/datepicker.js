import { VanillaMachine, normalizeProps, spreadProps } from '@zag-js/vanilla'
import * as datePicker from '@zag-js/date-picker'
import { parseDate } from '@internationalized/date'

import { applyFilter, emitChange, getFilterInstance, mounted, register, seedFilterInstance } from './bricks.js'

/**
 * Zag date picker UI over a Bricks query filter.
 *
 * Values move as the comma-joined ISO string Bricks' own datepicker uses:
 * "2026-05-01" or "2026-05-01,2026-05-15" for a range. Query_Filters parses
 * exactly that shape, so keeping to it means no value recovery is needed —
 * unlike multi-select.
 *
 * Note: the `enableTime` setting is honoured by the query layer but this UI is
 * date-only, so a time component is never produced. See CLAUDE.md.
 */

const ISO = (date) =>
	`${date.year}-${String(date.month).padStart(2, '0')}-${String(date.day).padStart(2, '0')}`

const SVG_PREV =
	'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14.5 7 L9.5 12 L14.5 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
const SVG_NEXT =
	'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9.5 7 L14.5 12 L9.5 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'

/** Parse the stored string into DateValues, discarding anything malformed. */
function parseValue(value) {
	if (!value) {
		return []
	}

	return String(value)
		.split(',')
		.map((part) => part.trim().split(' ')[0]) // Drop any time component.
		.filter(Boolean)
		.map((part) => {
			try {
				return parseDate(part)
			} catch {
				// Not a date we can use; drop it rather than poison the value.
				return null
			}
		})
		.filter(Boolean)
}

export function mountDatepicker(root) {
	let config

	try {
		config = JSON.parse(root.dataset.zagDatepicker)
	} catch (e) {
		console.error('[zag] Could not parse datepicker config', e, root)
		return
	}

	const native = root.querySelector('[data-zag-native]')
	const filterInstance = getFilterInstance(root)
	const range = config.mode === 'range'
	const initial = parseValue(config.value)

	seedFilterInstance(filterInstance, config.value || '', '')

	const machineConfig = {
		id: root.id || `zag-datepicker-${Math.random().toString(36).slice(2, 8)}`,
		locale: config.locale || 'en-GB',
		startOfWeek: config.startOfWeek ?? 1,
		selectionMode: range ? 'range' : 'single',
		defaultValue: initial,
		positioning: {
			placement: config.placement || 'bottom-start',
			gutter: 8,
			flip: true
		},
		onValueChange: (details) => onValueChange(root, details)
	}

	// Bounds come from the index, so only apply them when the element asked for it.
	const min = parseValue(config.min)[0]
	const max = parseValue(config.max)[0]

	if (min) {
		machineConfig.min = min
	}

	if (max) {
		machineConfig.max = max
	}

	const machine = new VanillaMachine(datePicker.machine, machineConfig)
	machine.start()

	const parts = buildParts(root, config)

	native.hidden = true
	native.setAttribute('aria-hidden', 'true')
	native.tabIndex = -1

	const api = () => datePicker.connect(machine.service, normalizeProps)

	const instance = {
		root,
		machine,
		native,
		config,
		parts,
		range,
		filterInstance,
		adapter: {
			getValue: () => {
				const value = api().value || []

				return value.length ? [value.map(ISO).join(',')] : []
			},
			setValue: (value) => api().setValue(parseValue(value)),
			captureState: () => ({ open: api().open }),
			restoreState: (state) => {
				if (state.open) {
					api().setOpen(true)
				}
			}
		}
	}

	instance.render = () => render(instance, api())

	machine.subscribe(instance.render)
	instance.render()

	/**
	 * Bricks' reset path calls .clear() and .setDate() on whatever it finds at
	 * filterInstance.datepicker, assuming flatpickr (filters.js:256). It bails
	 * early if the property is missing, which would make reset silently not
	 * clear the date rather than error. Expose that shape.
	 */
	if (filterInstance) {
		filterInstance.datepicker = {
			clear: () => api().clearValue(),
			setDate: (value) => api().setValue(parseValue(value))
		}
	}

	register(instance)
}

function buildParts(root, config) {
	const control = document.createElement('div')
	control.className = 'zag-datepicker__control'

	const trigger = document.createElement('button')
	trigger.type = 'button'
	trigger.className = 'zag-datepicker__trigger'

	const value = document.createElement('span')
	value.className = 'zag-datepicker__value'
	value.textContent = config.placeholder

	const icon = document.createElement('span')
	icon.className = 'zag-datepicker__icon'
	icon.innerHTML =
		'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'

	trigger.append(value, icon)
	control.append(trigger)

	const positioner = document.createElement('div')
	positioner.className = 'zag-datepicker__positioner'

	const content = document.createElement('div')
	content.className = 'zag-datepicker__content'

	positioner.append(content)
	root.append(control, positioner)

	return { control, trigger, value, positioner, content }
}

/** Small hyperscript helper — the calendar is rebuilt wholesale each render. */
function h(tag, props, ...children) {
	const node = document.createElement(tag)

	if (props) {
		spreadProps(node, props)
	}

	children.forEach((child) => {
		if (child === null || child === undefined || child === false) {
			return
		}

		node.append(child.nodeType ? child : document.createTextNode(String(child)))
	})

	return node
}

function buildDayView(api) {
	const table = h('table', api.getTableProps({ view: 'day' }))
	table.className = 'zag-datepicker__table'

	const head = document.createElement('thead')
	const headRow = document.createElement('tr')

	;(api.weekDays || []).forEach((day) => {
		const th = document.createElement('th')
		th.className = 'zag-datepicker__weekday'
		th.scope = 'col'
		th.setAttribute('aria-label', day.long || '')
		th.textContent = (day.narrow || day.short || day.long || '').slice(0, 2)
		headRow.append(th)
	})

	head.append(headRow)
	table.append(head)

	const body = h('tbody', api.getTableBodyProps({ view: 'day' }))

	;(api.weeks || []).forEach((week) => {
		const row = h('tr', api.getTableRowProps({ view: 'day' }))

		week.forEach((value) => {
			const cell = h('td', api.getDayTableCellProps({ value }))
			const trigger = h('div', api.getDayTableCellTriggerProps({ value }))
			trigger.className = 'zag-datepicker__day'
			trigger.textContent = value.day
			cell.append(trigger)
			row.append(cell)
		})

		body.append(row)
	})

	table.append(body)

	return table
}

function buildGridView(api, view) {
	const rows =
		view === 'month'
			? api.getMonthsGrid({ columns: 3, format: 'short' })
			: api.getYearsGrid({ columns: 3 })

	const table = document.createElement('table')
	table.className = 'zag-datepicker__table zag-datepicker__table--grid'

	const body = document.createElement('tbody')

	;(rows || []).forEach((row) => {
		const tr = document.createElement('tr')

		row.forEach((cell) => {
			const cellProps =
				view === 'month'
					? api.getMonthTableCellProps({ value: cell.value, columns: 3 })
					: api.getYearTableCellProps({ value: cell.value, columns: 3 })

			const triggerProps =
				view === 'month'
					? api.getMonthTableCellTriggerProps({ value: cell.value, columns: 3 })
					: api.getYearTableCellTriggerProps({ value: cell.value, columns: 3 })

			const td = h('td', cellProps)
			const trigger = h('div', triggerProps)
			trigger.className = 'zag-datepicker__cell'
			trigger.textContent = cell.label
			td.append(trigger)
			tr.append(td)
		})

		body.append(tr)
	})

	table.append(body)

	return table
}

function render(instance, api) {
	const { root, parts, config } = instance

	spreadProps(root, api.getRootProps())
	spreadProps(parts.control, api.getControlProps())
	spreadProps(parts.trigger, api.getTriggerProps())
	spreadProps(parts.positioner, api.getPositionerProps())
	spreadProps(parts.content, api.getContentProps())

	// Keep the calendar mounted so the close transition can play.
	parts.positioner.removeAttribute('hidden')
	parts.content.removeAttribute('hidden')
	root.dataset.open = api.open ? 'true' : 'false'

	// The calendar is cheap to rebuild and the view can change under us.
	parts.content.innerHTML = ''

	const view = api.view || 'day'

	const viewControl = h('div', api.getViewControlProps({ view }))
	viewControl.className = 'zag-datepicker__viewcontrol'

	const prev = h('button', api.getPrevTriggerProps({ view }))
	prev.className = 'zag-datepicker__nav'
	prev.innerHTML = SVG_PREV

	const title = h('button', api.getViewTriggerProps({ view }))
	title.className = 'zag-datepicker__title'
	title.textContent = api.visibleRangeText?.start || String(api.focusedValue?.year || '')

	const next = h('button', api.getNextTriggerProps({ view }))
	next.className = 'zag-datepicker__nav'
	next.innerHTML = SVG_NEXT

	viewControl.append(prev, title, next)
	parts.content.append(viewControl)
	parts.content.append(view === 'day' ? buildDayView(api) : buildGridView(api, view))

	const values = api.value || []

	if (!values.length) {
		parts.value.textContent = config.placeholder
	} else {
		parts.value.textContent = values.map(ISO).join(' – ')
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

	/**
	 * A range mid-selection has only its start date. Emitting that would filter
	 * on a single day and then immediately refilter, so hold until it's whole.
	 */
	if (instance.range && values.length === 1) {
		return
	}

	const joined = values.map(ISO).join(',')

	instance.native.value = joined
	instance.native.dispatchEvent(new Event('change', { bubbles: true }))

	emitChange(instance, { value: joined, dates: values.map(ISO) })

	applyFilter(instance, joined)
}
