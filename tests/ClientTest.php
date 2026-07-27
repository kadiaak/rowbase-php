<?php

declare(strict_types=1);

namespace Rowbase\Tests;

use PHPUnit\Framework\TestCase;
use Rowbase\Client;
use Rowbase\Exception\AuthenticationException;
use Rowbase\Exception\AuthorizationException;
use Rowbase\Exception\NotFoundException;
use Rowbase\Exception\RateLimitException;
use Rowbase\Exception\ServerException;
use Rowbase\Exception\TransportException;
use Rowbase\Exception\ValidationException;
use Rowbase\Http\RateLimiter;
use Rowbase\Tests\Support\FakeTransport;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;

    /** @var list<float> */
    private array $sleeps = [];

    private function client(array $options = []): Client
    {
        $this->transport = $this->transport ?? new FakeTransport();

        $client = new Client('rowb_test', \array_merge([
            'transport' => $this->transport,
            'requests_per_second' => 0.0,
        ], $options));

        $client->setSleeper(function (float $seconds): void {
            $this->sleeps[] = $seconds;
        });

        return $client;
    }

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
    }

    public function testParsesTablesSchema(): void
    {
        $this->transport->push(['tables' => [[
            'id' => '1',
            'slug' => 'contacts',
            'name' => 'Contacts',
            'sheets' => [[
                'id' => '5',
                'slug' => 'sheet-1',
                'name' => 'Sheet 1',
                'fields' => [
                    ['id' => 'fld20', 'name' => 'ID', 'type' => 'text'],
                    ['id' => 'fld23', 'name' => 'Email', 'type' => 'email'],
                ],
            ]],
        ]]]);

        $tables = $this->client()->tables();

        self::assertCount(1, $tables);
        self::assertSame('contacts', $tables[0]->slug);
        self::assertSame(['ID', 'Email'], $tables[0]->sheet('sheet-1')?->fieldNames());
        self::assertSame('email', $tables[0]->sheet('sheet-1')?->field('Email')?->type);
    }

    public function testTablesAreCachedUntilRefresh(): void
    {
        $this->transport->push(['tables' => []])->push(['tables' => []]);

        $client = $this->client();
        $client->tables();
        $client->tables();
        self::assertSame(1, $this->transport->count());

        $client->tables(refresh: true);
        self::assertSame(2, $this->transport->count());
    }

    /**
     * @dataProvider errorProvider
     */
    public function testMapsApiErrorsToTypedExceptions(int $status, string $type, string $expected): void
    {
        $this->transport->push(['error' => ['type' => $type, 'message' => 'Détail.']], $status);

        $this->expectException($expected);
        $this->expectExceptionMessage('[' . $type . '] Détail.');

        $this->client(['max_retries' => 0])->sheet('contacts', 'sheet-1')->list();
    }

    public static function errorProvider(): iterable
    {
        yield 'auth manquante' => [401, 'AUTHENTICATION_REQUIRED', AuthenticationException::class];
        yield 'jeton invalide' => [401, 'INVALID_API_KEY', AuthenticationException::class];
        yield 'hors périmètre' => [403, 'NOT_AUTHORIZED', AuthorizationException::class];
        yield 'table absente' => [404, 'TABLE_NOT_FOUND', NotFoundException::class];
        yield 'ligne absente' => [404, 'ROW_NOT_FOUND', NotFoundException::class];
        yield 'champ inconnu' => [422, 'UNKNOWN_FIELD_NAME', ValidationException::class];
        yield 'trop de lignes' => [422, 'TOO_MANY_RECORDS', ValidationException::class];
        yield 'quota atteint' => [429, 'RATE_LIMIT_REACHED', RateLimitException::class];
    }

    public function testExceptionExposesTypeAndStatus(): void
    {
        $this->transport->push(['error' => ['type' => 'UNKNOWN_FIELD_NAME', 'message' => 'Unknown field "Nope".']], 422);

        try {
            $this->client(['max_retries' => 0])->sheet('contacts', 'sheet-1')->create(['Nope' => 1]);
            self::fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            self::assertSame('UNKNOWN_FIELD_NAME', $e->type);
            self::assertSame(422, $e->statusCode);
            self::assertSame('Unknown field "Nope".', $e->body['error']['message'] ?? null);
        }
    }

    public function testRetriesRateLimitThenSucceeds(): void
    {
        $this->transport
            ->push(['error' => ['type' => 'RATE_LIMIT_REACHED', 'message' => 'Trop d\'appels.']], 429, ['Retry-After' => '2'])
            ->push(['records' => []]);

        $page = $this->client()->sheet('contacts', 'sheet-1')->list();

        self::assertCount(0, $page);
        self::assertSame(2, $this->transport->count());
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(2.0, $this->sleeps[0], 'Retry-After doit être respecté.');
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->transport->push(['error' => ['type' => 'RATE_LIMIT_REACHED', 'message' => 'Stop.']], 429);
        }

        $this->expectException(RateLimitException::class);

        $this->client(['max_retries' => 2])->sheet('contacts', 'sheet-1')->list();
    }

    public function testRetriesServerErrorsOnReadsOnly(): void
    {
        $this->transport->push(['error' => ['type' => 'INTERNAL', 'message' => 'Oups.']], 503)->push(['records' => []]);
        $this->client()->sheet('contacts', 'sheet-1')->list();
        self::assertSame(2, $this->transport->count());

        $writeTransport = new FakeTransport();
        $writeTransport->push(['error' => ['type' => 'INTERNAL', 'message' => 'Oups.']], 503);
        $this->transport = $writeTransport;

        $this->expectException(ServerException::class);
        $this->client()->sheet('contacts', 'sheet-1')->create(['ID' => 'abc']);
    }

    public function testRetriesNetworkFailures(): void
    {
        $this->transport->pushFailure('connexion coupée')->push(['records' => []]);

        $this->client()->sheet('contacts', 'sheet-1')->list();

        self::assertSame(2, $this->transport->count());
    }

    public function testSurfacesNetworkFailureWhenRetriesExhausted(): void
    {
        $this->transport->pushFailure()->pushFailure();

        $this->expectException(TransportException::class);

        $this->client(['max_retries' => 1])->sheet('contacts', 'sheet-1')->list();
    }

    public function testCustomBaseUrlAndUserAgent(): void
    {
        $this->transport->push(['tables' => []]);

        $this->client(['base_url' => 'https://exemple.test/api/v1/', 'user_agent' => 'mon-app/2.0'])->tables();

        $request = $this->transport->lastRequest();
        self::assertSame('https://exemple.test/api/v1/tables', $request->url);
        self::assertSame('mon-app/2.0', $request->headers['User-Agent']);
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('   ');
    }

    public function testRateLimiterThrottlesToConfiguredRate(): void
    {
        $waits = [];
        $limiter = new RateLimiter(5.0, sleeper: static function (float $s) use (&$waits): void {
            $waits[] = $s;
        });

        // Le seau démarre plein : cinq appels immédiats, puis attente.
        for ($i = 0; $i < 5; ++$i) {
            $limiter->acquire();
        }
        self::assertSame([], $waits);

        $limiter->acquire();
        self::assertCount(1, $waits);
        self::assertGreaterThan(0.0, $waits[0]);
        self::assertLessThanOrEqual(0.2, $waits[0]);
    }
}
