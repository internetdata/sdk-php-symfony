<?php

declare(strict_types=1);

namespace InternetData\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use InternetData\Client;
use InternetData\Options;
use InternetData\Symfony\InternetDataBundle;
use InternetData\Symfony\InternetDataListener;

/**
 * `allow_bots` as `config/packages/internetdata.yaml` gives it: through the bundle's
 * config tree and compiled container, then a request handled by a real HttpKernel
 * from a public peer read by the default selector.
 */
final class AllowBotsTest extends TestCase
{
    private const PEER = '66.249.66.1';

    private const OTHER = '66.249.66.2';

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    private Stub $stub;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $config
     * @param ?CacheInterface $pool The store the client keeps its answers in, where the framework's pool is one
     */
    private function kernel(?string $verdict, array $config, ?CacheInterface $pool = null): HttpKernel
    {
        $members = ['is_hosting' => true];
        if ($verdict !== null) {
            $members['user_agent'] = ['agent' => 'Googlebot', 'kind' => 'bot', 'verdict' => $verdict];
        }
        $routes = [];
        foreach ([self::PEER, self::OTHER] as $ip) {
            $routes[$ip] = ['status' => 200, 'body' => Stub::answer($ip, $members)];
        }
        $this->stub = new Stub(Stub::lookups($routes));

        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new InternetDataBundle())->getContainerExtension()->load([[...$config, 'cache_pool' => false]], $builder);
        // YAML cannot name the client answering, the stub here, nor a warning sink.
        $builder->register('test.client', Client::class)->setSynthetic(true)->setPublic(true);
        $builder->register('test.on_warn', \Closure::class)->setSynthetic(true)->setPublic(true);
        $options = $builder->getDefinition('internetdata.listener')->getArgument(0)->getArgument(0);
        $options->replaceArgument(0, new Reference('test.client'));
        $options->replaceArgument(10, new Reference('test.on_warn'));
        $builder->compile();
        $builder->set('test.client', new Client($pool === null
            ? new Options(cache: false, retries: 0, httpClient: $this->stub->client)
            : new Options(retries: 0, cacheStore: $pool, httpClient: $this->stub->client)));
        $builder->set('test.on_warn', function (string $m): void {
            $this->warnings[] = $m;
        });
        $listener = $builder->get('internetdata.listener');
        self::assertInstanceOf(InternetDataListener::class, $listener);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $controllers = new class implements ControllerResolverInterface {
            public function getController(Request $request): callable|false
            {
                return static fn (): Response => new Response('ok');
            }
        };
        return new HttpKernel($dispatcher, $controllers, new RequestStack(), new ArgumentResolver());
    }

    private static function visit(HttpKernel $kernel, string $agent = self::GOOGLEBOT, string $peer = self::PEER): int
    {
        return $kernel->handle(Request::create('/', server: ['REMOTE_ADDR' => $peer, 'HTTP_USER_AGENT' => $agent]))
            ->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function sent(): array
    {
        self::assertSame(['/' . self::PEER], $this->stub->calls, 'the public peer, looked up once');
        parse_str($this->stub->requests[0]->getUri()->getQuery(), $query);
        return $query;
    }

    public function testTheRequestsOwnUserAgentReachesTheOrigin(): void
    {
        self::assertSame(200, self::visit($this->kernel('verified', ['allow_bots' => true])));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    /**
     * Under PHP-FPM each request builds the kernel and the listener in it, so the four agents an
     * address may be looked up with have to be remembered in the client's store.
     */
    public function testTheBoundOnAnAddressesAgentsHoldsAcrossRequestsInTheClientsStore(): void
    {
        $pool = new Psr16Cache(new ArrayAdapter());
        $agent = static fn (int $n): string => "Mozilla/5.0 (compatible; Examplebot/{$n}.0)";
        // One request on a kernel built anew: its status, and the queries that reached the origin.
        $visit = function (int $n, string $peer = self::PEER) use ($pool, $agent): array {
            $kernel = $this->kernel('verified', ['allow_bots' => true, 'block_condition' => ['isHosting' => true]], $pool);
            $status = self::visit($kernel, $agent($n), $peer);
            return [$status, array_map(static function ($request): array {
                parse_str($request->getUri()->getQuery(), $query);
                return $query;
            }, $this->stub->requests)];
        };

        $statuses = [];
        for ($n = 1; $n <= 5; $n++) {
            [$status, $sent] = $visit($n);
            $statuses[] = $status;
            self::assertSame($n <= 4 ? [['user_agent' => $agent($n)]] : [[]], $sent, "agent {$n}");
        }
        self::assertSame([200, 200, 200, 200, 403], $statuses, 'the fifth agent is past the bound, and none is sent');

        [$status, $sent] = $visit(1);
        self::assertSame(200, $status, 'a known agent is still sent');
        self::assertNotContains([], $sent, 'and no bare lookup stands in for it');

        [$status, $sent] = $visit(5, self::OTHER);
        self::assertSame(200, $status, 'another address has its own four');
        self::assertSame([['user_agent' => $agent(5)]], $sent);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function verdicts(): array
    {
        return ['verified' => ['verified', 200], 'spoofed' => ['spoofed', 403], 'not_bot' => ['not_bot', 403]];
    }

    #[DataProvider('verdicts')]
    public function testAVerifiedCrawlerPassesAConditionItMeetsAndOthersAreRefused(string $verdict, int $status): void
    {
        $kernel = $this->kernel($verdict, ['allow_bots' => true, 'block_condition' => ['isHosting' => true]]);

        self::assertSame($status, self::visit($kernel));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testOffByDefaultNoUserAgentIsSentAndTheConditionHolds(): void
    {
        self::assertSame(403, self::visit($this->kernel('verified', ['block_condition' => ['isHosting' => true]])));
        self::assertSame([], $this->sent());
    }

    public function testAnEmptyUserAgentSendsNone(): void
    {
        self::assertSame(200, self::visit($this->kernel(null, ['allow_bots' => true]), ''));
        self::assertSame([], $this->sent());
    }

    // Only an agent naming a crawler is sent. `Request::create` sends `Symfony` as the
    // User-Agent unless told otherwise, and a browser's agent names no crawler either.
    public function testAnAgentNamingNoCrawlerIsNotSent(): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => self::PEER]);
        self::assertSame(200, $this->kernel(null, ['allow_bots' => true])->handle($request)->getStatusCode());
        self::assertSame([], $this->sent());

        self::assertSame(200, self::visit($this->kernel(null, ['allow_bots' => true]), self::CHROME));
        self::assertSame([], $this->sent());
    }

    public function testAnAbsentUserAgentSendsNone(): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => self::PEER]);
        $request->headers->remove('User-Agent');
        self::assertSame(200, $this->kernel(null, ['allow_bots' => true])->handle($request)->getStatusCode());
        self::assertSame([], $this->sent());
    }

    public function testAConditionOnTheVerdictRefusesAForgedCrawler(): void
    {
        $kernel = $this->kernel('spoofed', [
            'allow_bots' => true,
            'block_condition' => ['userAgent' => ['verdict' => 'spoofed']],
        ]);

        self::assertSame(403, self::visit($kernel));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testAnAnswerWithoutAVerdictWarnsOnce(): void
    {
        $kernel = $this->kernel(null, ['allow_bots' => true]);

        self::assertSame(200, self::visit($kernel));
        self::assertSame(200, self::visit($kernel));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('user_agent', $this->warnings[0]);
    }
}
