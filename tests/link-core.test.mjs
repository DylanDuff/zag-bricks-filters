import { test } from 'node:test'
import assert from 'node:assert/strict'

import { computeHidden } from '../src/link-core.js'

// Posts 1-3 are locations. Penrith offers both services, Parramatta only physio.
const locations = (values = []) => ({
	key: 'location',
	postType: 'location',
	values,
	items: [
		{ value: '10', posts: [1, 2] }, // Penrith
		{ value: '11', posts: [3] }, // Parramatta
		{ value: '12', posts: [] } // Empty term
	]
})

const services = (values = []) => ({
	key: 'service',
	postType: 'location',
	values,
	items: [
		{ value: '20', posts: [1, 3] }, // Physio
		{ value: '21', posts: [2] } // Massage
	]
})

const hiddenOf = (result, key) => [...result.get(key)].sort()

test('nothing selected hides nothing', () => {
	const result = computeHidden([locations(), services()])

	assert.deepEqual(hiddenOf(result, 'location'), [])
	assert.deepEqual(hiddenOf(result, 'service'), [])
})

test('a location hides services it has no posts in common with', () => {
	const result = computeHidden([locations(['11']), services()])

	assert.deepEqual(hiddenOf(result, 'service'), ['21'])
	// A select never narrows itself.
	assert.deepEqual(hiddenOf(result, 'location'), [])
})

test('and vice versa', () => {
	const result = computeHidden([locations(), services(['21'])])

	assert.deepEqual(hiddenOf(result, 'location'), ['11', '12'])
})

test('both selected narrows each by the other', () => {
	const result = computeHidden([locations(['10']), services(['20'])])

	assert.deepEqual(hiddenOf(result, 'location'), ['12'])
	assert.deepEqual(hiddenOf(result, 'service'), [])
})

test('a multi-selection reads as the union of its options', () => {
	const result = computeHidden([locations(['10', '11']), services()])

	assert.deepEqual(hiddenOf(result, 'service'), [])
})

test('a selected option is never hidden', () => {
	const extra = { key: 'extra', postType: 'location', values: ['30'], items: [{ value: '30', posts: [3] }] }
	// Massage (post 2) rules out Parramatta (post 3), which is already picked.
	const result = computeHidden([locations(['11']), services(['21']), extra])

	assert.deepEqual(hiddenOf(result, 'location'), ['10', '12'])
	assert.ok(!result.get('service').has('21'))
	assert.ok(!result.get('extra').has('30'))
})

test('selects on different post types do not constrain each other', () => {
	const other = { ...services(['21']), postType: 'post' }
	const result = computeHidden([locations(), other])

	assert.deepEqual(hiddenOf(result, 'location'), [])
})

test('values compare as strings', () => {
	const result = computeHidden([{ ...locations(), values: [11] }, services()])

	assert.deepEqual(hiddenOf(result, 'service'), ['21'])
})

test('a pick with no posts behind it hides nothing', () => {
	const result = computeHidden([locations(['12']), services()])

	assert.deepEqual(hiddenOf(result, 'service'), [])
})

test('missing post data never empties a sibling', () => {
	const bare = (member) => ({ ...member, items: member.items.map((item) => ({ ...item, posts: [] })) })
	const result = computeHidden([bare(locations(['10'])), bare(services())])

	assert.deepEqual(hiddenOf(result, 'service'), [])
	assert.deepEqual(hiddenOf(result, 'location'), [])
})
