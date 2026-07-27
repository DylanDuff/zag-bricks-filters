import { mounted, startLifecycle } from './bricks.js'
import { mountSelect } from './select.js'
import { mountDatepicker } from './datepicker.js'

/**
 * Entry point.
 *
 * One BricksFunction per element type. BricksFunction (global, frontend.js:826)
 * tracks which nodes it has already handled and re-runs on
 * bricks/ajax/query_result/displayed, which is what picks up the replacement
 * node after Bricks swaps a filter element out.
 */

function makeRunner(selector, mount) {
	const fn = new window.BricksFunction({
		parentNode: document,
		selector,
		frontEndOnly: true,
		eachElement: (element) => {
			if (mounted.has(element)) {
				return
			}

			mount(element)
		}
	})

	return () => fn.run()
}

startLifecycle([
	makeRunner('.zag-select[data-zag-select]', mountSelect),
	makeRunner('.zag-datepicker[data-zag-datepicker]', mountDatepicker)
])
