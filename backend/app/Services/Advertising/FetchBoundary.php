<?php

namespace App\Services\Advertising;

use RuntimeException;

/** Conservative local response-shape contract. Not a provider inventory guarantee. */
final class FetchBoundary
{
    public const MAX_PAGES = 100;

    public static function rows(mixed $rows, string $context): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
            throw new RuntimeException($context.' requires an explicit row list.');
        }
        foreach ($rows as $r) {
            if (! is_array($r) || array_is_list($r)) {
                throw new RuntimeException($context.' contains a malformed row.');
            }
        }

        return $rows;
    }

    public static function google(mixed $data, array &$tokens, int &$pages): array
    {
        if (++$pages > self::MAX_PAGES) {
            throw new RuntimeException('Google local page limit reached; no partial result.');
        }
        if (! is_array($data) || array_is_list($data) || isset($data['error'])) {
            throw new RuntimeException('Google search envelope invalid.');
        }
        $rows = self::rows($data['results'] ?? null, 'Google search');
        $next = null;
        if (array_key_exists('nextPageToken', $data)) {
            $next = $data['nextPageToken'];
            if (! is_string($next) || $next === '' || strlen($next) > 8192 || isset($tokens[$next])) {
                throw new RuntimeException('Google next token malformed or repeated.');
            }
            if ($pages >= self::MAX_PAGES) {
                throw new RuntimeException('Google local page limit reached; no partial result.');
            }
            $tokens[$next] = true;
        }

        return [$rows, $next];
    }

    public static function tiktok(mixed $data, int $page, ?int &$expectedPages): array
    {
        if (! is_array($data) || ($data['code'] ?? null) !== 0 || ! is_array($data['data'] ?? null)) {
            throw new RuntimeException('TikTok response envelope invalid.');
        }
        $body = $data['data'];
        $rows = self::rows($body['list'] ?? null, 'TikTok page');
        $total = $body['page_info']['total_page'] ?? null;
        if (! is_int($total) || $total < 1 || $total > self::MAX_PAGES || $page > $total || ($expectedPages !== null && $expectedPages !== $total)) {
            throw new RuntimeException('TikTok page count missing, invalid, changed or over local limit.');
        }
        if (array_key_exists('page',$body['page_info']) && $body['page_info']['page'] !== $page) {
            throw new RuntimeException('TikTok returned another page.');
        }
        $expectedPages = $total;

        return $rows;
    }

    public static function id(mixed $id, string $context): string
    {
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[A-Za-z0-9_:.\/-]{1,255}$/D', (string) $id)) {
            throw new RuntimeException($context.' identifier missing or invalid.');
        }

        return (string) $id;
    }

    public static function unique(array &$seen, string $key, string $context): void
    {
        if (isset($seen[$key])) {
            throw new RuntimeException($context.' duplicate row.');
        }
        $seen[$key] = true;
    }
}
