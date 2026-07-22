# Changelog

## 5.0.2 - 2026-07-22

### Fixed
- Publishing a release that had already been published could apply unrelated drafts to random entries. Applying a draft deletes it and the `draftId` foreign key is `SET NULL`, so published release entries carry a null draft ID — which `Entry::find()->id()` treated as "no filter" rather than "no match"
- Releases can no longer be published twice; `Published` is now enforced as a terminal status via `ReleaseStatus::isPublishable()`
- `publishRelease()` and `validateRelease()` threw a `TypeError` instead of failing gracefully when passed an unsaved release
- Release entries whose draft has gone missing are now reported by `validateRelease()` and refused by `publishRelease()`, instead of being silently skipped

### Added
- Craft-backed integration test suite running against a real Craft install, covering the releases service, draft discovery, field diffing, plugin event listeners, install migration schema, the scheduler command, and the publish queue job
- `composer test-unit` and `composer test-integration` scripts; `composer test` now runs both suites

## 5.0.1 - 2026-07-19

### Changed
- Expanded automated unit test coverage for the release status state machine, `Release`/`ReleaseEntry`/`Settings` validation, and `DiffService` field-value serialization
- Added a unit-suite bootstrap so model validation rules can be tested without booting a full Craft application

## 5.0.0 - 2026-06-12

### Added
- Field-by-field diff comparison for drafts vs. live content
- Side-by-side visual preview with synchronized scrolling
- Release management for coordinated multi-entry publishing
- Atomic publish — all entries in a release publish together or none do
- Scheduled releases with cron-based automation
- Dashboard with draft overview and release status
- Permissions for diff viewing, release management, and settings
- Entry editor sidebar integration showing diff summary and release membership
