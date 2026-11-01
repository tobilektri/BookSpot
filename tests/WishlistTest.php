<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\BatchImporter;
use BookSpot\BookService;
use BookSpot\ExportService;
use BookSpot\Http\Kernel;
use BookSpot\Import\BookImporter;
use BookSpot\Import\CsvReader;
use BookSpot\Request;
use BookSpot\ShelfService;
use BookSpot\StatsService;
use BookSpot\WishlistService;

final class WishlistTest extends TestCase
{
    private BookService $books;
    private WishlistService $wishes;
    private array $anna;
    private array $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->createUser('anna');
        $this->ben = $this->createUser('ben');
        $this->books = new BookService($this->db, new ShelfService($this->db));
        $this->wishes = new WishlistService($this->db, $this->books);
    }

    private function wish(string $title, string $isbn, ?array $user = null): array
    {
        return $this->books->create(['title' => $title, 'isbn' => $isbn, 'wishlist' => true, 'entry_type' => 'library_loan', 'shelf_id' => 99], ($user ?? $this->anna)['id']);
    }

    private function notFound(callable $fn): void
    {
        try {
            $fn();
            self::fail('expected not found');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testWishedBooksStayOutOfTheCollection(): void
    {
        $book = $this->wish('Wunschbuch', '9783548283951');
        self::assertTrue($book['wishlist']);
        self::assertTrue($book['wished']);
        self::assertSame(['owned', null, null], [$book['entry_type'], $book['shelf_id'], $book['loan_due']], 'no type, place or loan');

        self::assertSame(0, $this->books->list([], $this->anna['id'])['total']);
        self::assertSame(0, (new StatsService($this->db))->forUser($this->anna['id'])['totals']['books']);
        self::assertSame(['Wunschbuch'], array_column($this->wishes->mine($this->anna['id'])['items'], 'title'));
    }

    public function testPrivateWishesAreInvisibleToOthers(): void
    {
        $book = $this->wish('Geheim', '9783548283951');
        $this->notFound(fn () => $this->books->get($book['id'], $this->ben['id']));
        self::assertNull($this->books->findByIsbn('9783548283951', $this->ben['id']), 'scanning it does not reveal the wish');
        self::assertSame([], $this->wishes->shared($this->ben['id']));

        $this->wishes->share($this->anna['id'], 'instance');
        $lists = $this->wishes->shared($this->ben['id']);
        self::assertSame('anna', $lists[0]['username']);
        self::assertSame(['Geheim'], array_column($lists[0]['items'], 'title'));
        self::assertSame('Geheim', $this->books->get($book['id'], $this->ben['id'])['title']);
        self::assertSame(0, $this->books->list([], $this->ben['id'])['total'], 'still not in the collection');
    }

    public function testPublicLink(): void
    {
        $book = $this->wish('Für alle', '9783548283951');
        self::assertNull($this->wishes->sharing($this->anna['id'])['token']);
        $sharing = $this->wishes->share($this->anna['id'], 'public');
        $token = (string) $sharing['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $public = $this->wishes->public($token);
        self::assertSame('anna', $public['username']);
        self::assertSame(['id', 'title', 'authors', 'publisher', 'published_year', 'isbn13', 'has_cover', 'cover_version'], array_keys($public['items'][0]), 'nothing personal');
        self::assertSame($book['id'], $this->wishes->publicBook($token, $book['id']));
        self::assertNull($this->wishes->publicBook($token, $book['id'] + 1));

        // Without a session, through the router.
        $response = Kernel::handle(new Request('GET', path: "/v1/public/wishlists/$token"));
        self::assertSame(200, $response->status);
        self::assertSame(404, Kernel::handle(new Request('GET', path: '/v1/public/wishlists/' . str_repeat('0', 32)))->status);

        self::assertSame($token, $this->wishes->share($this->anna['id'], 'public')['token'], 'link stays the same');
        $renewed = (string) $this->wishes->share($this->anna['id'], 'public', true)['token'];
        self::assertNotSame($token, $renewed);
        $this->notFound(fn () => $this->wishes->public($token));

        $this->wishes->share($this->anna['id'], 'private');
        $this->notFound(fn () => $this->wishes->public($renewed));
    }

    public function testBoughtOrBorrowedJoinsTheCollection(): void
    {
        $bought = $this->wish('Gekauft', '9783548283951');
        $borrowed = $this->wish('Geliehen', '9783404139873');
        $same = $this->wish('Gekauft', '9783548283951', $this->ben); // Ben wishes for the same book himself
        self::assertSame($bought['id'], $same['id']);
        $this->notFound(fn () => $this->wishes->add($this->ben['id'], $this->wish('Annas', '9783423760508')['id'])); // private: no access by id

        $book = $this->wishes->fulfil($this->anna['id'], $bought['id'], 'owned');
        self::assertSame([false, 'owned', App::today(), 'active'], [$book['wishlist'], $book['entry_type'], $book['acquired_at'], $book['status']]);
        self::assertSame([], $this->wishes->mine($this->ben['id'])['items'], 'everybody\'s wish is fulfilled');

        $loan = $this->wishes->fulfil($this->anna['id'], $borrowed['id'], 'library_loan');
        self::assertSame(['library_loan', App::today(), '2026-10-30'], [$loan['entry_type'], $loan['loan_start'], $loan['loan_due']]);
        self::assertSame(2, $this->books->list([], $this->anna['id'])['total']);
    }

    public function testAddingAWishedBookToTheCollectionTakesTheWishedEntry(): void
    {
        $wished = $this->wish('Gewünscht', '9783548283951');
        // Ben brings the book home and adds it normally – no "duplicate ISBN" because of Anna's private wish.
        $book = $this->books->create(['title' => 'Gewünscht', 'isbn' => '9783548283951', 'shelf_id' => null], $this->ben['id']);
        self::assertSame($wished['id'], $book['id']);
        self::assertFalse($book['wishlist']);
        self::assertSame([], $this->wishes->mine($this->anna['id'])['items']);

        $this->expectException(ApiException::class); // now it really exists
        $this->books->create(['title' => 'Nochmal', 'isbn' => '9783548283951'], $this->ben['id']);
    }

    public function testWishForABookOfTheCatalogueAndRemoving(): void
    {
        $loan = $this->books->create(['title' => 'Aus der Bücherei', 'isbn' => '9783548283951', 'entry_type' => 'library_loan', 'loan_due' => '2026-10-01'], $this->anna['id']);
        $this->books->update($loan['id'], ['status' => 'returned'], $this->anna['id']);
        // "Want to own it": wishing via the form finds the existing book.
        $again = $this->books->create(['title' => 'Aus der Bücherei', 'isbn' => '9783548283951', 'wishlist' => true], $this->anna['id']);
        self::assertSame($loan['id'], $again['id']);
        self::assertTrue($again['wished']);
        self::assertFalse($again['wishlist'], 'stays a book of the catalogue');

        $this->wishes->remove($this->anna['id'], $loan['id']);
        self::assertNotNull($this->db->value('SELECT id FROM books WHERE id = ?', [$loan['id']]), 'catalogue books are kept');

        $only = $this->wish('Nur gewünscht', '9783404139873');
        $this->wishes->remove($this->anna['id'], $only['id']);
        self::assertNull($this->db->value('SELECT id FROM books WHERE id = ?', [$only['id']]), 'nobody wishes for it any more');
    }

    public function testScanningAWishedBookIntoAShelfBuysIt(): void
    {
        $shelf = (new ShelfService($this->db))->create(['name' => 'Regal', 'columns' => [['label' => 'A', 'rows' => ['A1']]]]);
        $wished = $this->wish('Gewünscht', '9783548283951');
        $result = (new BatchImporter($this->db))->submit([['isbn' => '9783548283951']], ['shelf_id' => $shelf['id'], 'shelf_col' => 1, 'shelf_row' => 1], $this->ben['id']);
        self::assertSame('created', $result['items'][0]['status']);
        $book = $this->books->get($wished['id'], $this->ben['id']);
        self::assertSame([false, $shelf['id']], [$book['wishlist'], $book['shelf_id']]);
        self::assertSame([], $this->wishes->mine($this->anna['id'])['items']);
    }

    public function testOnlyThePeopleWhoWishForABookMayChangeIt(): void
    {
        $private = $this->wish('Annas Geheimnis', '9783548283951');
        $id = (int) $private['id'];
        $ben = $this->ben['id'];
        $attempts = [
            'update' => fn () => $this->books->update($id, ['title' => 'pwned'], $ben),
            'delete' => fn () => $this->books->delete($id, $ben),
            'personal' => fn () => (new \BookSpot\UserBookService($this->db))->upsert($ben, $id, ['reading_status' => 'read']),
            'read' => fn () => (new \BookSpot\ReadHistoryService($this->db))->add($ben, $id, ['finished_at' => '2026-01-01']),
            'fulfil' => fn () => $this->wishes->fulfil($ben, $id, 'owned'),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail("$name must be refused");
            } catch (ApiException $e) {
                self::assertSame(404, $e->getCode(), "$name: private wishes don't exist for others");
            }
        }
        self::assertSame(0, $this->books->move([$id], null, null, null), 'never moved onto a shelf');
        self::assertSame('Annas Geheimnis', $this->books->get($id, $this->anna['id'])['title']);

        // Shared with the instance: Ben may look and buy it as a gift, but not change it.
        $this->wishes->share($this->anna['id'], 'instance');
        foreach (['update', 'delete', 'personal', 'read'] as $name) {
            try {
                $attempts[$name]();
                self::fail("$name must be refused");
            } catch (ApiException $e) {
                self::assertSame(403, $e->getCode(), $name);
            }
        }
        self::assertArrayNotHasKey('created_by_name', $this->books->get($id, $ben), 'who wished for it stays private');
        self::assertSame('owned', $this->wishes->fulfil($ben, $id, 'owned')['entry_type'], 'gift bought');
    }

    public function testFulfilNeedsAWish(): void
    {
        $loan = $this->books->create(['title' => 'Annas Ausleihe', 'isbn' => '9783548283951', 'entry_type' => 'library_loan', 'loan_due' => '2026-11-30'], $this->anna['id']);
        $this->notFound(fn () => $this->wishes->fulfil($this->ben['id'], $loan['id'], 'owned'));
        self::assertSame('library_loan', $this->books->get($loan['id'], $this->anna['id'])['entry_type']);
    }

    public function testWishedBooksStayOffShelvesLoansAndHiddenIdsStayHidden(): void
    {
        $private = $this->wish('Annas Geheimnis', '9783548283951');
        $this->db->update('books', ['entry_type' => 'library_loan', 'status' => 'active', 'loan_due' => App::today()], ['id' => $private['id']]);
        self::assertSame([], $this->books->dueLoans($this->ben['id']));
        self::assertSame(0, (new StatsService($this->db))->forUser($this->ben['id'])['loans']['library_active']);

        $own = $this->books->create(['title' => 'Bens Buch', 'isbn' => '9783404139873'], $this->ben['id']);
        try {
            $this->books->update($own['id'], ['isbn' => '9783548283951'], $this->ben['id']);
            self::fail('duplicate');
        } catch (ApiException $e) {
            self::assertSame('duplicate_isbn', $e->getMessage());
            self::assertArrayNotHasKey('book_id', $e->details, 'no hint at the private wish');
        }
    }

    public function testDeletingAUserRemovesTheirOrphanedWishes(): void
    {
        $orphan = $this->wish('Nur Ben', '9783548283951', $this->ben);
        $shared = $this->wish('Beide', '9783404139873', $this->ben);
        $this->wish('Beide', '9783404139873');
        (new \BookSpot\Auth($this->db))->deleteUser($this->anna['id'], $this->ben['id']);
        self::assertNull($this->db->value('SELECT id FROM books WHERE id = ?', [$orphan['id']]));
        self::assertNotNull($this->db->value('SELECT id FROM books WHERE id = ?', [$shared['id']]), 'Anna still wishes for it');
    }

    public function testExportAndImportKeepTheWishlist(): void
    {
        $this->wish('Wunsch', '9783548283951');
        $this->books->create(['title' => 'Eigenes', 'isbn' => '9783404139873'], $this->anna['id']);
        $this->wish('Bens Wunsch', '9783423760508', $this->ben);

        $out = fopen('php://memory', 'w+');
        (new ExportService($this->db))->writeCsv($this->anna['id'], $out);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        self::assertStringContainsString('Wunschliste', $csv);
        self::assertStringNotContainsString('Bens Wunsch', $csv, 'other people\'s private wishes are not exported');

        $this->db->run('DELETE FROM wishes');
        $this->db->run('DELETE FROM books');
        (new BookImporter($this->db, $this->anna['id']))->import((new CsvReader())->parseString($csv));
        self::assertSame(['Wunsch'], array_column($this->wishes->mine($this->anna['id'])['items'], 'title'));
        self::assertSame(['Eigenes'], array_column($this->books->list([], $this->anna['id'])['items'], 'title'));
    }
}
