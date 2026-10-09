<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Due-date reminders for library and private loans (run by cron.php).
 *
 * Sends a push 3 days and 1 day before `loan_due` and on the due date itself.
 * notification_log makes runs idempotent, so cron can run hourly without
 * sending duplicates.
 */
final class ReminderService
{
    /** days before due => notification kind */
    public const SCHEDULE = [3 => 'due_in_3', 1 => 'due_in_1', 0 => 'due_today'];

    public function __construct(private readonly Database $db, private readonly PushService $push)
    {
    }

    /** @return array{checked: int, notified: int, sent: int, failed: int} */
    public function run(): array
    {
        $today = new \DateTimeImmutable(App::today());
        $stats = ['checked' => 0, 'notified' => 0, 'sent' => 0, 'failed' => 0];

        foreach (self::SCHEDULE as $days => $kind) {
            $due = $today->modify("+$days days")->format('Y-m-d');
            $books = $this->db->all(
                "SELECT id, title, loan_due, loan_from, loan_user_id, entry_type FROM books
                 WHERE status = 'active' AND entry_type IN ('library_loan', 'personal_loan') AND loan_due = ?",
                [$due]
            );
            foreach ($books as $book) {
                $stats['checked']++;
                foreach ($this->recipients($book) as $user) {
                    $already = $this->db->value(
                        'SELECT id FROM notification_log WHERE user_id = ? AND book_id = ? AND kind = ? AND due_date = ?',
                        [(int) $user['id'], (int) $book['id'], $kind, $due]
                    );
                    if ($already !== null) {
                        continue;
                    }
                    $report = $this->push->send([(int) $user['id']], self::message($book, $days, (string) $user['locale']));
                    $stats['sent'] += $report['sent'];
                    $stats['failed'] += $report['failed'];
                    if ($report['sent'] > 0) {
                        $stats['notified']++;
                        $this->db->insert('notification_log', [
                            'user_id' => (int) $user['id'],
                            'book_id' => (int) $book['id'],
                            'kind' => $kind,
                            'due_date' => $due,
                            'sent_at' => App::nowString(),
                        ]);
                    }
                }
            }
        }
        return $stats;
    }

    /**
     * The borrower gets the reminder; loans without borrower notify every user
     * with a push subscription (shared household catalogue).
     *
     * @return list<array<string, mixed>>
     */
    private function recipients(array $book): array
    {
        if (!empty($book['loan_user_id'])) {
            return $this->db->all('SELECT id, locale FROM users WHERE id = ?', [(int) $book['loan_user_id']]);
        }
        return $this->db->all('SELECT DISTINCT u.id, u.locale FROM users u JOIN push_subscriptions p ON p.user_id = u.id');
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public static function message(array $book, int $days, string $locale): array
    {
        $title = (string) $book['title'];
        $date = (new \DateTimeImmutable((string) $book['loan_due']))->format($locale === 'en' ? 'Y-m-d' : 'd.m.Y');
        $from = $book['loan_from'] ? " ({$book['loan_from']})" : '';
        if ($locale === 'en') {
            $head = match ($days) {
                0 => 'Due today',
                1 => 'Due tomorrow',
                default => "Due in $days days",
            };
            $body = "„{$title}“ must be returned by $date$from.";
        } else {
            $head = match ($days) {
                0 => 'Heute fällig',
                1 => 'Morgen fällig',
                default => "In $days Tagen fällig",
            };
            $body = "„{$title}“ muss bis $date zurückgegeben werden$from.";
        }
        return [
            'title' => '📚 ' . $head,
            'body' => $body,
            'url' => './#/books/' . (int) $book['id'],
            'tag' => 'loan-' . (int) $book['id'],
        ];
    }
}
