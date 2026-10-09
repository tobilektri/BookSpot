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

    public function testRearrangingCompartmentsMovesTheirBooks(): void
    {
        $user = $this->createUser();
        $shelves = new ShelfService($this->db);
        $books = new BookService($this->db, $shelves);
        // Import guessed: column L top-down L1..L3, column M with M1, M2
        $shelf = $shelves->create(['name' => 'Wohnzimmer', 'columns' => [
            ['label' => 'L', 'rows' => ['L1', 'L2', 'L3']],
            ['label' => 'M', 'rows' => ['M1', 'M2']],
        ]]);
        $place = fn (string $title, int $col, int $row) => $books->create(['title' => $title, 'shelf_id' => $shelf['id'], 'shelf_col' => $col, 'shelf_row' => $row], $user['id'])['id'];
        $inL1 = $place('In L1', 1, 1);
        $inL3 = $place('In L3', 1, 3);
        $inM1 = $place('In M1', 2, 1);
        $inM2 = $place('In M2', 2, 2);

        // Reality: L is numbered bottom-up, M1 actually belongs to a new right column, M2 is removed.
        $updated = $shelves->update($shelf['id'], ['columns' => [
            ['label' => 'L', 'rows' => [
                ['name' => 'L3', 'from_col' => 1, 'from_row' => 3],
                ['name' => 'L2', 'from_col' => 1, 'from_row' => 2],
                ['name' => 'L1', 'from_col' => 1, 'from_row' => 1],
            ]],
            ['label' => 'R', 'rows' => [
                ['name' => 'Neu', 'from_col' => null, 'from_row' => null],
                ['name' => 'M1', 'from_col' => 2, 'from_row' => 1],
            ]],
        ]]);
        self::assertSame(1, $updated['unassigned_books'], 'M2 was removed');
        self::assertSame(['L3', 'L2', 'L1'], $updated['columns'][0]['rows']);

        $books = new BookService($this->db, $shelves); // fresh instance: shelf names are cached per request
        $location = fn (int $id) => $books->get($id, $user['id'])['location'];
        self::assertSame('L1', $location($inL1)['compartment']);
        self::assertSame([1, 3], [$books->get($inL1, $user['id'])['shelf_col'], $books->get($inL1, $user['id'])['shelf_row']]);
        self::assertSame('L3', $location($inL3)['compartment']);
        self::assertSame(['shelf' => 'Wohnzimmer', 'column' => 'R', 'compartment' => 'M1'], $location($inM1));
        self::assertNull($location($inM2));

        // Index index still resolves names to the new positions (used by the CSV import).
        $index = $shelves->compartmentIndex();
        self::assertSame([1, 3], [$index['l1']['shelf_col'], $index['l1']['shelf_row']]);
        self::assertSame([2, 2], [$index['m1']['shelf_col'], $index['m1']['shelf_row']]);
    }

    public function testPlainNamesKeepPositionsByIndex(): void
    {
        self::assertNull(ShelfService::extractMoves([['label' => 'A', 'rows' => ['A1', 'A2']]]));
        self::assertSame(['1:2' => [1, 1]], ShelfService::extractMoves([['label' => 'A', 'rows' => [['name' => 'A2', 'from_col' => 1, 'from_row' => 2], ['name' => 'X', 'from_col' => null]]]]));
    }
}
