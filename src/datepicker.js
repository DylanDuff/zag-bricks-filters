import { VanillaMachine, normalizeProps, spreadProps } from '@zag-js/vanilla'
import * as datePicker from '@zag-js/date-picker'
import { DateFormatter, getLocalTimeZone, parseDate } from '@internationalized/date'

import { applyFilter, emitChange, getFilterInstance, insertControl, mounted, register, seedFilterInstance, updateCaption } from './bricks.js'

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

const ORDINAL_SUFFIXES = { one: 'st', two: 'nd', few: 'rd', other: 'th' }

/**
 * Ordinals are English-only here.
 *
 * Intl.PluralRules gives an ordinal category for any locale, but the st/nd/rd/th
 * mapping is English — German returns 'other' for everything and would render
 * "1th" where it wants "1.". Anything non-English falls back to the plain long
 * form rather than producing wrong grammar.
 */
function isEnglish(locale) {
	return String(locale).toLowerCase().split('-')[0] === 'en'
}

function ordinalise(parts, locale) {
	const rules = new Intl.PluralRules(locale, { type: 'ordinal' })

	return parts
		.map((part) => {
			if (part.type !== 'day') {
				return part.value
			}

			// Handles the 11th/12th/13th exceptions a naive n%10 map gets wrong.
			return `${part.value}${ORDINAL_SUFFIXES[rules.select(Number(part.value))] || 'th'}`
		})
		.join('')
}

/**
 * Format the selected date(s) for the trigger label.
 *
 * Display only — the value written back to Bricks stays ISO. Ranges collapse
 * the parts both ends share, so "1 May 2026 – 15 May 2026" reads as
 * "1 – 15 May 2026". Numeric ('short') styles are never collapsed: "01 –
 * 15/05/2026" is worse than the long form, not better.
 */
