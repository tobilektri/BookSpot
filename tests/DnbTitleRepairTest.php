<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\BookService;
use BookSpot\Config;
use BookSpot\DnbTitleRepair;
use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\ShelfService;

final class DnbTitleRepairTest extends TestCase
{
    private BookService $books;
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('METADATA_LOOKUP', 'true');
        $this->user = $this->createUser();
        $this->books = new BookService($this->db, new ShelfService($this->db));
    }

    protected function tearDown(): void
    {
        Config::set('METADATA_LOOKUP', 'false');
        parent::tearDown();
    }

    /** Book as an older version stored it (title written directly, like the old lookup did). */
    private function book(string $isbn, string $title): int
    {
        $id = (int) $this->books->create(['title' => 'x', 'isbn' => $isbn], $this->user['id'])['id'];
        $this->db->update('books', ['title' => $title, 'updated_at' => '2026-01-01 00:00:00'], ['id' => $id]);
        return $id;
    }

    private function title(int $id): string
    {
        return (string) $this->db->value('SELECT title FROM books WHERE id = ?', [$id]);
    }

    /** State migration 010 leaves behind on an existing installation. */
    private function startRepair(): void
    {
        $max = (int) $this->db->value('SELECT MAX(id) FROM books');
        $this->db->run('UPDATE settings SET value = ? WHERE name = ?', ["0:$max", DnbTitleRepair::SETTING]);
    }

    private function dnb(array &$calls = []): MetadataService
    {
        return new MetadataService($this->db, function (string $url) use (&$calls) {
            preg_match('/num%3D(\d{13})/', $url, $m);
            $calls[] = $m[1];
            $file = __DIR__ . '/fixtures/dnb_' . $m[1] . '.xml';
            return is_file($file) ? (string) file_get_contents($file) : null;
        });
    }

    public function testOldDnbTitlesAreCorrectedOnce(): void
    {
        $zauberer = $this->book('9783358000571', "\u{0098}Der\u{009C} kleine Zauberer und die große 5[Fünf");
        $temponauten = $this->book('9783423760508', "\u{0098}Die\u{009C} fünf Temponauten");
        $edited = $this->book('9783548283951', 'Impact (meine Ausgabe)');
        $this->startRepair();
        $later = $this->book('9783404139873', 'Added after the update');

        $calls = [];
        $result = (new DnbTitleRepair($this->db, $this->dnb($calls)))->run();
        self::assertSame(['checked' => 3, 'fixed' => 2, 'remaining' => 0], $result);
        self::assertSame(['9783358000571', '9783423760508', '9783548283951'], $calls, 'books added after the update are not looked up');

        self::assertSame('Der kleine Zauberer und die große 5', $this->title($zauberer));
        self::assertSame('Die fünf Temponauten. Treffpunkt 2321', $this->title($temponauten));
        self::assertSame(App::nowString(), $this->db->value('SELECT updated_at FROM books WHERE id = ?', [$zauberer]), 'offline copies pick up the change');
        self::assertSame('Impact (meine Ausgabe)', $this->title($edited), 'titles edited by hand stay');
        self::assertSame('Added after the update', $this->title($later));

        $again = (new DnbTitleRepair($this->db, $this->dnb($calls)))->run();
        self::assertSame(['checked' => 0, 'fixed' => 0, 'remaining' => 0], $again);
        self::assertCount(3, $calls, 'runs only once');
        self::assertNull($this->db->value('SELECT value FROM settings WHERE name = ?', [DnbTitleRepair::SETTING]), 'trigger removed when done');
    }

    public function testTitlesWithoutMarkersFromOtherSourcesStay(): void
    {
        // Same text the old parser made, minus the invisible markers (e.g. typed by hand) → also corrected.
        $copied = $this->book('9783358000571', 'Der kleine Zauberer und die große 5[Fünf');
        // Open Library title (different text) → untouched.
        $openLibrary = $this->book('9783423760508', 'Die fünf Temponauten - Treffpunkt 2321');
        $this->startRepair();

        (new DnbTitleRepair($this->db, $this->dnb()))->run();
        self::assertSame('Der kleine Zauberer und die große 5', $this->title($copied));
        self::assertSame('Die fünf Temponauten - Treffpunkt 2321', $this->title($openLibrary));
    }

    public function testContinuesWhereItStoppedWhenTheDnbIsDown(): void
    {
        $zauberer = $this->book('9783358000571', "\u{0098}Der\u{009C} kleine Zauberer und die große 5[Fünf");
        $this->startRepair();

        $down = new MetadataService($this->db, fn () => throw new MetadataUnavailable('HTTP status 503', 503));
        self::assertSame(['checked' => 0, 'fixed' => 0, 'remaining' => 1], (new DnbTitleRepair($this->db, $down))->run());

        self::assertSame(['checked' => 1, 'fixed' => 1, 'remaining' => 0], (new DnbTitleRepair($this->db, $this->dnb()))->run());
        self::assertSame('Der kleine Zauberer und die große 5', $this->title($zauberer));
    }

    public function testNewInstallationsHaveNothingToRepair(): void
    {
        $this->book('9783358000571', "\u{0098}Der\u{009C} kleine Zauberer und die große 5[Fünf");
        $calls = [];
        // Migration 010 ran on an empty database: "0:0".
        self::assertSame(['checked' => 0, 'fixed' => 0, 'remaining' => 0], (new DnbTitleRepair($this->db, $this->dnb($calls)))->run());
        self::assertSame([], $calls);
    }
}
