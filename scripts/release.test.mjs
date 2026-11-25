// Tests of scripts/release.mjs on throwaway copies of the files it reads: node --test scripts/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { check, notes } from './release.mjs'

const CHANGELOG = `# Changelog

## [Unreleased]

## [1.2.0] - 2026-12-01

### Fixed

- Import: see [the README](README.md#csv-import) and [CONTRIBUTING](./CONTRIBUTING.md).

## [1.1.0] - 2026-11-01

- Older entry.

[Unreleased]: https://github.com/HiteaFR/tirelire/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/HiteaFR/tirelire/releases/tag/v1.2.0
[1.1.0]: https://github.com/HiteaFR/tirelire/releases/tag/v1.1.0
`

/** A repository copy where version 1.2.0 agrees everywhere, with some files overridden. */
function repo(overrides = {}) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'tirelire-release-'))
  const files = {
    'package.json': JSON.stringify({ name: 'tirelire', version: '1.2.0' }),
    'package-lock.json': JSON.stringify({ name: 'tirelire', version: '1.2.0', packages: { '': { version: '1.2.0' } } }),
    'compose.yaml': 'services:\n  app:\n    image: ${TIRELIRE_IMAGE:-ghcr.io/hiteafr/tirelire:1.2.0}\n',
    'README.md': 'git clone --branch v1.2.0 https://github.com/HiteaFR/tirelire.git\n`gh attestation verify oci://ghcr.io/hiteafr/tirelire:1.2.0`\n',
    'CHANGELOG.md': CHANGELOG,
    ...overrides,
  }
  for (const [name, content] of Object.entries(files)) fs.writeFileSync(path.join(root, name), content)
  return root
}

test('check: no disagreement when the version agrees everywhere', () => {
  assert.deepEqual(check('1.2.0', { root: repo() }), [])
})

test('check: names each file that disagrees with the version', () => {
  const root = repo({
    'package-lock.json': JSON.stringify({ version: '1.2.0', packages: { '': { version: '1.1.0' } } }),
    'compose.yaml': 'image: ${TIRELIRE_IMAGE:-ghcr.io/hiteafr/tirelire:1.1.0}\n',
    'README.md': 'git clone --branch v1.2.0 x\noci://ghcr.io/hiteafr/tirelire:1.1.0`\n',
  })

  assert.deepEqual(check('1.2.0', { root }), [
    'package-lock.json: version 1.2.0/1.1.0, 1.2.0 expected',
    'compose.yaml: ghcr.io/hiteafr/tirelire:1.1.0, 1.2.0 expected',
    'README.md: ghcr.io/hiteafr/tirelire:1.1.0, 1.2.0 expected',
  ])
})

test('check: refuses a version that is not the first entry under Unreleased, or without its links', () => {
  const root = repo({ 'package.json': JSON.stringify({ version: '1.1.0' }) })

  const errors = check('1.1.0', { root })

  assert.ok(errors.includes('CHANGELOG.md: first version entry "## [1.2.0] - 2026-12-01", "## [1.1.0] - YYYY-MM-DD" expected'))
  assert.ok(errors.includes('CHANGELOG.md: link [Unreleased] to compare/v1.1.0...HEAD missing'))
})

test('check: refuses a compose file without the published image, and a malformed version', () => {
  assert.deepEqual(check('1.2.0', { root: repo({ 'compose.yaml': 'services:\n  app:\n    build: .\n' }) }), [
    'compose.yaml: no ghcr.io/hiteafr/tirelire image',
  ])
  assert.deepEqual(check('v1.2', { root: repo() }), ['version "v1.2": x.y.z expected'])
})

test('notes: the entry of the version only, links pinned to the tag, digest and install commands', () => {
  const digest = `sha256:${'a'.repeat(64)}`

  const text = notes('1.2.0', { root: repo(), digest })

  assert.ok(text.startsWith('### Fixed\n\n- Import: see [the README](https://github.com/HiteaFR/tirelire/blob/v1.2.0/README.md#csv-import) and [CONTRIBUTING](https://github.com/HiteaFR/tirelire/blob/v1.2.0/CONTRIBUTING.md).\n\n## Install'))
  assert.ok(!text.includes('Older entry'))
  assert.ok(text.includes(`Digest: \`${digest}\``))
  assert.ok(text.includes('gh attestation verify oci://ghcr.io/hiteafr/tirelire:1.2.0 --repo HiteaFR/tirelire'))
})

test('notes: refuses a malformed digest and a version without an entry', () => {
  assert.throws(() => notes('1.2.0', { root: repo(), digest: 'sha256:abc' }), /digest "sha256:abc"/)
  assert.throws(() => notes('1.3.0', { root: repo() }), /no 1\.3\.0 entry/)
})
