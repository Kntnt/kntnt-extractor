# Extractions run as a background job, fetched through a one-time download link rather than streamed inline

An extraction request creates a detached background job, polled to completion, rather than running synchronously inside the REST request — the same create/poll shape used elsewhere for heavy WordPress background work. PHP's own execution-time limit, and the harder timeouts many managed hosts impose in front of PHP-FPM regardless of `php.ini`, make a single-request response unreliable once a selection is more than trivially small, and this plugin must work for anything from "one small table" to "the entire uploads directory."

The finished artifact (encrypted, per the plugin's existing exposure-minimisation posture) is fetched via a short-lived, single-use link rather than streamed back through the REST response body. Streaming multi-gigabyte responses through PHP was considered and rejected: it is far more sensitive to timeouts, memory limits, and proxy buffering than letting the web server serve a static file directly, and the exposure that buys back — never having the artifact sit web-reachable even briefly — is instead achieved by making the link single-use, deleted immediately after a verified download rather than left until a timer fires.

## Consequences

- A client must poll job status and then follow a returned download link, rather than receiving the artifact directly from the extraction call.
- The download link is consumed on first successful, verified fetch — a second attempt with the same link fails by design.
