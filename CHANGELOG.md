# Changelog

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
