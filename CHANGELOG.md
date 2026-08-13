# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

Initial release of `crustum/mongo` (`Crustum\Mongo`).

## Unreleased

### Added
- `ODM\Association::attachTo()` — in-pipeline (lookup) association attach, the
  ODM analog of cake60 `Association::attachTo()`; registers the association on
  the query eager loader so `$lookup` stages build and results nest (doc 32).

### Tests
- Rewrote `BelongsToTest::testAttachTo*` to the ODM shape (assert the `$lookup`
  pipeline, not SQL `clause('join')`/`clause('select')`); added
  `testAttachToEndToEnd`. SQL-only cases (multi-column primary keys, target
  `beforeFind`) marked skip (F25).
