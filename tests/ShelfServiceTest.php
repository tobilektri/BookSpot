<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\BookService;
use BookSpot\ShelfService;

final class ShelfServiceTest extends TestCase
{
    public function testCompactSpecFormatIsExpanded(): void
    {
        $columns = ShelfService::normalizeColumns([4, 2, 5]);
        self::assertCount(3, $columns);
        self::assertSame(['A1', 'A2', 'A3', 'A4'], $columns[0]['rows']);
        self::assertSame(['B1', 'B2'], $columns[1]['rows']);
        self::assertCount(5, $columns[2]['rows']);
    }

    public function testLayoutFromLocationCodes(): void
    {
        $layout = ShelfService::layoutFromCodes(['R3', 'L8', 'M4', 'L2', 'R9', 'r1', 'Cara']);
        self::assertSame(['L', 'M', 'R'], array_column($layout, 'label'));
        self::assertCount(8, $layout[0]['rows']);
        self::assertSame('L8', $layout[0]['rows'][7]);
        self::assertCount(4, $layout[1]['rows']);
        self::assertCount(9, $layout[2]['rows']);
    }

    public function testCreateListAndCompartmentIndex(): void
    {
        $service = new ShelfService($this->db);
        $shelf = $service->create(['name' => 'Wohnzimmer', 'columns' => [
            ['label' => 'Links', 'rows' => ['L1', 'L2']],
            ['label' => 'Rechts', 'rows' => 3],
        ]]);
        self::assertSame(5, $shelf['compartment_count']);
        self::assertSame(['Rechts1', 'Rechts2', 'Rechts3'], $shelf['columns'][1]['rows']);

        $index = $service->compartmentIndex();
        self::assertSame(['shelf_id' => $shelf['id'], 'shelf_col' => 1, 'shelf_row' => 2], array_slice($index['l2'], 0, 3));
        self::assertArrayHasKey('wohnzimmer/rechts3', $index);
    }

    public function testDuplicateCompartmentNamesAreRejected(): void
    {
        $this->expectException(ApiException::class);
        (new ShelfService($this->db))->create(['name' => 'X', 'columns' => [['label' => 'A', 'rows' => ['A1', 'a1']]]]);
    }

    public function testShrinkingAShelfUnassignsBooks(): void
    {
        $user = $this->createUser();
        $shelves = new ShelfService($this->db);
        $books = new BookService($this->db, $shelves);
        $shelf = $shelves->create(['name' => 'Flur', 'columns' => [3, 2]]);
        $kept = $books->create(['title' => 'Bleibt', 'shelf_id' => $shelf['id'], 'shelf_col' => 1, 'shelf_row' => 1], $user['id']);
        $lost = $books->create(['title' => 'Fällt raus', 'shelf_id' => $shelf['id'], 'shelf_col' => 2, 'shelf_row' => 2], $user['id']);

        $updated = $shelves->update($shelf['id'], ['columns' => [3]]);
        self::assertSame(1, $updated['unassigned_books']);
        self::assertSame($shelf['id'], $books->get($kept['id'], $user['id'])['shelf_id']);
        self::assertNull($books->get($lost['id'], $user['id'])['shelf_id']);

        $detail = $shelves->get($shelf['id'], $user['id']);
        self::assertSame([1, 0, 0], $detail['columns'][0]['counts']);
    }

    public function testInvalidPositionIsRejected(): void
    {
        $user = $this->createUser();
        $shelves = new ShelfService($this->db);
        $shelf = $shelves->create(['name' => 'Klein', 'columns' => [1]]);
        try {
            (new BookService($this->db, $shelves))->create(['title' => 'X', 'shelf_id' => $shelf['id'], 'shelf_col' => 1, 'shelf_row' => 5], $user['id']);
            self::fail('Expected validation error');
        } catch (ApiException $e) {
            self::assertSame('compartment_not_found', $e->details['errors']['shelf_row']);
        }
    }
}
