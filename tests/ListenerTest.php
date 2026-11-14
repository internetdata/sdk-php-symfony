<?php

declare(strict_types=1);

namespace InternetData\Symfony\Tests;

use InternetData\Client;
use InternetData\Middleware\Core;
use InternetData\Middleware\Options as MwOptions;
use InternetData\Options;
use InternetData\Symfony\InternetDataBundle;
use InternetData\Symfony\InternetDataListener;
use InternetData\Symfony\Selectors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The listener, driven with real Symfony request events, plus the shared corpus.
 *
 * A request with no REMOTE_ADDR resolves to nothing, and one from 127.0.0.1 is a bogon
 * answered locally. Anything that needs a served answer therefore has to arrive
 * wearing a public address, through a selector.
 */
final class ListenerTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';

    /** An origin that refuses every connection, so an answer can only come from a cache. */
    private const REFUSED = 'http://127.0.0.1:9';

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $data['middleware'];
    }

    private static function toIdiom(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'toIdiom'], $value);
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = self::toIdiom($entry);
        }
        return $out;
    }

    /** @param object|array<string, mixed> $body */
    private static function serving(object|array $body, int $status = 200): Stub
    {
        $ip = is_object($body) ? $body->ip : ($body['ip'] ?? self::PUBLIC_IP);
        return new Stub(Stub::lookups([$ip => ['status' => $status, 'body' => $body]]));
    }

    private static function client(Stub $stub): Client
    {
        return new Client(new Options(cache: false, retries: 0, httpClient: $stub->client));
    }

    private static function request(array $headers = [], string $ip = '127.0.0.1'): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return new Request(server: $server);
    }

    private static function dispatch(InternetDataListener $listener, Request $request): ?Response
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->onKernelRequest($event);
        return $event->getResponse();
    }

    private static function listener(MwOptions $options, mixed $onBlocked = null): InternetDataListener
    {
        return new InternetDataListener(new Core($options, Selectors::default()), $onBlocked);
    }

    public function testItRunsBeforeTheRouter(): void
    {
        // Not a taste question: refusing after routing would cost a route match on
        // every blocked request, and any router-priority listener would see one.
        $events = InternetDataListener::getSubscribedEvents();
        self::assertSame(['onKernelRequest', 33], $events[KernelEvents::REQUEST]);
    }

    public function testEnrichesTheRequestAndLeavesTheDecisionToTheApp(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
        ));
        $request = self::request();
        self::assertNull(self::dispatch($listener, $request));

        $lookup = InternetDataListener::lookup($request);
        self::assertNotNull($lookup);
        self::assertSame('AT', $lookup->result?->location->country);
        self::assertSame(9009, $lookup->result?->asn->asn);
        self::assertSame(self::PUBLIC_IP, $lookup->ip);
    }

    public function testBlocksWhenTheConditionMatches(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['location' => ['country' => ['CU', 'IR', 'AT']]],
        ));
        $response = self::dispatch($listener, self::request());
        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('{"error":"access denied"}', $response?->getContent());
    }

    public function testOnBlockedReplacesTheRefusal(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $listener = self::listener(
            new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r): string => self::PUBLIC_IP,
                blockCondition: ['location' => ['country' => 'AT']],
            ),
            fn (Request $r, $lookup): Response => new JsonResponse(
                ['why' => $lookup->result->location->country],
                451
            ),
        );
        $response = self::dispatch($listener, self::request());
        self::assertSame(451, $response?->getStatusCode());
        self::assertSame('{"why":"AT"}', $response?->getContent());
    }

    public function testASubRequestIsNotClassifiedAgain(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
        ));
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $event = new RequestEvent($kernel, self::request(), HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelRequest($event);

        // Every forward() and ESI fragment is a sub-request for the SAME visitor, so
        // classifying one would multiply the cost of a single page view.
        self::assertSame([], $stub->calls);
    }

    public function testAFailingLookupLetsTheVisitorThrough(): void
    {
        $failing = self::client(self::serving(['ip' => self::PUBLIC_IP, 'error' => 'boom'], 500));
        $listener = self::listener(new MwOptions(
            client: $failing,
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['location' => ['country' => 'AT']],
        ));
        $request = self::request();
        self::assertNull(self::dispatch($listener, $request));
        self::assertNotNull(InternetDataListener::lookup($request)?->error);
    }

    public function testAForgedXForwardedForIsIgnoredByDefault(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $listener = self::listener(new MwOptions(client: self::client($stub)));
        $request = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::dispatch($listener, $request);

        self::assertSame('127.0.0.1', InternetDataListener::lookup($request)?->ip);
        self::assertSame([], $stub->calls, 'a bogon is answered locally, so nothing was asked');

        $explicit = self::serving(Stub::answer(self::PUBLIC_IP));
        $viaSelector = self::listener(new MwOptions(
            client: self::client($explicit),
            ipSelector: Selectors::xff(),
        ));
        $forged = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::dispatch($viaSelector, $forged);
        self::assertSame(self::PUBLIC_IP, InternetDataListener::lookup($forged)?->ip);
    }

    public function testCorpusConditions(): void
    {
        // Decoded with objects too, so a detail served as `{}` is not sent as `[]`.
        $wire = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        )->middleware->conditions;
        foreach (self::corpus()['conditions'] as $i => $case) {
            $why = "{$case['name']}: {$case['why']}";
            $ip = $case['bogon'] ?? $case['body']['ip'];
            $stub = self::serving(isset($case['body']) ? $wire[$i]->body : ['ip' => $ip]);
            $warnings = [];
            $listener = self::listener(new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r) => $ip,
                blockCondition: self::toIdiom($case['condition']),
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ));
            $response = self::dispatch($listener, self::request());
            self::assertSame($case['expect']['blocked'] ? 403 : null, $response?->getStatusCode(), $why);

            $reported = array_values(array_filter(
                $warnings,
                static fn (string $m): bool => str_contains($m, 'does not include')
            ));
            self::assertCount($case['expect']['missing'] === [] ? 0 : 1, $reported, $why);
        }
    }

    /**
     * The bundle's wiring, compiled for real.
     *
     * A YAML key that silently produces no selector, or one the container cannot
     * instantiate, looks identical to a working config until the first request.
     */
    public function testTheBundleCompilesAndWiresTheConfiguredSelector(): void
    {
        foreach ([null, 'CF-Connecting-IP'] as $header) {
            $builder = new ContainerBuilder();
            $bundle = new InternetDataBundle();
            $config = ['api_key' => 'k', 'client_ip_header' => $header];
            $instanceof = [];
            $bundle->loadExtension($config, new ContainerConfigurator(
                $builder,
                new PhpFileLoader($builder, new FileLocator()),
                $instanceof,
                __DIR__,
                'internetdata'
            ), $builder);
            $builder->compile();

            $listener = $builder->get('internetdata.listener');
            self::assertInstanceOf(InternetDataListener::class, $listener);
        }
    }

    /**
     * A condition as `config/packages/internetdata.yaml` gives it, through the bundle's
     * own config tree and container. Every other test builds the core by hand, so a key
     * the bundle dropped on its way to the core would pass them all.
     */
    public function testAConfiguredConditionRefusesTheRequest(): void
    {
        $builder = new ContainerBuilder();
        // What the kernel sets before it loads any bundle's configuration.
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new InternetDataBundle())->getContainerExtension()->load([[
            'block_condition' => ['location' => ['country' => ['CU', 'IR', 'AT']]],
            'client_ip_header' => 'CF-Connecting-IP',
        ]], $builder);
        // The one thing YAML cannot name is the client answering, which is the stub here.
        $builder->register('test.client', Client::class)->setSynthetic(true)->setPublic(true);
        $builder->getDefinition('internetdata.listener')->getArgument(0)->getArgument(0)
            ->replaceArgument(0, new Reference('test.client'));
        $builder->compile();
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $builder->set('test.client', self::client($stub));

        $listener = $builder->get('internetdata.listener');
        self::assertInstanceOf(InternetDataListener::class, $listener);
        $response = self::dispatch($listener, self::request(['CF-Connecting-IP' => self::PUBLIC_IP]));

        self::assertSame(403, $response?->getStatusCode());
        self::assertCount(1, $stub->calls);
    }

    /**
     * `skip_paths` as `config/packages/internetdata.yaml` gives it: a matching path is
     * never looked up and carries no answer, and any other path still is.
     */
    public function testAConfiguredSkipPathIsNeverLookedUp(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new InternetDataBundle())->getContainerExtension()->load([[
            'client_ip_header' => 'CF-Connecting-IP',
            'skip_paths' => ['^/healthz', '^/assets/'],
        ]], $builder);
        $builder->register('test.client', Client::class)->setSynthetic(true)->setPublic(true);
        $builder->getDefinition('internetdata.listener')->getArgument(0)->getArgument(0)
            ->replaceArgument(0, new Reference('test.client'));
        $builder->compile();
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $builder->set('test.client', self::client($stub));
        $listener = $builder->get('internetdata.listener');
        self::assertInstanceOf(InternetDataListener::class, $listener);

        foreach (['/healthz', '/healthz/db', '/assets/app.css'] as $path) {
            $request = Request::create($path, server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => self::PUBLIC_IP]);
            self::assertNull(self::dispatch($listener, $request));
            self::assertNull(InternetDataListener::lookup($request));
        }
        self::assertCount(0, $stub->calls);

        $request = Request::create('/checkout', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => self::PUBLIC_IP]);
        self::dispatch($listener, $request);
        self::assertSame('AT', InternetDataListener::lookup($request)?->result?->location->country);
        self::assertCount(1, $stub->calls);
    }

    /**
     * Under PHP-FPM the container, and the client in it, is built for every request,
     * so only the framework's pool carries an answer from one request to the next.
     * Another client sharing the pool writes the answer, and the bundle's own client
     * reaches only an origin that refuses it: it can answer from the pool alone.
     */
    public function testTheBundleKeepsAnswersInTheFrameworksPool(): void
    {
        $pool = new ArrayAdapter();
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        (new Client(new Options(
            apiKey: 'k',
            baseUrl: self::REFUSED,
            cacheStore: new Psr16Cache($pool),
            httpClient: $stub->client,
        )))->lookup(self::PUBLIC_IP);
        $config = ['api_key' => 'k', 'base_url' => self::REFUSED, 'client_ip_header' => 'CF-Connecting-IP'];

        foreach ([[], ['cache_pool' => 'cache.app']] as $pooled) {
            $request = self::request(['CF-Connecting-IP' => self::PUBLIC_IP]);
            self::dispatch(self::compiled([...$config, ...$pooled], $pool), $request);
            self::assertSame('AT', InternetDataListener::lookup($request)?->result?->location->country);
        }
        self::assertCount(1, $stub->calls);

        $request = self::request(['CF-Connecting-IP' => self::PUBLIC_IP]);
        self::dispatch(self::compiled([...$config, 'cache_pool' => false], $pool), $request);
        self::assertNull(InternetDataListener::lookup($request)?->result);
        self::assertNotNull(InternetDataListener::lookup($request)?->error);
    }

    public function testANamedPoolThatDoesNotExistIsRefused(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        self::compiled(['cache_pool' => 'app.missing'], new ArrayAdapter());
    }

    /** @param array<string, mixed> $config */
    private static function compiled(array $config, ArrayAdapter $pool): InternetDataListener
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new InternetDataBundle())->getContainerExtension()->load([$config], $builder);
        $builder->register('cache.app', ArrayAdapter::class)->setSynthetic(true)->setPublic(true);
        $builder->compile();
        $builder->set('cache.app', $pool);
        $listener = $builder->get('internetdata.listener');
        self::assertInstanceOf(InternetDataListener::class, $listener);
        return $listener;
    }

    public function testASkipPathThatIsNotARegularExpressionIsRefused(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        (new InternetDataBundle())->getContainerExtension()->load([['skip_paths' => ['^/(unclosed']]], $builder);
    }

    public function testCorpusRefusesAConditionThatConstrainsNothing(): void
    {
        foreach (self::corpus()['invalidConditions'] as $case) {
            $refused = false;
            try {
                self::listener(new MwOptions(blockCondition: self::toIdiom($case['condition'])));
            } catch (\InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'constrains nothing');
            }
            self::assertTrue($refused, "{$case['name']}: {$case['why']}");
        }
    }
}
