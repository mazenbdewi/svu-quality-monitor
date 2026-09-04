# Extensible Monitoring Engine

Each monitored service has a `check_type` and an encrypted `check_config`. Existing services default to `http`, so no existing URL needs to be recreated after migration.

## Supported checks

- **HTTP / HTTPS:** URL, expected status, optional response keyword, timeout, and response-time measurement. HTTPS uses the normal HTTP TLS connection.
- **API:** GET or POST, expected status, optional headers and request body, plus a simple JSON path existence or equality assertion.
- **DNS:** hostname and A, AAAA, CNAME, MX, or TXT record type. The result stores at most 20 resolved values.
- **SSL certificate:** hostname and optional port (443 by default). The result stores issuer, subject, validity dates, expiry, and days remaining.
- **TCP:** hostname/IP, port, and timeout. Success only means that the TCP connection was established.

## Result semantics

- **Healthy:** a functional HTTP/API check is below `warning_response_ms`.
- **Warning:** a functional HTTP/API check is at or above `warning_response_ms` but below `critical_response_ms`; a valid SSL certificate with 30 days or less remaining is also a warning.
- **Critical:** a functional HTTP/API check is at or above `critical_response_ms`. It is a performance condition, not downtime.
- **Down:** a functional check fails, for example DNS failure, TCP connection failure, TLS failure, expired certificate, HTTP status mismatch, content mismatch, or JSON assertion failure.

Only functional failures are passed to the existing incident detector. Performance warning/critical checks and near-expiry certificates do not open outage incidents or create downtime. HTTP/API response times remain available to existing control charts; DNS, SSL, and TCP response metrics are not added to those charts automatically.

## Secrets and stored metadata

`check_config` uses Laravel's `encrypted:array` cast. API headers and tokens are encrypted at rest, omitted from check-result metadata, logs, and exports. Store only the required header values and avoid putting secrets in service names, URLs, or notes.

Check-result metadata is intentionally bounded: no response body, cookies, Authorization header, or large payload is stored. Errors use stable categories such as `timeout`, `dns_failure`, `connection_refused`, `http_status_mismatch`, `keyword_missing`, `json_expectation_failed`, `tls_failure`, and `certificate_expired`.
