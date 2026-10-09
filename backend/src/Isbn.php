<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * ISBN normalisation, validation and ISBN-10 <-> ISBN-13 conversion.
 */
final class Isbn
{
    /** Strips everything except digits and a trailing X ("978-3-404-13987-3" -> "9783404139873"). */
    public static function clean(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        // Spreadsheet exports sometimes wrap ISBNs as ="978..." to keep leading zeros.
        $value = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');
        // An X is only valid as the last character of an ISBN-10.
        if (str_contains(substr($value, 0, -1), 'X')) {
            $value = str_replace('X', '', $value);
        }
        return $value;
    }

    public static function isValid13(string $isbn): bool
    {
        if (!preg_match('/^\d{13}$/', $isbn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - $sum % 10) % 10 === (int) $isbn[12];
    }

    public static function isValid10(string $isbn): bool
    {
        if (!preg_match('/^\d{9}[\dX]$/', $isbn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = $isbn[$i] === 'X' ? 10 : (int) $isbn[$i];
            $sum += $digit * (10 - $i);
        }
        return $sum % 11 === 0;
    }

    public static function to13(string $isbn10): ?string
    {
        if (!self::isValid10($isbn10)) {
            return null;
        }
        $base = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $base[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return $base . (10 - $sum % 10) % 10;
    }

    public static function to10(string $isbn13): ?string
    {
        if (!self::isValid13($isbn13) || !str_starts_with($isbn13, '978')) {
            return null;
        }
        $base = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $base[$i] * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;
        return $base . ($check === 10 ? 'X' : (string) $check);
    }

    /**
     * Normalises any input to [isbn13, isbn10]. Unknown/invalid input yields nulls.
     * Invalid-checksum 13-digit EANs starting with 978/979 are still accepted as
     * ISBN-13, because some publishers print broken check digits.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normalize(?string $raw): array
    {
        $isbn = self::clean($raw);
        if (strlen($isbn) === 13 && preg_match('/^97[89]/', $isbn)) {
            return [$isbn, self::to10($isbn)];
        }
        if (strlen($isbn) === 10 && self::isValid10($isbn)) {
            return [self::to13($isbn), $isbn];
        }
        return [null, null];
    }
}
