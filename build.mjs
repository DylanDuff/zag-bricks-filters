import { build, context } from 'esbuild'

const watch = process.argv.includes('--watch')

const options = {
	entryPoints: ['src/zag-filters.js'],
	outfile: 'assets/js/zag-filters.js',
	bundle: true,
	format: 'iife',
	target: ['es2020'],
	minify: !watch,
	sourcemap: watch,
	logLevel: 'info'
}

if (watch) {
	const ctx = await context(options)
	await ctx.watch()
} else {
	await build(options)
}
