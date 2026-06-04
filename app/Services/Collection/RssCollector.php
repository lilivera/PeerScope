<?php

namespace App\Services\Collection;

use Carbon\Carbon;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Throwable;

class RssCollector
{
    public function __construct(private readonly UrlNormalizer $urlNormalizer) {}

    /**
     * RSS 2.0とAtomのどちらでも、画面に必要な共通項目へ揃えて返す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $xml, string $baseUrl): array
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            throw new RuntimeException('RSS/XMLの解析に失敗しました。');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="item"]');

        if (! $nodes || $nodes->length === 0) {
            // Atomフィードではitemではなくentryが使われる。
            $nodes = $xpath->query('//*[local-name()="entry"]');
        }

        $items = [];

        foreach ($nodes ?: [] as $node) {
            $title = $this->text($xpath, $node, ['title']);
            $url = $this->link($xpath, $node);

            if ($title === '' || $url === null) {
                continue;
            }

            $summary = $this->text($xpath, $node, ['description', 'summary', 'content']);
            $publishedText = $this->text($xpath, $node, ['pubDate', 'published', 'updated', 'date']);

            $items[] = [
                'title' => $title,
                'url' => $this->urlNormalizer->normalize($url, $baseUrl),
                'published_at' => $this->parseDate($publishedText),
                'summary' => $this->cleanText($summary),
                'body_text' => null,
                'category' => $this->category($xpath, $node),
            ];
        }

        return $items;
    }

    private function text(DOMXPath $xpath, DOMNode $context, array $localNames): string
    {
        // 名前空間つきRSSにも対応するためlocal-name()で要素名だけを見る。
        foreach ($localNames as $localName) {
            $nodes = $xpath->query('.//*[local-name()="'.$localName.'"]', $context);

            if ($nodes && $nodes->length > 0) {
                return $this->cleanText($nodes->item(0)?->textContent ?? '');
            }
        }

        return '';
    }

    private function link(DOMXPath $xpath, DOMNode $context): ?string
    {
        $nodes = $xpath->query('.//*[local-name()="link"]', $context);

        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        // Atomのlinkはhref属性、RSSのlinkはテキストで渡されることが多い。
        $href = $node?->attributes?->getNamedItem('href')?->nodeValue;

        return $href ?: trim($node?->textContent ?? '') ?: null;
    }

    private function category(DOMXPath $xpath, DOMNode $context): ?string
    {
        $nodes = $xpath->query('.//*[local-name()="category"]', $context);

        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        $category = $node?->attributes?->getNamedItem('term')?->nodeValue ?: $node?->textContent;
        $category = $this->cleanText((string) $category);

        return $category !== '' ? $category : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
