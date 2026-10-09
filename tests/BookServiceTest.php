<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\BookService;
use BookSpot\ReminderService;
use BookSpot\PushService;
use BookSpot\ShelfService;
use BookSpot\UserBookService;

final class BookServiceTest extends TestCase
{
    private BookService $books;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new BookService($this->db, new ShelfService($this->db));
    }

    public function testCreateFindAndDuplicateIsbn(): void
    {
        $user = $this->createUser();
        $book = $this->books->create(['isbn' => '3-404-13987-9', 'title' => '  Test   Buch ', 'authors' => 'A. Autor', 'pages' => '391'], $user['id']);
        self::assertSame('9783404139873', $book['isbn13']);
        self::assertSame('Test Buch', $book['title']);
        self::assertSame('unread', $book['personal']['reading_status']);

        self::assertSame($book['id'], $this->books->findByIsbn('9783404139873', $user['id'])['id']);
        self::assertNull($this->books->findByIsbn('9783548283951', $user['id']));

        try {
            $this->books->create(['isbn' => '9783404139873', 'title' => 'Dupe'], $user['id']);
            self::fail('duplicate accepted');
        } catch (ApiException $e) {
            self::assertSame(409, $e->getCode());
            self::assertSame($book['id'], $e->details['book_id']);
        }
    }

    public function testValidation(): void
    {
        $user = $this->createUser();
        try {
            $this->books->create(['title' => '', 'isbn' => '123', 'cover_url' => 'javascript:alert(1)', 'entry_type' => 'library_loan'], $user['id']);
            self::fail('invalid input accepted');
        } catch (ApiException $e) {
            self::assertSame(['isbn', 'title', 'cover_url', 'loan_due'], array_keys($e->details['errors']));
        }
    }

    public function testLibraryLoanAndListFilters(): void
    {
        $user = $this->createUser();
        $loan = $this->books->create(['title' => 'Leihbuch', 'entry_type' => 'library_loan', 'loan_due' => '12.10.2026', 'loan_from' => 'Stadtbibliothek'], $user['id']);
        self::assertSame('2026-10-12', $loan['loan_due']);
        self::assertSame(3, $loan['loan_days_left']);
        self::assertSame($user['id'], $loan['loan_user_id']);
        self::assertSame('2026-10-09', $loan['loan_start']);

        $this->books->create(['title' => 'Eigenes', 'authors' => 'Zed', 'genre' => 'Krimi'], $user['id']);
        self::assertSame(1, $this->books->list(['entry_type' => 'library_loan'], $user['id'])['total']);
        self::assertSame(1, $this->books->list(['q' => 'eigen'], $user['id'])['total']);
        self::assertSame(1, $this->books->list(['unplaced' => 1], $user['id'])['total']);
        self::assertSame('Leihbuch', $this->books->dueLoans($user['id'])[0]['title']);

        $bought = $this->books->update($loan['id'], ['entry_type' => 'owned'], $user['id']);
        self::assertNull($bought['loan_due']);
    }

    public function testLibraryLoanUsesDefaultLoanPeriod(): void
    {
        $user = $this->createUser();
        $auth = new \BookSpot\Auth($this->db);
        $profile = $auth->updateProfile($user['id'], ['library_name' => ' Stadtbücherei ', 'library_loan_days' => '21']);
        self::assertSame('Stadtbücherei', $profile['library_name']);
        self::assertSame(21, $profile['library_loan_days']);

        // Only the borrowing date: due date and library come from the defaults.
        $loan = $this->books->create(['title' => 'Leihbuch', 'entry_type' => 'library_loan', 'loan_start' => '01.10.2026'], $user['id']);
        self::assertSame('2026-10-22', $loan['loan_due']);
        self::assertSame('Stadtbücherei', $loan['loan_from']);

        // Without a borrowing date it starts today; an explicit due date or library wins.
        self::assertSame('2026-10-30', $this->books->create(['title' => 'B', 'entry_type' => 'library_loan'], $user['id'])['loan_due']);
        $own = $this->books->create(['title' => 'C', 'entry_type' => 'library_loan', 'loan_due' => '2026-11-15', 'loan_from' => 'Uni'], $user['id']);
        self::assertSame(['2026-11-15', 'Uni'], [$own['loan_due'], $own['loan_from']]);

        // Clearing the due date on an existing loan recalculates it from the borrowing date.
        self::assertSame('2026-10-22', $this->books->update($own['id'], ['loan_start' => '2026-10-01', 'loan_due' => ''], $user['id'])['loan_due']);

        try {
            $auth->updateProfile($user['id'], ['library_loan_days' => '0']);
            self::fail('invalid loan period accepted');
        } catch (ApiException $e) {
            self::assertSame(['library_loan_days' => 'invalid_loan_days'], $e->details['errors']);
        }
        $cleared = $auth->updateProfile($user['id'], ['library_name' => '', 'library_loan_days' => '']);
        self::assertSame([null, null], [$cleared['library_name'], $cleared['library_loan_days']]);
        try {
            $this->books->create(['title' => 'D', 'entry_type' => 'library_loan'], $user['id']);
            self::fail('library loan without due date accepted');
        } catch (ApiException $e) {
            self::assertSame(['loan_due'], array_keys($e->details['errors']));
        }
    }

    public function testPersonalDataIsIsolatedPerUser(): void
    {
        $anna = $this->createUser('anna');
        $ben = $this->createUser('ben');
        $book = $this->books->create(['title' => 'Geteilt', 'pages' => 200], $anna['id']);
        $personal = new UserBookService($this->db);

        $result = $personal->upsert($anna['id'], $book['id'], ['reading_status' => 'read', 'rating' => 5]);
        self::assertSame('2026-10-09', $result['personal']['finished_at']);
        self::assertSame(200, $result['personal']['pages_read']);

        self::assertSame('unread', $this->books->get($book['id'], $ben['id'])['personal']['reading_status']);
        self::assertSame([['username' => 'anna', 'reading_status' => 'read', 'rating' => 5]], $this->books->get($book['id'], $ben['id'])['readers']);
        self::assertSame(1, $this->books->list(['reading_status' => 'unread'], $ben['id'])['total']);
        self::assertSame(0, $this->books->list(['reading_status' => 'unread'], $anna['id'])['total']);

        $progress = $personal->upsert($ben['id'], $book['id'], ['pages_read' => 50]);
        self::assertSame('reading', $progress['personal']['reading_status']);
        self::assertSame('2026-10-09', $progress['personal']['started_at']);
    }

    public function testOfflineConflictKeepsNewerServerState(): void
    {
        $user = $this->createUser();
        $book = $this->books->create(['title' => 'Sync'], $user['id']);
        $personal = new UserBookService($this->db);
        $personal->upsert($user['id'], $book['id'], ['reading_status' => 'read']);

        $stale = $personal->upsert($user['id'], $book['id'], ['reading_status' => 'reading', 'client_updated_at' => '2026-10-09T08:00:00+02:00']);
        self::assertSame('conflict', $stale['status']);
        self::assertSame('read', $stale['personal']['reading_status']);

        $fresh = $personal->upsert($user['id'], $book['id'], ['rating' => 3, 'client_updated_at' => '2026-10-09T12:30:00+02:00']);
        self::assertSame('saved', $fresh['status']);
    }

    public function testMoveBooks(): void
    {
        $user = $this->createUser();
        $shelf = (new ShelfService($this->db))->create(['name' => 'R', 'columns' => [2, 2]]);
        $a = $this->books->create(['title' => 'A'], $user['id']);
        $b = $this->books->create(['title' => 'B'], $user['id']);
        self::assertSame(2, $this->books->move([$a['id'], $b['id']], $shelf['id'], 2, 1));
        self::assertSame(['shelf' => 'R', 'column' => 'B', 'compartment' => 'B1'], $this->books->get($a['id'], $user['id'])['location']);
        self::assertSame(2, $this->books->list(['shelf_id' => $shelf['id'], 'col' => 2, 'row' => 1], $user['id'])['total']);
    }

    public function testReminderMessages(): void
    {
        $de = ReminderService::message(['id' => 4, 'title' => 'Momo', 'loan_due' => '2026-10-12', 'loan_from' => 'Stabi'], 3, 'de');
        self::assertSame('📚 In 3 Tagen fällig', $de['title']);
        self::assertStringContainsString('12.10.2026', $de['body']);
        $en = ReminderService::message(['id' => 4, 'title' => 'Momo', 'loan_due' => '2026-10-10', 'loan_from' => null], 1, 'en');
        self::assertSame('📚 Due tomorrow', $en['title']);

        // Without push subscriptions the run finds the loan but sends nothing (and logs nothing).
        $user = $this->createUser();
        $this->books->create(['title' => 'Leih', 'entry_type' => 'library_loan', 'loan_due' => '2026-10-10'], $user['id']);
        $report = (new ReminderService($this->db, new PushService($this->db)))->run();
        self::assertSame(1, $report['checked']);
        self::assertSame(0, $report['sent']);
    }
}
