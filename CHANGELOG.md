# Changelog

Notable changes to `aisdk/openai`. Release notes are also published at https://github.com/phpaisdk/openai/releases.

## v0.11.0 — 2026-10-31

- Requires `aisdk/core ^0.11.0` and `aisdk/openai-compatible ^0.11.0`. Upgrade SDK packages together; no other changes in this package.

## v0.10.1 — 2026-10-29

- Raise SDK exceptions instead of native PHP ones.

## v0.10.0 — 2026-10-29

### What's new

- Upload, retrieve, list, download and delete files with `OpenAI::files()`, and attach stored documents and images to requests.
- Native Decisions: ask predicate, choice and score questions with probabilities and explicit refusal, using text, JSON or image evidence.
- Stored files and document URLs use the Responses API automatically; prompt-cache keys are preserved.

### Compatibility

- Requires `aisdk/core ^0.10.0` and `aisdk/openai-compatible ^0.10.0`. Upgrade SDK packages together.

## Earlier releases

See the [GitHub releases](https://github.com/phpaisdk/openai/releases) for versions before 0.10.0.