function formatDates(values, locale, dateStyle) {
	const zone = getLocalTimeZone()
	const ordinal = dateStyle === 'ordinal' && isEnglish(locale)

	// 'ordinal' isn't a real Intl style; it's the long form with a suffixed day.
	const style = dateStyle === 'ordinal' ? 'long' : dateStyle

	const format = (date, options) => {
		// dateStyle can't be combined with component options, so when we need
		// the parts back to suffix the day, spell the components out instead.
		const resolved = ordinal && options.dateStyle
			? { day: 'numeric', month: 'long', year: 'numeric' }
			: options

		const formatter = new DateFormatter(locale, resolved)
		const jsDate = date.toDate(zone)

		return ordinal ? ordinalise(formatter.formatToParts(jsDate), locale) : formatter.format(jsDate)
	}

	if (!values.length) {
		return ''
	}

	if (values.length === 1) {
		return format(values[0], { dateStyle: style })
	}

	const [start, end] = values
	const full = format(end, { dateStyle: style })

	if (style === 'short') {
		return `${format(start, { dateStyle: style })} – ${full}`
	}

	if (start.year === end.year && start.month === end.month) {
		return `${format(start, { day: 'numeric' })} – ${full}`
	}

	if (start.year === end.year) {
		// Match the month width the end is using, or the two halves disagree
		// ("3rd May – 22nd August 2026").
		const month = style === 'medium' ? 'short' : 'long'

		return `${format(start, { day: 'numeric', month })} – ${full}`
	}

	return `${format(start, { dateStyle: style })} – ${full}`
}

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

	// Preserve Bricks' element id through mounting — see the note in select.js.
	const bricksId = root.id

	const machineConfig = {
		id: bricksId || `zag-datepicker-${Math.random().toString(36).slice(2, 8)}`,
		ids: bricksId ? { root: bricksId } : undefined,
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

	if (!parts.input) {
		native.hidden = true
		native.setAttribute('aria-hidden', 'true')
		native.tabIndex = -1
	}

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
			/**
			 * The custom icon, if one replaced the calendar glyph.
			 *
			 * Only ever matches when zagIcon is set: fillIcon() marks the host it
			 * relocated into, and leaves the default calendar unmarked because
			 * that is chrome rather than something the editor chose.
			 */
			icon: () => root.querySelector('[data-zag-icon]'),
			/**
			 * One chip for the whole value, not one per date.
			 *
			 * A range is a single filter clause — removing half of it would
			 * leave a state the calendar can't represent — so the chip carries
			 * the joined value and removing it clears the field. Labelled with
			 * the same formatter the field itself uses, so the chip and the
			 * control never disagree.
			 */
			describe: () => {
				const values = api().value || []

				if (!values.length) {
					return []
				}

				return [
					{
						value: values.map(ISO).join(','),
						label: formatDates(values, config.locale || 'en-GB', config.dateStyle || 'medium')
					}
				]
			},
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
	// Rendered server-side; keep it first so it reads above the field.
	const label = root.querySelector('[data-zag-label]')

	const control = document.createElement('div')
	control.className = 'zag-datepicker__control'

	const CALENDAR_SVG =
		'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'

	/**
	 * A custom icon replaces the calendar glyph; without one, the default stands.
	 *
	 * The server renders it into a hidden carrier, so its children are *moved*
	 * rather than serialised — Bricks' icon markup and classes survive and no
	 * arbitrary HTML goes through innerHTML. Snapshotted with Array.from because
	 * appending moves nodes out of the live childNodes list as it iterates.
	 */
	const provided = root.querySelector('[data-zag-icon]')
	const glyphs = provided ? Array.from(provided.childNodes) : []

	const fillIcon = (host) => {
		if (glyphs.length) {
			host.append(...glyphs)

			// Same contract as the select: marks a user-set icon so the active
			// filters element can clone it. The default calendar is chrome and
			// deliberately stays unmarked.
			host.setAttribute('data-zag-icon', '')
		} else {
			host.innerHTML = CALENDAR_SVG
		}

		provided?.remove()
	}

	const trigger = document.createElement('button')
	trigger.type = 'button'

	let value = null
	let input = null

	if (config.typeable) {
		/**
		 * Promote the progressive-enhancement input to the real one.
		 *
		 * It already carries the field name and value, so reusing it means the
		 * no-JS baseline and the enhanced control are the same node rather than
		 * two that have to be kept in step.
		 */
		input = root.querySelector('[data-zag-native]')
		input.className = 'zag-datepicker__input'

		trigger.className = 'zag-datepicker__iconbtn'
		trigger.setAttribute('aria-label', config.placeholder || 'Open calendar')
		fillIcon(trigger)

		control.append(input, trigger)
	} else {
		trigger.className = 'zag-datepicker__trigger'

		value = document.createElement('span')
		value.className = 'zag-datepicker__value'
		value.textContent = config.placeholder

		const icon = document.createElement('span')
		icon.className = 'zag-datepicker__icon'
		fillIcon(icon)

		trigger.append(value, icon)
		control.append(trigger)
	}

	const positioner = document.createElement('div')
	positioner.className = 'zag-datepicker__positioner'

	const content = document.createElement('div')
	content.className = 'zag-datepicker__content'

	positioner.append(content)
	insertControl(root, control, positioner)

	return { label, control, trigger, value, input, positioner, content }
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

	// See the note in select.js: without this the control has no accessible name.
	if (parts.label) {
		spreadProps(parts.label, api.getLabelProps({ index: 0 }))
	}

	spreadProps(parts.control, api.getControlProps())
	spreadProps(parts.trigger, api.getTriggerProps())

	if (parts.input) {
		spreadProps(parts.input, api.getInputProps())
	}
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

	const locale = config.locale || 'en-GB'
	const style = config.dateStyle || 'medium'

	// With typing on, Zag owns the input's text; there's no label span to paint.
	if (parts.value) {
		parts.value.textContent = values.length ? formatDates(values, locale, style) : config.placeholder
		parts.value.classList.toggle('is-selected', values.length > 0)
	}

	updateCaption(root, values.length ? formatDates(values, locale, style) : '')
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

	if (!instance.parts.input) {
		instance.native.value = joined
	}

	instance.native.dispatchEvent(new Event('change', { bubbles: true }))

	emitChange(instance, { value: joined, dates: values.map(ISO) })

	applyFilter(instance, joined)
}
