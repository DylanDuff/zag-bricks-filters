/**
 * Builder-canvas mount decision.
 *
 * Bricks' own interactive elements set `frontEndOnly: true` on their
 * BricksFunction runners to keep from fighting the canvas' click-to-select
 * handling — enforced by checking for a `bricks-is-frontend` body class that
 * never exists in canvas, since `bricks_is_frontend()` is literally
 * `!bricks_is_builder()`. zag-filters.js copied that pattern, which meant the
 * bundle never mounted in canvas at all — and because the pre-mount CSS
 * hides the native control unconditionally once `.zag-js` is set (see
 * plugin.php's wp_head callback), that combination made every Zag element
 * permanently invisible in the builder, not just non-interactive.
 *
 * `.zag-builder` (also set there) tells the stylesheet to skip that hide by
 * default, so canvas shows the plain native control rather than nothing.
 * Each element has its own "Preview in builder" checkbox
 * (`inc/builder-preview.php`), which — only inside canvas — renders a
 * `data-zag-preview` attribute on that one instance's root. That's the
 * opt-in this module checks: per element, not page-wide, so you can preview
 * the one you're actually styling without turning every Zag element on the
 * page into a live, request-firing control while you edit.
 */

export function isBuilderCanvas() {
	return document.documentElement.classList.contains('zag-builder')
}

export function shouldMount(element) {
	return !isBuilderCanvas() || element.hasAttribute('data-zag-preview')
}
