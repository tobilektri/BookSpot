<?php

declare(strict_types=1);

/*
 * Fills an EMPTY installation with demo data (users, shelves, books, reading
 * history, loans) – to try BookSpot out or to produce the screenshots in docs/.
 *
 *   php backend/bin/demo-data.php [en|de]
 *
 * Users: anna (admin), ben, mia – password "demo-password".
 * Refuses to run when the database already contains books or users.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

use BookSpot\App;
use BookSpot\Auth;
use BookSpot\BookService;
use BookSpot\ShelfService;
use BookSpot\UserBookService;

$locale = in_array($argv[1] ?? 'en', ['en', 'de'], true) ? ($argv[1] ?? 'en') : 'en';
$db = App::db();
if ((int) $db->value('SELECT COUNT(*) FROM users') > 0 || (int) $db->value('SELECT COUNT(*) FROM books') > 0) {
    fwrite(STDERR, "The database is not empty – demo data is only added to a fresh installation.\n");
    exit(1);
}
$de = $locale === 'de';
$password = 'demo-password';

// ---- users
$auth = new Auth($db);
$anna = $auth->register('anna', 'anna@example.org', $password, $locale, force: true); // first user = admin
$ben = $auth->register('ben', 'ben@example.org', $password, $locale, force: true);
$mia = $auth->register('mia', 'mia@example.org', $password, $locale, force: true);
foreach ([$anna, $ben, $mia] as $user) {
    $auth->markOnboarded($user['id']);
}
$auth->updateProfile($anna['id'], ['library_name' => $de ? 'Stadtbibliothek' : 'City Library', 'library_loan_days' => 28]);

// ---- shelves: asymmetric columns with named compartments
$shelves = new ShelfService($db);
$living = $shelves->create([
    'name' => $de ? 'Wohnzimmer' : 'Living room',
    'room' => $de ? 'Wohnzimmer' : 'Living room',
    'columns' => [
        ['label' => 'L', 'rows' => ['L1', 'L2', 'L3', 'L4']],
        ['label' => 'M', 'rows' => ['M1', 'M2', 'M3', 'M4', 'M5']],
        ['label' => 'R', 'rows' => ['R1', 'R2', 'R3']],
    ],
]);
$hall = $shelves->create([
    'name' => $de ? 'Flur' : 'Hallway',
    'room' => $de ? 'Flur' : 'Hallway',
    'columns' => [['label' => 'A', 'rows' => ['A1', 'A2']], ['label' => 'B', 'rows' => ['B1', 'B2', 'B3']]],
]);

// ---- books: [title en, title de, authors, year, pages, genre en, genre de, shelf, col, row]
$catalogue = [
    ['Pride and Prejudice', 'Stolz und Vorurteil', 'Jane Austen', 1813, 432, 'Classics', 'Klassiker', $living, 1, 1],
    ['Emma', 'Emma', 'Jane Austen', 1815, 474, 'Classics', 'Klassiker', $living, 1, 1],
    ['Faust', 'Faust. Der Tragödie erster Teil', 'Johann Wolfgang von Goethe', 1808, 160, 'Drama', 'Drama', $living, 1, 2],
    ['Effi Briest', 'Effi Briest', 'Theodor Fontane', 1896, 336, 'Classics', 'Klassiker', $living, 1, 2],
    ['The Trial', 'Der Process', 'Franz Kafka', 1925, 288, 'Classics', 'Klassiker', $living, 1, 3],
    ['The Metamorphosis', 'Die Verwandlung', 'Franz Kafka', 1915, 96, 'Classics', 'Klassiker', $living, 1, 3],
    ['Buddenbrooks', 'Buddenbrooks', 'Thomas Mann', 1901, 768, 'Family saga', 'Familienroman', $living, 1, 4],
    ['The Magic Mountain', 'Der Zauberberg', 'Thomas Mann', 1924, 1008, 'Classics', 'Klassiker', $living, 1, 4],
    ['Moby-Dick', 'Moby-Dick', 'Herman Melville', 1851, 720, 'Adventure', 'Abenteuer', $living, 2, 1],
    ['Twenty Thousand Leagues Under the Seas', '20.000 Meilen unter dem Meer', 'Jules Verne', 1870, 464, 'Science fiction', 'Science-Fiction', $living, 2, 1],
    ['Around the World in Eighty Days', 'Reise um die Erde in 80 Tagen', 'Jules Verne', 1872, 272, 'Adventure', 'Abenteuer', $living, 2, 2],
    ['Treasure Island', 'Die Schatzinsel', 'Robert Louis Stevenson', 1883, 304, 'Adventure', 'Abenteuer', $living, 2, 2],
    ['The Adventures of Tom Sawyer', 'Die Abenteuer des Tom Sawyer', 'Mark Twain', 1876, 288, 'Adventure', 'Abenteuer', $living, 2, 2],
    ['Frankenstein', 'Frankenstein', 'Mary Shelley', 1818, 288, 'Horror', 'Horror', $living, 2, 3],
    ['Dracula', 'Dracula', 'Bram Stoker', 1897, 512, 'Horror', 'Horror', $living, 2, 3],
    ['The Picture of Dorian Gray', 'Das Bildnis des Dorian Gray', 'Oscar Wilde', 1890, 304, 'Classics', 'Klassiker', $living, 2, 3],
    ['The Hound of the Baskervilles', 'Der Hund der Baskervilles', 'Arthur Conan Doyle', 1902, 256, 'Crime', 'Krimi', $living, 2, 4],
    ['A Study in Scarlet', 'Eine Studie in Scharlachrot', 'Arthur Conan Doyle', 1887, 192, 'Crime', 'Krimi', $living, 2, 4],
    ['The Moonstone', 'Der Monddiamant', 'Wilkie Collins', 1868, 544, 'Crime', 'Krimi', $living, 2, 4],
    ['Crime and Punishment', 'Schuld und Sühne', 'Fyodor Dostoevsky', 1866, 704, 'Classics', 'Klassiker', $living, 2, 5],
    ['Anna Karenina', 'Anna Karenina', 'Leo Tolstoy', 1878, 1024, 'Classics', 'Klassiker', $living, 2, 5],
    ['War and Peace', 'Krieg und Frieden', 'Leo Tolstoy', 1869, 1584, 'Classics', 'Klassiker', $living, 3, 1],
    ['Great Expectations', 'Große Erwartungen', 'Charles Dickens', 1861, 544, 'Classics', 'Klassiker', $living, 3, 1],
    ['A Christmas Carol', 'Eine Weihnachtsgeschichte', 'Charles Dickens', 1843, 128, 'Classics', 'Klassiker', $living, 3, 2],
    ['Jane Eyre', 'Jane Eyre', 'Charlotte Brontë', 1847, 624, 'Classics', 'Klassiker', $living, 3, 2],
    ['Wuthering Heights', 'Sturmhöhe', 'Emily Brontë', 1847, 416, 'Classics', 'Klassiker', $living, 3, 3],
    ['The Time Machine', 'Die Zeitmaschine', 'H. G. Wells', 1895, 128, 'Science fiction', 'Science-Fiction', $hall, 1, 1],
    ['The War of the Worlds', 'Der Krieg der Welten', 'H. G. Wells', 1898, 256, 'Science fiction', 'Science-Fiction', $hall, 1, 1],
    ['The Jungle Book', 'Das Dschungelbuch', 'Rudyard Kipling', 1894, 240, "Children's books", 'Kinderbuch', $hall, 1, 2],
    ["Alice's Adventures in Wonderland", 'Alice im Wunderland', 'Lewis Carroll', 1865, 192, "Children's books", 'Kinderbuch', $hall, 1, 2],
    ['Grimms\' Fairy Tales', 'Kinder- und Hausmärchen', 'Jacob und Wilhelm Grimm', 1812, 864, "Children's books", 'Kinderbuch', $hall, 2, 1],
    ['The Odyssey', 'Die Odyssee', 'Homer', -700, 560, 'Classics', 'Klassiker', $hall, 2, 2],
    ['Meditations', 'Selbstbetrachtungen', 'Marcus Aurelius', 180, 256, 'Philosophy', 'Philosophie', $hall, 2, 3],
    ['The Art of War', 'Die Kunst des Krieges', 'Sunzi', -500, 128, 'Philosophy', 'Philosophie', $hall, 2, 3],
    ['Siddhartha', 'Siddhartha', 'Hermann Hesse', 1922, 176, 'Classics', 'Klassiker', null, null, null],
    ['The Count of Monte Cristo', 'Der Graf von Monte Christo', 'Alexandre Dumas', 1844, 1312, 'Adventure', 'Abenteuer', null, null, null],
];

/** Valid ISBN-13 in the 978-3-00 range (not real books). */
$isbn = static function (int $n): string {
    $digits = '978300' . str_pad((string) (900000 + $n), 6, '0', STR_PAD_LEFT);
    $sum = 0;
    foreach (str_split($digits) as $i => $digit) {
        $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
    }
    return $digits . ((10 - $sum % 10) % 10);
};

