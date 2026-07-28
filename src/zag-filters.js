import { mounted, startLifecycle } from './bricks.js'
import { mountSelect } from './select.js'
import { mountDatepicker } from './datepicker.js'
import { mountSearch, searchInstances } from './search.js'
import { activeFilterInstances, mountActiveFilters } from './active-filters.js'

/**
 * Entry point.
 *
 * One BricksFunction per element type. BricksFunction (global, frontend.js:826)
 * tracks which nodes it has already handled and re-runs on
 * bricks/ajax/query_result/displayed, which is what picks up the replacement
 * node after Bricks swaps a filter element out.
 */

function makeRunner(selector, mount, registry = mounted) {
	const fn = new window.BricksFunction({
		parentNode: document,
		selector,
		frontEndOnly: true,
		eachElement: (element) => {
			if (registry.has(element)) {
				return
			}

			mount(element)
		}
	})

	return () => fn.run()
}

/**
 * Order matters: the two controller elements read the filters through
 * filterTargets(), so the filters have to be mounted and registered before the
 * search adopts a value from the URL or the chips draw their first row.
 */
startLifecycle([
	makeRunner('.zag-select[data-zag-select]', mountSelect),
	makeRunner('.zag-datepicker[data-zag-datepicker]', mountDatepicker),
	makeRunner('.zag-search[data-zag-search]', mountSearch, searchInstances),
	makeRunner('.zag-active-filters[data-zag-active-filters]', mountActiveFilters, activeFilterInstances)
])
