# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [0.1.1] - 2026-11-09

### Added

- "Recently viewed": small links inside What's next and What needs my attention to the last five activities you looked at, so a refresh doesn't lose your place. A link brings the card on stage and scrolls to it. Includes a new course setting to switch it off. A course reset that clears completion or unenrols users clears the lists too.

### Fixed

- A course page with a YouTube video on screen could keep loading for as long as YouTube took to answer. The video card now shows a play button, and the player loads only when it is clicked, so nothing is fetched from YouTube until then.
- Uninstalling the format now removes its user preferences (the view mode and Recently viewed), which Moodle leaves behind.

## [0.1.0] - 2026-11-04

### Added

- First release. See `CHANGES.md`.