$books = new BookService($db, $shelves);
$personal = new UserBookService($db);
$ids = [];
foreach ($catalogue as $i => [$titleEn, $titleDe, $authors, $year, $pages, $genreEn, $genreDe, $shelf, $col, $row]) {
    $book = $books->create([
        'title' => $de ? $titleDe : $titleEn,
        'authors' => $authors,
        'isbn' => $isbn($i + 1),
        'published_year' => $year > 0 ? $year : null,
        'pages' => $pages,
        'genre' => $de ? $genreDe : $genreEn,
        'language' => $locale,
        'format' => $i % 3 === 0 ? ($de ? 'Hardcover' : 'Hardcover') : ($de ? 'Taschenbuch' : 'Paperback'),
        'shelf_id' => $shelf['id'] ?? null,
        'shelf_col' => $col,
        'shelf_row' => $row,
    ], $anna['id']);
    $ids[] = $book['id'];
}
// Demo books have no cover files: the app shows its generated covers without any request.
$db->run("UPDATE books SET cover_status = 'none', metadata_checked_at = ?", [App::nowString()]);

// ---- reading history of anna (and a bit of ben and mia) for statistics
$today = new DateTimeImmutable(App::today());
$readOrder = [0, 2, 4, 8, 13, 16, 3, 9, 11, 14, 26, 5, 19, 1, 24, 6, 27, 29, 31, 12, 17, 22];
foreach ($readOrder as $k => $index) {
    $finished = $today->modify('-' . (8 + $k * 17) . ' days');
    $personal->upsert($anna['id'], $ids[$index], [
        'reading_status' => 'read',
        'rating' => [5, 4, 5, 3, 4, 5, 4, 4, 3, 5][$k % 10],
        'pages_read' => $catalogue[$index][4],
        'started_at' => $finished->modify('-' . (6 + $k % 9) . ' days')->format('Y-m-d'),
        'finished_at' => $finished->format('Y-m-d'),
    ]);
}
$personal->upsert($anna['id'], $ids[20], ['reading_status' => 'reading', 'pages_read' => 412, 'started_at' => $today->modify('-12 days')->format('Y-m-d')]);
$personal->upsert($anna['id'], $ids[10], ['reading_status' => 'reading', 'pages_read' => 96, 'started_at' => $today->modify('-3 days')->format('Y-m-d')]);
$personal->upsert($anna['id'], $ids[7], ['reading_status' => 'abandoned', 'pages_read' => 210]);
foreach ([0, 4, 8, 13, 29] as $k => $index) {
    $personal->upsert($ben['id'], $ids[$index], ['reading_status' => 'read', 'rating' => [4, 5, 3, 4, 5][$k], 'finished_at' => $today->modify('-' . (20 + $k * 40) . ' days')->format('Y-m-d')]);
}
$personal->upsert($mia['id'], $ids[29], ['reading_status' => 'reading', 'pages_read' => 80]);

