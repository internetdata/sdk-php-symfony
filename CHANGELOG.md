# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff.

## 1.0.5 - 2026-11-22

### Fixes

- Require internetdata/internetdata 2.15.0, which reads is_bot and the bot row ([`97e8258`](https://github.com/internetdata/sdk-php-symfony/commit/97e8258d869dc0513dbd8c761ba5ff7d33231547))
- README: a condition on isBot, which works on every plan ([`453cc44`](https://github.com/internetdata/sdk-php-symfony/commit/453cc44076b94a37c4ed5ff1ba152d9101e3c146))

## 1.0.4 - 2026-11-20

### Fixes

- Require internetdata 2.14.0: a paid domain answer's hosting, home page and linking domains ([`186cba7`](https://github.com/internetdata/sdk-php-symfony/commit/186cba75d1638cd08bbf76922673424e762f30dd))

## 1.0.3 - 2026-11-20

### Fixes

- Require internetdata 2.13.3: the spec re-pinned to 2026.11.20 ([`3555700`](https://github.com/internetdata/sdk-php-symfony/commit/355570071402bd0f363dc0aa03a1a917c4560642))

## 1.0.2 - 2026-11-19

### Fixes

- Require internetdata 2.13.2: a reduced answer is cached a minute at most ([`987f5d4`](https://github.com/internetdata/sdk-php-symfony/commit/987f5d482ed6728203fc5a63243075e2a80b2ff4))

## 1.0.1 - 2026-11-19

### Fixes

- Require internetdata 2.13.1: cap_reached on a paid answer past its overage cap ([`948c22b`](https://github.com/internetdata/sdk-php-symfony/commit/948c22b3718d069f1734fdfd6d1000f8c0b05dd0))

## 1.0.0 - 2026-11-14

### Features

- Attach the visitor's InternetData record to every request, read with `InternetDataListener::lookup($request)`: the location, the network and the company behind the address, and the anonymity and hosting flags your plan serves ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Refuse a request with a `block_condition` in `config/packages/internetdata.yaml`, written in the shape of a result, by country, network type, anonymity or any other member, with 403 before the router runs ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Choose where the client address comes from: `getClientIp()` by default, which honours `framework.trusted_proxies`, or `client_ip_header` for a header your edge writes ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Keep answers in `cache.app` for an hour, so a returning visitor costs nothing under PHP-FPM; `cache_pool` names another pool or turns it off ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Leave a path unlooked-up with `skip_paths`, each a regular expression matched against the path ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
- Fail open on any lookup failure unless `fail_closed` is set, with a 2.5 s bound and no retry by default ([`da73ad6`](https://github.com/internetdata/sdk-php-symfony/commit/da73ad6bc594c41066a57dce80747f012e94ac03))
