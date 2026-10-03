# Security Policy

## Reporting a vulnerability

Please do not disclose security vulnerabilities in a public issue, pull request or discussion.

Use GitHub Security Advisories or the repository's private security contact when available. If the private reporting channel is not enabled, use the security contact published in the repository metadata. The final public repository must publish an operational private reporting channel before its first release.

Do not include plaintext secrets, decryption keys, passphrases, complete share or management URLs, user content, personal data or production credentials in a report. Redact logs and provide the smallest reproducible example possible.

Only test instances that you own or are explicitly authorized to test. Do not perform denial-of-service tests, mass scanning, scraping or attempts to access other users' content.

## Scope

Reports are relevant to:

- the browser frontend and local cryptographic operations;
- the Symfony API and PHP CLI;
- the file-based storage, locking and atomic-write implementation;
- expiration, single-read consumption, deletion and idempotency;
- Markdown rendering and sanitization;
- Docker, configuration, cache and logging behavior;
- release integrity, dependencies and build artifacts.

The V1 project does not use a database. Its persistent data is stored in a local filesystem volume containing encrypted payloads and minimal metadata. Reports must not assume or request access to a database service.

The security model does not protect against a deployment that serves malicious JavaScript, a compromised user device or a recipient who intentionally copies a secret. These limits remain reportable if an implementation flaw makes them broader than documented.

## Supported versions

| Version | Support |
| --- | --- |
| Latest released V1.x | Security fixes and coordinated releases |
| Previous V1.x release | Security fixes when a supported upgrade path exists |
| Development branches | Best effort; not guaranteed for production |
| Unreleased or end-of-life versions | No security support |

## Response and disclosure

The maintainers aim to acknowledge a report within 3 business days and provide an initial triage within 10 business days. The remediation timeline depends on severity, exploitability and the availability of a safe fix.

Confirmed vulnerabilities should be coordinated with the reporter before public disclosure. Security advisories should describe affected versions, impact, mitigations, fixed versions and any required configuration or storage-file action. Researchers may be credited unless they request anonymity.

## Security expectations for contributions

Contributions must preserve:

- client-side encryption and the absence of plaintext in the API and file store;
- the no-database V1 architecture;
- restrictive permissions outside the web root;
- atomic file writes and exclusive locking for state transitions;
- the prohibition on logging keys, passphrases, deletion tokens and complete URLs;
- the required TDD, dependency review and security-test workflow.
