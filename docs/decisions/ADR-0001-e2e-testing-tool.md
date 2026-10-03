# ADR-0001: End-to-end testing tool

- Status: Accepted
- Date: 2026-10-03
- Spec reference: `docs/cahier-des-charges.md` §19.2

## Context

The specification requires E2E tests (§16, §17) on mobile and desktop browsers, including Safari/WebKit where Web Crypto and the Ed25519 fallback must be exercised.

## Decision

Use **Playwright** for E2E tests, running Chromium, Firefox and WebKit projects plus mobile device emulation in CI. No third-party testing cloud service. The real-device matrix (physical iOS/Android phones, desktop browsers) is executed manually and is tracked as a human action for Phase 3.

## Consequences

- Playwright becomes a frontend dev dependency (Node tooling only, never shipped).
- WebKit coverage in CI catches Safari-specific Web Crypto gaps early.
- Emulation does not replace real-device validation; results of manual runs are recorded per release.
