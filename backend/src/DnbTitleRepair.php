<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * One-time correction of titles taken from the DNB before 1.7.3.
 *
 * Older versions read DNB records too literally: non-sorting markers around
 * articles (U+0098/U+009C), the spelled-out number for sorting with a cut-off
 * bracket ("die große 5[Fünf") and no part title of multi-part works ("Die fünf
 * Temponauten" instead of "Die fünf Temponauten. Treffpunkt 2321").
 *
 * Every book with an ISBN that existed before the update is looked up once more.
 * The title is only replaced when it is still exactly what the old parser made of
 * that record – titles from other sources or edited by hand stay untouched.
 *
 * Triggered once by migration 010, which stores "next:until" in the settings table
 * with "until" = highest book id at update time: only books that already existed
 * are looked up, books added later (with the new parser) never. The work is done
 * by cron in small batches – hundreds of DNB requests don't fit into the web
 * request that applies the migration – and the setting is deleted when finished.
 */
final class DnbTitleRepair
{
    public const SETTING = 'dnb_title_repair';

    public function __construct(private readonly Database $db, private readonly MetadataService $metadata)
    {
    }

    /** @return array{checked: int, fixed: int, remaining: int} */
    public function run(int $limit = 30, float $maxSeconds = 30.0): array
    {
        $start = microtime(true);
        $checked = 0;
        $fixed = 0;
        $progress = $this->progress();
        if ($progress === null || !Config::bool('METADATA_LOOKUP', true) || !Config::bool('METADATA_DNB', true)) {
            return ['checked' => 0, 'fixed' => 0, 'remaining' => 0];
        }
        [$next, $until] = $progress;
        $rows = $this->db->all(
            'SELECT id, isbn13, title FROM books WHERE id >= ? AND id <= ? AND isbn13 IS NOT NULL ORDER BY id LIMIT ' . max(1, min(500, $limit)),
            [$next, $until]
        );
        foreach ($rows as $book) {
            if (microtime(true) - $start > $maxSeconds) {
                break;
            }
            try {
                $xml = $this->metadata->dnbMarc((string) $book['isbn13']);
            } catch (MetadataUnavailable) {
                break; // DNB unreachable or paused: continue with this book next run
            }
            $checked++;
            if ($xml !== null && $this->repair($book, $xml)) {
                $fixed++;
            }
            $next = (int) $book['id'] + 1;
            $this->save($next, $until);
        }
        $remaining = $this->remaining($next, $until);
        if ($remaining === 0) {
            // Done for good: without the setting every later cron run ends at the first query.
            $this->db->run('DELETE FROM settings WHERE name = ?', [self::SETTING]);
        }
        return ['checked' => $checked, 'fixed' => $fixed, 'remaining' => $remaining];
    }

    /** Replaces the title when it is unchanged from what the old parser produced. */
    private function repair(array $book, string $xml): bool
    {
        $old = self::legacyTitle($xml);
        $new = MetadataService::parseDnbRecord($xml)['title'] ?? null;
        if ($old === null || $new === null || $old === $new) {
            return false;
        }
        $stored = (string) $book['title'];
        $withoutMarkers = preg_replace('/[\x{0088}\x{0089}\x{0098}\x{009C}]/u', '', $old) ?? $old;
        if ($stored !== $old && $stored !== $withoutMarkers) {
            return false; // other source or edited by hand
        }
        $this->db->update('books', ['title' => mb_substr($new, 0, 500), 'updated_at' => App::nowString()], ['id' => (int) $book['id']]);
        return true;
    }

    /** Title as BookSpot up to 1.7.2 read it from a DNB record ($a + ": " + $b). */
    public static function legacyTitle(string $xml): ?string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('m', 'http://www.loc.gov/MARC21/slim');
        $subfield = fn (string $code) => $xpath->query("//m:record/m:datafield[@tag='245'][1]/m:subfield[@code='$code'][1]")?->item(0)?->textContent;
        $clean = fn (?string $v) => $v === null ? null : (trim(rtrim(trim($v), ' /:;,.='), ' []') ?: null);
        $title = $clean($subfield('a'));
        $subtitle = $clean($subfield('b'));
        return $title === null ? null : ($subtitle ? "$title: $subtitle" : $title);
    }

    /** @return array{int, int}|null [next id, last id] or null when there is nothing (left) to do */
    private function progress(): ?array
    {
        $value = $this->db->value('SELECT value FROM settings WHERE name = ?', [self::SETTING]);
        if (!is_string($value) || !preg_match('/^(\d+):(\d+)$/', $value, $m) || (int) $m[1] > (int) $m[2]) {
            return null;
        }
        return [(int) $m[1], (int) $m[2]];
    }

    private function save(int $next, int $until): void
    {
        $this->db->run('UPDATE settings SET value = ? WHERE name = ?', ["$next:$until", self::SETTING]);
    }

    private function remaining(int $next, int $until): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM books WHERE id >= ? AND id <= ? AND isbn13 IS NOT NULL', [$next, $until]);
    }
}
