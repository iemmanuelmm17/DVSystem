# DVSystem: NetSapiens Web Responder

A PHP server-side IVR for the Dee Voch Phone API. This first version handles caller lookup, SMS-code verification, shared-number account selection, subscription review and confirmed auto-renew changes. The caller can transfer to the office; new signups, card/payment handling, renewals, addresses, email verification and other free-text changes are **not implemented** and require the office. Caller ID is not treated as verification. Calls with more than eight accounts or subscriptions are transferred rather than truncated. No card data is collected.

## Windows deployment

1. Install PHP 8.5 with the cURL extension and configure IIS (or another HTTPS web server) with document root `D:/www/dvapp`. Copy `public/index.php` there. Copy `private/app.php` and `private/.env.example` to `D:/private/dvapp`, and rename the latter to `.env`. Alternatively set `DVAPP_PRIVATE_DIR` in the web server environment to the private directory. Never serve the private directory.
2. Fill in `DV_API_KEY`, a separate long random `WEBRESPONDER_KEY`, the public HTTPS `WEBRESPONDER_URL` ending in `/index.php`, and the dial-plan `OFFICE_DESTINATION`. Leave the office destination empty to end safely instead of forwarding. Ensure the PHP worker can read the private files and create/write the configured state and log directories; deny other users access. HTTPS and outbound access to the Dee Voch API are required.
3. Point a NetSapiens To-Web dial translation at `https://your-host/index.php?key=YOUR_WEBRESPONDER_KEY`. Configure POST if desired; the endpoint accepts GET or form POST. Route through a system user, as NetSapiens recommends for forwarding. NetSapiens v44.1+ with a working Voice Services TTS integration is required for `<Response>`, `<Say>`, and enhanced `<Gather>`. Test the destination and prompts on your PBX.
4. The Web Responder secret is necessarily present in callback URLs. Configure IIS, proxies, PBX and monitoring to **suppress or redact query strings** and request bodies; restrict access by trusted PBX source IP where possible. Do not expose the Dee Voch API key. OTP digits and session tokens must not be logged or recorded. Disable call recording during verification; the app cannot control PBX recordings.
5. Confirm the test account and one-time codes with Dee Voch. Test incoming calls, timeouts, invalid codes, shared phone numbers, office transfer and auto-renew rules before going live. Set the live base URL only when supplied.

State is stored server-side under random 256-bit IDs, with a 30-minute inactivity limit. Normal exit and transfer close the Dee Voch session; unexpected caller hangups cannot be detected by this implementation, so the API session expires on its own. Abandoned local state files older than 24 hours are opportunistically removed at the start of calls; configure a scheduled cleanup if calls are infrequent. Protect state files as sensitive data. Do not run multiple servers without shared, locking-capable state storage.

`CALLER_ID_DIGIT_STYLE`, `TTS_VOICE`, `TTS_LANGUAGE`, `INPUT_TIMEOUT_SECONDS`, `INTERDIGIT_TIMEOUT_SECONDS`, `MAX_ATTEMPTS`, `LOG_LEVEL` and `LOG_RETENTION_DAYS` are configured in the private `.env`. Logging intentionally records only fixed, non-sensitive events even at debug level, not raw requests or XML. No message is sent by this application; Dee Voch delivers verification codes. Dev SMS is disabled and card charges decline.

## Open deployment decisions

- Supply the office dial-plan destination and confirm the system-user routing and TTS voice/language.
- Confirm whether caller ID can be withheld or changed: this version transfers rather than accepting an alternate number, and supports SMS only. Landline/email verification transfers to the office.
- A full signup, renewal, address and card-management IVR requires a defined secure payment collection solution, free-text workflow, and explicit caller-consent prompts. Avoid collecting card data or OTPs in recordings, transcripts, and logs; assess PCI obligations before adding payments.
- Confirm how the PBX behaves on a Gather timeout and whether it propagates the action URL query parameters. Callbacks should preserve `key` and `state` and supply `Digits`. Confirm TLS, inbound firewall restrictions, and log redaction end-to-end.

Run `php tests/smoke.php` for basic helper and timeout checks. A real PBX and Dee Voch test account are needed for integration testing.
