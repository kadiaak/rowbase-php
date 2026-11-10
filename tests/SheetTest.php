<?php

declare(strict_types=1);

namespace Rowbase\Tests;

use PHPUnit\Framework\TestCase;
use Rowbase\Client;
use Rowbase\Query;
use Rowbase\Record;
use Rowbase\Sheet;
use Rowbase\Tests\Support\FakeTransport;

final class SheetTest extends TestCase
{
    private FakeTransport $transport;

    private Sheet $sheet;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $client = new Client('rowb_test', [
            'transport' => $this->transport,
            'requests_per_second' => 0.0,
        ]);
        $this->sheet = $client->sheet('contacts', 'sheet-1');
    }

    public function testListSendsAuthenticatedRequest(): void
    {
        $this->transport->push(['records' => [
            ['id' => 'uuid-1', 'createdTime' => '2026-07-27T00:06:46.000Z', 'fields' => ['Website' => 'example.com']],
        ]]);

        $page = $this->sheet->list();

        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame('https://rowbase.co/api/v1/tables/contacts/sheet-1/records', $request->url);
        self::assertSame('Bearer rowb_test', $request->headers['Authorization']);

        self::assertCount(1, $page);
        self::assertSame('uuid-1', $page->first()?->id);
        self::assertSame('example.com', $page->first()?->get('Website'));
        self::assertSame('2026-07-27', $page->first()?->createdTime?->format('Y-m-d'));
        self::assertFalse($page->hasMore());
    }

    public function testAllFollowsPagination(): void
    {
        $this->transport
            ->push(['records' => [['id' => 'a', 'fields' => []]], 'offset' => '100'])
            ->push(['records' => [['id' => 'b', 'fields' => []]], 'offset' => '200'])
            ->push(['records' => [['id' => 'c', 'fields' => []]]]);

        $ids = \array_map(
            static fn (Record $r): string => $r->id,
            \iterator_to_array($this->sheet->all(), false),
        );

        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertSame(3, $this->transport->count());
        self::assertStringContainsString('offset=100', $this->transport->requests[1]->url);
        self::assertStringContainsString('offset=200', $this->transport->requests[2]->url);
    }

    public function testAllStopsAtMaxRecords(): void
    {
        $this->transport->push([
            'records' => [['id' => 'a', 'fields' => []], ['id' => 'b', 'fields' => []]],
            'offset' => '100',
        ]);

        $records = \iterator_to_array($this->sheet->all(Query::make()->maxRecords(1)), false);

        self::assertCount(1, $records);
        self::assertSame(1, $this->transport->count(), 'La page suivante ne doit pas être demandée.');
    }

    public function testPaginationStopsOnRepeatedOffset(): void
    {
        $this->transport
            ->push(['records' => [['id' => 'a', 'fields' => []]], 'offset' => '100'])
            ->push(['records' => [['id' => 'b', 'fields' => []]], 'offset' => '100']);

        $records = \iterator_to_array($this->sheet->all(), false);

        self::assertCount(2, $records);
        self::assertSame(2, $this->transport->count());
    }

    public function testCreateSendsSingleFieldsPayload(): void
    {
        $this->transport->push(['records' => [['id' => 'uuid-1', 'fields' => ['ID' => 'abc']]]]);

        $record = $this->sheet->create(['ID' => 'abc']);

        self::assertSame('POST', $this->transport->lastRequest()->method);
        self::assertSame(['fields' => ['ID' => 'abc']], $this->transport->bodyOf(0));
        self::assertSame('uuid-1', $record->id);
    }

    public function testCreateAcceptsBareRecordResponse(): void
    {
        $this->transport->push(['id' => 'uuid-9', 'fields' => ['ID' => 'abc']]);

        self::assertSame('uuid-9', $this->sheet->create(['ID' => 'abc'])->id);
    }

    public function testCreateManyChunksBatchesOfTen(): void
    {
        $rows = [];
        for ($i = 0; $i < 23; ++$i) {
            $rows[] = ['ID' => (string) $i];
        }

        $this->transport
            ->push(['records' => \array_fill(0, 10, ['id' => 'x', 'fields' => []])])
            ->push(['records' => \array_fill(0, 10, ['id' => 'x', 'fields' => []])])
            ->push(['records' => \array_fill(0, 3, ['id' => 'x', 'fields' => []])]);

        $created = $this->sheet->createMany($rows);

        self::assertCount(23, $created);
        self::assertSame(3, $this->transport->count());
        self::assertCount(10, $this->transport->bodyOf(0)['records']);
        self::assertCount(3, $this->transport->bodyOf(2)['records']);
        self::assertSame(['fields' => ['ID' => '20']], $this->transport->bodyOf(2)['records'][0]);
    }

    public function testUpdateUsesPatchWithRecordsArray(): void
    {
        $this->transport->push(['records' => [['id' => 'uuid-1', 'fields' => ['Website' => 'example.com']]]]);

        $record = $this->sheet->update('uuid-1', ['Website' => 'example.com']);

        self::assertSame('PATCH', $this->transport->lastRequest()->method);
        self::assertSame(
            ['records' => [['id' => 'uuid-1', 'fields' => ['Website' => 'example.com']]]],
            $this->transport->bodyOf(0),
        );
        self::assertSame('example.com', $record->get('Website'));
    }

    public function testUpdateManyAcceptsIdKeyedMapAndRecordObjects(): void
    {
        $this->transport
            ->push(['records' => [['id' => 'uuid-1', 'fields' => []]]])
            ->push(['records' => [['id' => 'uuid-2', 'fields' => []]]]);

        $this->sheet->updateMany(['uuid-1' => ['Website' => 'a.com']]);
        self::assertSame(
            ['records' => [['id' => 'uuid-1', 'fields' => ['Website' => 'a.com']]]],
            $this->transport->bodyOf(0),
        );

        $existing = new Record('uuid-2', ['Website' => 'b.com']);
        $this->sheet->updateMany([$existing->with(['Website' => 'c.com'])]);
        self::assertSame(
            ['records' => [['id' => 'uuid-2', 'fields' => ['Website' => 'c.com']]]],
            $this->transport->bodyOf(1),
        );
    }

    public function testReplaceUsesPut(): void
    {
        $this->transport->push(['records' => [['id' => 'uuid-1', 'fields' => []]]]);

        $this->sheet->replace('uuid-1', ['Website' => 'example.com']);

        self::assertSame('PUT', $this->transport->lastRequest()->method);
    }

    public function testUpdateWithoutIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sheet->updateMany([['fields' => ['Website' => 'x']]]);
    }

    public function testDeleteManyChunksAndUsesQueryString(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = 'uuid-' . $i;
        }

        $this->transport
            ->push(['records' => \array_map(
                static fn (string $id): array => ['id' => $id, 'deleted' => true],
                \array_slice($ids, 0, 10),
            )])
            ->push(['records' => \array_map(
                static fn (string $id): array => ['id' => $id, 'deleted' => true],
                \array_slice($ids, 10),
            )]);

        $deleted = $this->sheet->deleteMany($ids);

        self::assertSame($ids, $deleted);
        self::assertSame(2, $this->transport->count());
        self::assertSame('DELETE', $this->transport->requests[0]->method);
        self::assertNull($this->transport->requests[0]->body);

        $url = \rawurldecode($this->transport->requests[0]->url);
        self::assertStringContainsString('records[]=uuid-0', $url);
        self::assertStringContainsString('records[]=uuid-9', $url);
        self::assertStringNotContainsString('uuid-10', $url);
    }

    public function testDeleteReturnsTrueWhenConfirmed(): void
    {
        $this->transport->push(['records' => [['id' => 'uuid-1', 'deleted' => true]]]);

        self::assertTrue($this->sheet->delete('uuid-1'));
    }

    public function testFindEncodesRecordId(): void
    {
        $this->transport->push(['id' => 'uuid-1', 'fields' => ['ID' => 'abc']]);

        $record = $this->sheet->find('uuid-1');

        self::assertSame(
            'https://rowbase.co/api/v1/tables/contacts/sheet-1/records/uuid-1',
            $this->transport->lastRequest()->url,
        );
        self::assertSame('abc', $record['ID']);
    }

    public function testFindByFiltersClientSide(): void
    {
        $this->transport->push(['records' => [
            ['id' => 'a', 'fields' => ['Email' => 'x@y.z']],
            ['id' => 'b', 'fields' => ['Email' => 'cible@y.z']],
        ]]);

        self::assertSame('b', $this->sheet->findBy('Email', 'cible@y.z')?->id);
        self::assertSame(1, $this->transport->count());
    }

    public function testWherePushesTheServerFilter(): void
    {
        $this->transport->push(['records' => [
            ['id' => 'a', 'fields' => ['Email' => 'cible@y.z']],
        ]]);

        $ids = \array_map(
            static fn (Record $r): string => $r->id,
            \iterator_to_array($this->sheet->where('Email', 'cible@y.z'), false),
        );

        self::assertSame(['a'], $ids);
        self::assertStringContainsString(
            'filter[field]=Email',
            \rawurldecode($this->transport->lastRequest()->url),
        );
    }

    public function testExistsSendsServerFilterAndConfirmsTheMatch(): void
    {
        $this->transport->push(['records' => [
            ['id' => 'a', 'fields' => ['Email' => 'cible@y.z']],
        ]]);

        self::assertTrue($this->sheet->exists('Email', 'cible@y.z'));
        self::assertSame(1, $this->transport->count());

        $url = \rawurldecode($this->transport->lastRequest()->url);
        self::assertStringContainsString('filter[field]=Email', $url);
        self::assertStringContainsString('filter[value]=cible@y.z', $url);
        self::assertStringContainsString('pageSize=1', $url);
    }

    public function testExistsIsFalseOnAnEmptyResult(): void
    {
        $this->transport->push(['records' => []]);

        self::assertFalse($this->sheet->exists('Email', 'nope@y.z'));
    }

    public function testExistsRefusesAFalsePositiveFromALegacyApi(): void
    {
        // Une API qui ignore le filtre renvoie une ligne quelconque : la
        // revérification de la valeur doit écarter ce faux positif.
        $this->transport->push(['records' => [
            ['id' => 'a', 'fields' => ['Email' => 'autre@y.z']],
        ]]);

        self::assertFalse($this->sheet->exists('Email', 'cible@y.z'));
    }

    public function testCountWalksEveryPage(): void
    {
        $this->transport
            ->push(['records' => \array_fill(0, 100, ['id' => 'x', 'fields' => []]), 'offset' => '100'])
            ->push(['records' => \array_fill(0, 7, ['id' => 'x', 'fields' => []])]);

        self::assertSame(107, $this->sheet->count());
    }

    public function testSlugsAreUrlEncoded(): void
    {
        $this->transport->push(['records' => []]);

        $client = new Client('rowb_test', ['transport' => $this->transport, 'requests_per_second' => 0.0]);
        $client->sheet('ma table', 'feuille 1')->list();

        self::assertSame(
            'https://rowbase.co/api/v1/tables/ma%20table/feuille%201/records',
            $this->transport->lastRequest()->url,
        );
    }
}