// ---- loans: from the library, from a friend, and a book lent out
$books->create([
    'title' => $de ? 'Die Insel des Dr. Moreau' : 'The Island of Doctor Moreau', 'authors' => 'H. G. Wells', 'isbn' => $isbn(90),
    'pages' => 208, 'genre' => $de ? 'Science-Fiction' : 'Science fiction', 'entry_type' => 'library_loan',
    'loan_start' => $today->modify('-26 days')->format('Y-m-d'), 'loan_due' => $today->modify('+2 days')->format('Y-m-d'),
], $anna['id']);
$books->create([
    'title' => $de ? 'Der Glöckner von Notre-Dame' : 'The Hunchback of Notre-Dame', 'authors' => 'Victor Hugo', 'isbn' => $isbn(91),
    'pages' => 640, 'genre' => $de ? 'Klassiker' : 'Classics', 'entry_type' => 'library_loan',
    'loan_start' => $today->modify('-6 days')->format('Y-m-d'), 'loan_due' => $today->modify('+22 days')->format('Y-m-d'),
], $anna['id']);
$books->create([
    'title' => $de ? 'Der Seewolf' : 'The Sea-Wolf', 'authors' => 'Jack London', 'isbn' => $isbn(92),
    'pages' => 368, 'genre' => $de ? 'Abenteuer' : 'Adventure', 'entry_type' => 'personal_loan',
    'loan_from' => 'Lena', 'loan_start' => $today->modify('-10 days')->format('Y-m-d'),
], $anna['id']);
$books->update($ids[15], ['lent_to' => 'Tom'], $anna['id']);
$db->run("UPDATE books SET cover_status = 'none', metadata_checked_at = ? WHERE cover_status IS NULL", [App::nowString()]);

echo "Demo data created ($locale): " . count($ids) . " books, 2 shelves, users anna (admin), ben, mia – password \"$password\".\n";
