<?php

namespace App\Services\Collection;

use DateTimeInterface;

class ContentHashService
{
    public function urlHash(string $normalizedUrl): string
    {
        // URL正規化後の値を主キー代わりに使い、同じリンクの重複登録を防ぐ。
        return hash('sha256', $normalizedUrl);
    }

    public function contentHash(?string $title, DateTimeInterface|string|null $publishedAt, ?string $summary, ?string $bodyText): string
    {
        // URLが変わっても内容が同じ告知は、同一記事として扱えるよう本文側のハッシュも持つ。
        $published = $publishedAt instanceof DateTimeInterface
            ? $publishedAt->format('Y-m-d H:i:s')
            : (string) $publishedAt;

        return hash('sha256', $this->normalizeText(implode("\n", [
            $title,
            $published,
            $summary,
            $bodyText,
        ])));
    }

    private function normalizeText(string $text): string
    {
        // 空白や大小文字の揺れで内容ハッシュが変わりすぎないよう正規化する。
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
