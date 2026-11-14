# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff.

## 1.0.0 - 2026-11-14

### Features

- Attach the visitor's InternetData record to every request, read with `InternetDataListener::lookup($request)`: the location, the network and the company behind the address, and the anonymity and hosting flags your plan serves ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Refuse a request with a `block_condition` in `config/packages/internetdata.yaml`, written in the shape of a result, by country, network type, anonymity or any other member, with 403 before the router runs ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Choose where the client address comes from: `getClientIp()` by default, which honours `framework.trusted_proxies`, or `client_ip_header` for a header your edge writes ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Keep answers in `cache.app` for an hour, so a returning visitor costs nothing under PHP-FPM; `cache_pool` names another pool or turns it off ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Leave a path unlooked-up with `skip_paths`, each a regular expression matched against the path ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Fail open on any lookup failure unless `fail_closed` is set, with a 2.5 s bound and no retry by default ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
