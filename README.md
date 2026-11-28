# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData Symfony Bundle

[![Packagist](https://img.shields.io/packagist/v/internetdata/symfony.svg)](https://packagist.org/packages/internetdata/symfony)
[![license](https://img.shields.io/packagist/l/internetdata/symfony.svg)](LICENSE)

The official [Symfony](https://symfony.com) bundle for the [InternetData](https://internetdata.io) API.

It looks up the visitor behind each request, where they are, which network they come from and whether it's a VPN, a proxy, Tor or a hosting server, and hands the answer to your code. Blocking is opt-in.

## Getting Started

```bash
composer require internetdata/symfony
```

Requires PHP 8.2 or newer and Symfony 6.4, 7 or 8.

Without an API key a lookup is on Unlimited, the free plan: the country, the network and the company behind the address, Tor, relay and anycast space, with no daily or monthly cap. Every lookup your app makes comes from your server's one address, though, so a busy site will see bursts refused. A paid plan answers every field, VPN, the proxy flags, hosting, CDN, bots, carrier and satellite space, the service flags, the network's type and the city among them; create a key in the [console](https://app.internetdata.io). A free account tries every field.

Register the bundle and configure it:

```php
// config/bundles.php
InternetData\Symfony\InternetDataBundle::class => ["all" => true],
```

```yaml
# config/packages/internetdata.yaml
internetdata:
    api_key: "%env(default::INTERNETDATA_API_KEY)%"
```

`default::` reads an unset `INTERNETDATA_API_KEY` as no key, so the bundle runs keyless until you set one; a plain `%env(INTERNETDATA_API_KEY)%` makes every request a 500 while the variable is unset.

```php
use InternetData\Symfony\InternetDataListener;

#[Route("/")]
public function index(Request $request): Response
{
    $result = InternetDataListener::lookup($request)?->result;
    return new Response("Hello, visitor from " . ($result?->location->countryName ?? "somewhere"));
}
```

The listener runs just before Symfony's router, so a blocked request costs no route match, and sub-requests are not looked up again: every `forward()` and ESI fragment is the same visitor.

By default nothing is blocked. Every request gets an answer and your own code decides what it means: a currency to show, a sign-up to review, a page to keep from some countries.

## Blocking

Set a `block_condition` and a matching request is answered with `403` and never reaches your code.

```yaml
# config/packages/internetdata.yaml
internetdata:
    api_key: "%env(default::INTERNETDATA_API_KEY)%"
    block_condition:
        location:
            country: [CU, IR, KP]
```

A condition is written in the shape of a `Result`, and only the members you name are considered, so it can refuse on anything in the answer. The country works on every plan, and so does the first of these:

```yaml
block_condition: { isAnycast: true }                                       # anycast space
block_condition: { asn: { type: hosting } }                                # the network's type
block_condition: { isBot: true }                                           # crawlers, monitors and scanners
block_condition: [{ isSatellite: true }, { isCarrier: true }]              # satellite and mobile carrier space
block_condition: [{ isHosting: true }, { isCdn: true }]                    # traffic from servers, not people
block_condition: [{ isVpn: true }, { isTor: true }, { isResproxy: true }]  # anonymity: a list is OR
block_condition: { isVpn: true, vpn: { provider: nordvpn } }               # one provider
block_condition: { resproxy: { hits: { gte: 5 } } }                        # a numeric threshold
```

Values are matched by equality, strings without regard to case. A LIST means any-of. A map of `gte`/`gt`/`lte`/`lt` compares numbers, and every bound you give must hold, so two of them are a range. A member the answer holds as a list, such as `web`'s rows or `pubproxy.protocols`, matches when any one element does, and a nested condition must hold within that one element. Members set to `false` or `null` are ignored, so a condition states the signals you act on; one that constrains nothing would match every request, and is refused when the middleware is built rather than silently blocking all your traffic.

## Let a verified crawler through

Set `allow_bots` and the bundle sends each request's own `User-Agent` with its lookup when it names a crawler, so the answer says whose bot it names and whether this address bears that out. A request whose verdict is `verified`, a crawler its operator vouches for here, is never refused, whatever `block_condition` matches; its answer is still attached:

```yaml
# config/packages/internetdata.yaml
internetdata:
    api_key: "%env(default::INTERNETDATA_API_KEY)%"
    allow_bots: true
    block_condition: { isHosting: true }
```

With it on, a condition can also refuse a forged crawler: `{ userAgent: { verdict: spoofed } }`. Any other agent, or none, is not sent, since its verdict could only be `not_bot`. One address is looked up with at most four crawler agents an hour, remembered in the framework's cache pool (`cache_pool`) so the bound holds under PHP-FPM too (with it set to `false`, only within one process): past those its lookup sends none, and a request passes as a crawler only where the answer's `bot.verified` is true. Only an answer with every field carries the verdict, and `bot` is a paid member too, so a keyless app gets no bot pass and nothing is let through by it; the bundle warns once when an agent was sent and the answer had no verdict. Off by default, since on, answers are cached per address and user agent rather than per address.

## Where the client address comes from

This is the setting that decides whether any of the above works, and it is the one thing only you can get right.

By default the listener uses `$request->getClientIp()`, which honours Symfony's trusted-proxy configuration. That is the right fix behind a load balancer: set `framework.trusted_proxies` to the proxies actually in front of you and Symfony walks the chain for you. Without it, and behind one, every visitor looks like the load balancer, and that is the address that gets looked up: every visitor gets its country and network, and if it is a hosting address, a hosting rule blocks everyone. A load balancer in your own network usually has a private address, which the listener answers locally without a lookup, as an address with no country, and logs a warning.

For an edge that writes the address into its own header, name the header:

```yaml
# config/packages/internetdata.yaml
internetdata:
    client_ip_header: CF-Connecting-IP
```

A callable cannot live in YAML, so a header NAME is the config key. Anything more exotic, a chain depth or your own logic, means replacing the `internetdata.listener` service, which is Symfony's own answer rather than a config key we would have to keep inventing.

`\InternetData\Symfony\Selectors::xff()` reads `X-Forwarded-For` directly, and `Selectors::xff(1)` counts one trusted hop from the right. Be aware that the left-most entry is whatever the caller sent, because proxies append to that header, so it is only trustworthy when an edge you control overwrites it. Both are for a replaced listener service; prefer `framework.trusted_proxies`.

If the address resolves to a private one, the middleware says so once each time the application boots, which under PHP-FPM is every request. That is expected locally and is the signal to fix your configuration anywhere else.

## When a lookup fails

The request is let through, and the reason is on the answer's `error`. Our outage should not become yours, so a network failure, a rate limit or a rejected key all fail open. Set `fail_closed` to block instead. Private addresses are answered locally and never fail, so this will not lock you out in development.

## Cost and latency

Answers are cached for an hour in your app's `cache.app` pool, keyed by a hash of your API key and the visitor's address, so a returning visitor costs nothing even under PHP-FPM, where the application is built afresh for every request. Private addresses never leave the process. A lookup is one request to our API, bounded at 2.5 seconds by default and not retried: on a request path, failing open quickly beats holding a visitor while we try again.

Name another pool with `cache_pool`, or set it to `false` to keep answers in the client alone, which under PHP-FPM lasts one request:

```yaml
internetdata:
    cache_pool: cache.internetdata
```

Skip the paths that never need an answer, such as a health check or static assets. Each is a regular expression matched against the path, the way `access_control`'s `path` is, and a skipped request carries no lookup:

```yaml
internetdata:
    skip_paths: ["^/healthz", "^/assets/"]
```

Beyond a few million distinct visitors a day, stop calling the API per request: look addresses up locally in [our databases](https://internetdata.io/databases) instead.

## Absent is not false

Unlimited, with a key or without one, leaves out `isVpn`, `isHosting`, `isCdn`, the four proxy flags, `isBot`, `isCarrier`, `isSatellite` and the service flags, the detail behind them, and the paid members inside `location`, `asn`, `company` and `abuse`, such as `location->city` and `asn->type`. A property your plan does not include is `null`, which means "not in your plan" rather than "checked, and no". The eight flags `isBot`, `isCarrier`, `isSatellite`, `isSsh`, `isSmtp`, `isWeb`, `isDnsResolver` and `isBittorrent` are plain booleans and read `false` there, and `asn->type` is a plain string and reads `''`, though a condition naming one is still warned about.

```php
$lookup->result->isVpn ?? false   // when you only want the flag
```

A `block_condition` naming a member your plan does not serve, `location.city` or `asn.type` on Unlimited included, can never match, so the middleware warns, once each time the application boots, instead of failing silently. Set `on_missing_field` to `throw` to make it an error.

## Other Libraries

There are official InternetData client libraries available for many languages including PHP, Python, Go, Java, and Ruby. See our GitHub at https://github.com/internetdata for more.

## About InternetData

Geolocation, anonymity, ownership, network, service and domain data for any IP address, AS or domain, by API or as databases you download.

[<picture><source media="(prefers-color-scheme: dark)" srcset="https://s3.internetdata.io/internetdata-public/brand/logo-dark.svg"><img src="https://s3.internetdata.io/internetdata-public/brand/logo.svg" alt="InternetData" height="40"/></picture>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
