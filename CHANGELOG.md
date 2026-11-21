# Core Edit Tracker Changelog

File headers (`@version` / `@updated`) record the release that last modified
each file, not the current release, the same convention as Zen Cart core.

## v1.0.0 (11-21-2026)

First release.

- Compares the store with the official Zen Cart zip of the version it runs.
  The zip is checked, kept whole under a name nobody can guess and never
  unpacked.
- Lists core edits, template overrides and missing files, grouped by folder,
  with search, sort and a peek at each file's first change.
- Covers every template assigned in Template Selection and pairs each
  override with the Zen Cart file it replaces.
- Marks files newly edited, changed again, back to stock or newly missing
  since the scan before. The last ten scans are kept.
- Compare view, side by side or inline, with the differing characters
  marked, a change map, whole file view, ignore whitespace and copy as patch.
- Export: one zip with each changed file and its Zen Cart original beside it
  as `.ORIGINAL`, Zen Cart's sample configure files for reference, a
  `MANIFEST.json` and a `README.md` on how to carry the edits to a new
  release.
- Data folder beside the web root, in the store cache folder or in a folder
  you choose, with a web access test. It can be changed after install.
- Detects a Zen Cart upgrade and asks for the new version's release zip.
- Configure files are never opened. Files that may hold a secret are flagged,
  shown masked and left out of the export by default.
- An optional helper class lets another plugin declare the files it owns, a
  verified copy of each and its marker file endings. The setting Accept
  companion plugins switches this off.
- All page text is in language files.
