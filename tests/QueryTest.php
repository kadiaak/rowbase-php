<?php

declare(strict_types=1);

namespace Rowbase\Tests;

use PHPUnit\Framework\TestCase;
use Rowbase\Http\QueryString;
use Rowbase\Query;

final class QueryTest extends TestCase
{
    public function testBuildsRowbaseQueryFormat(): void
    {
        $query = Query::make()
            ->pageSize(50)
            ->fields('ID', 'Email')
            ->sort('Date', 'desc')
            ->sort('ID')
            ->returnFieldsByFieldId();

        $string = \rawurldecode(QueryString::build($query->toParams()));

        self::assertSame(
            'pageSize=50&fields[]=ID&fields[]=Email&sort[0][field]=Date&sort[0][direction]=desc'
            . '&sort[1][field]=ID&sort[1][direction]=asc&returnFieldsByFieldId=true',
            $string,
        );
    }

    public function testEncodesSpecialCharacters(): void
    {
        $string = QueryString::build(Query::make()->fields('Nom complet')->toParams());

        self::assertSame('fields%5B%5D=Nom%20complet', $string);
    }

    public function testDeleteQueryUsesRecordsArray(): void
    {
        self::assertSame(
            'records[]=a&records[]=b',
            \rawurldecode(QueryString::build(['records' => ['a', 'b']])),
        );
    }

    public function testIsImmutable(): void
    {
        $base = Query::make()->pageSize(10);
        $derived = $base->pageSize(20)->fields('ID');

        self::assertSame(['pageSize' => 10], $base->toParams());
        self::assertSame(['pageSize' => 20, 'fields' => ['ID']], $derived->toParams());
    }

    public function testRejectsInvalidPageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Query::make()->pageSize(101);
    }

    public function testRejectsInvalidSortDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Query::make()->sort('Date', 'sideways');
    }

    public function testCoerceAcceptsPlainArray(): void
    {
        $query = Query::coerce([
            'page_size' => 25,
            'fields' => ['ID'],
            'sort' => [['field' => 'Date', 'direction' => 'desc']],
        ]);

        self::assertSame([
            'pageSize' => 25,
            'fields' => ['ID'],
            'sort' => [['field' => 'Date', 'direction' => 'desc']],
        ], $query->toParams());
    }

    public function testNullValuesAreOmitted(): void
    {
        self::assertSame('a=1', QueryString::build(['a' => 1, 'b' => null]));
    }

    public function testFilterSerializesFieldAndValue(): void
    {
        $string = \rawurldecode(QueryString::build(Query::make()->pageSize(1)->filter('Id', 'rec-123')->toParams()));

        self::assertSame('pageSize=1&filter[field]=Id&filter[value]=rec-123', $string);
    }

    public function testFilterReplacesThePreviousOne(): void
    {
        $query = Query::make()->filter('A', '1')->filter('B', '2');

        self::assertSame(['filter' => ['field' => 'B', 'value' => '2']], $query->toParams());
    }

    public function testCoerceAcceptsFilter(): void
    {
        $query = Query::coerce(['filter' => ['field' => 'Id', 'value' => 'abc']]);

        self::assertSame(['filter' => ['field' => 'Id', 'value' => 'abc']], $query->toParams());
    }
}
