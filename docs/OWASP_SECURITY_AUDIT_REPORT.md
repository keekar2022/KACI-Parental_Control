# OWASP Security Audit Report — KACI Parental Control

**Date**: February 2026  
**Scope**: KACI Parental Control codebase (pfSense package)  
**Methodology**: Manual review against OWASP Top 10 2025, OWASP API Security Top 10, and OWASP Top 10 for GenAI.

---

## Scope and Methodology

- **Codebase**: PHP (pfSense package), shell scripts; no database (config is XML).
- **In scope**: All `.php` files under project root (`parental_control_api.php`, `parental_control_captive.php`, `parental_control_services.php`, `parental_control_profiles.php`, `parental_control_gaming.php`, `parental_control.inc`, etc.), `UNINSTALL.sh`, `parental_control_health.php`.
- **Frameworks**: OWASP Top 10 2025, OWASP API Security Top 10, OWASP Top 10 for GenAI (applicability noted).

---

## Summary

| Framework           | Applicable                        | Findings                                |
| ------------------- | --------------------------------- | --------------------------------------- |
| OWASP Top 10 2025   | Yes                               | 6 areas with issues; several strengths  |
| OWASP API Security  | Yes (`parental_control_api.php`)  | 4 areas with issues                     |
| OWASP GenAI         | No                                | No LLM/AI in codebase                   |

---

## 1. OWASP Top 10 2025

### A01: Broken Access Control

- **GUI**: Uses pfSense `guiconfig.inc`; `parental_control_status.php` notes CSRF protection via csrf-magic.php. Access controlled by pfSense auth.
- **API**: Single shared API key; any valid key can access any device/profile (no per-resource authorization). Acceptable for an admin-only API.
- **Captive portal** (`parental_control_captive.php`): Intentionally no auth (by design for block page).
- **Finding**: No critical BOLA/IDOR in GUI; API is single-tenant admin style. Document that API key = full admin access.

### A02: Security Misconfiguration

- **CORS**: `parental_control_api.php` — configurable allowlist via **API CORS Origins** (Settings). If set, only listed origins are allowed; otherwise `*` for backward compatibility. **Remediated.**
- **TLS verification**: `parental_control_services.php` — `CURLOPT_SSL_VERIFYPEER` set to `true` for curl requests. **Remediated.**

### A03: Software Supply Chain Failures

- No dependency manifest (e.g. Composer) in repo; PHP uses pfSense/system includes. No automated dependency scanning observed.
- **Recommendation**: If external PHP libs are added, introduce a lock file and periodic vulnerability scanning (e.g. `composer audit`, SBOM per project rules).

### A04: Cryptographic Failures

- **Override password**: `parental_control_captive.php` / `parental_control_blocked.php` — now verified via `pc_override_password_verify()`; stored value migrated to bcrypt hash in `parental_control_sync()`. **Remediated.**
- **API key**: Compared with `hash_equals()` (good); storage in config remains plaintext (pfSense pattern). Prefer hashed storage if feasible.

### A05: Injection

- **Shell**: `parental_control_services.php` line 394 uses `escapeshellarg()`. `UNINSTALL.sh` uses fixed string — no user input.
- **XSS**: Widespread `htmlspecialchars()` for output. Good.
- **SQL**: No SQL; config is XML. No SQL injection.
- **Finding**: Shell usage safe; XSS mitigated.

### A06: Insecure Design (Path Traversal)

- **Captive portal**: `parental_control_captive.php` — static file serving now uses `realpath()` and enforces docroot under `/usr/local/www`. **Remediated.**

### A07: Authentication Failures

- **API**: API key with `hash_equals()` (timing-safe). No MFA (acceptable for admin API).
- **Override password**: Now hashed and verified via `password_verify()` / legacy plaintext fallback. **Remediated.**

### A08: Software and Data Integrity Failures

- Not deeply assessed. Package build/signing in `.github/scripts/sign-package.sh`. No specific code-level finding.

### A09: Security Logging and Alerting Failures

- Structured logging via `pc_log()` in `parental_control.inc` with OpenTelemetry-style attributes. Security events (e.g. API block/unblock) logged with context.
- **Finding**: Logging in good shape; ensure no sensitive data (API key, passwords) in logs.

### A10: Mishandling Exceptional Conditions

- **User-facing error messages**: Catch blocks now log full detail server-side (e.g. `pc_log` / `error_log`) and show generic messages in `$input_errors[]` and health API `error` field. **Remediated.**

---

## 2. OWASP API Security Top 10

- **API1 (BOLA)**: Single API key grants access to all resources; no per-device/per-profile authorization. Acceptable for admin API; document clearly.
- **API2 (Authentication)**: API key + `hash_equals`; API key accepted **only via X-API-Key header** (query parameter removed to avoid leak in Referer, logs, browser history). **Remediated.**
- **API4 (Resource consumption)**: Rate limiting added (120 requests per 60 seconds per IP). **Remediated.**
- **API8 (Misconfiguration)**: CORS allowlist configurable; API key header-only. **Remediated.**

API endpoints require API key; no SQL; POST body (e.g. `reason`) is logged — consider capping length and sanitizing for log safety.

---

## 3. OWASP Top 10 for GenAI

- **Applicability**: No LLM, GenAI, or prompt/response handling in the codebase. References to "model" or "prompt" in docs are non-AI.
- **Conclusion**: OWASP GenAI list does not apply to current code. If AI features are added later, apply LLM01–LLM10 (e.g. prompt injection, output handling, PII in prompts).

---

## 4. Positive Practices Observed

- **API**: `hash_equals()` for key comparison; JSON responses with correct headers; routing and auth checked before logic.
- **Output encoding**: Consistent `htmlspecialchars()` for HTML output.
- **Shell**: User-controlled values passed to `exec()` use `escapeshellarg()`.
- **Input validation**: MAC validated with regex in profiles; numeric IDs validated with `is_numeric()`/`intval()`; IP validation with `filter_var(..., FILTER_VALIDATE_IP)` in services.
- **Logging**: Structured `pc_log()` with event/context; security events logged.

---

## 5. Remediation Summary

| Priority | Item | Status |
| -------- | ---- | ------ |
| High | Path traversal in `parental_control_captive.php` (realpath + docroot check) | Implemented |
| High | Stop exposing raw exception messages (A10) | Implemented |
| Medium | Harden override password (A04/A07) with password_hash/verify | Implemented |
| Medium | Enable TLS verification for curl in `parental_control_services.php` | Implemented |
| Medium | API key header-only; deprecate query param | Implemented |
| Low | CORS allowlist (configurable in Settings) | Implemented |
| Low | Rate limiting on API | Implemented |
| Low | Strict comparison for profile/schedule IDs in API | Implemented |

**Remaining recommendations**: API key hashed storage if feasible; cap/sanitize POST body (e.g. `reason`) in logs; apply OWASP GenAI if AI features are added.

---

## References

- [OWASP Top 10 2025](https://owasp.org/Top10/2025/)
- [OWASP API Security Top 10](https://owasp.org/API-Security/)
- [OWASP Top 10 for GenAI](https://owasp.org/www-project-top-10-for-large-language-model-applications/)
