<?php

namespace App\Services\Collection;

use App\Models\WatchSource;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

class HtmlCollector
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly CssSelectorConverter $converter = new CssSelectorConverter,
    ) {}

    /**
     * HTML本文から、収集先ごとのCSSセレクタ設定に従って新着候補を取り出す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $html, WatchSource $source): array
    {
        if (! $source->list_selector) {
            throw new RuntimeException('list_selector が未設定です。');
        }

        if ($source->list_selector === 'js-news-list') {
            // 蒲郡信用金庫のように一覧がJavaScript配列だけで提供されるページ用の特別処理。
            return $this->parseJsNewsList($html, $source);
        }

        foreach (['title_selector', 'url_selector'] as $field) {
            if (! $source->{$field}) {
                throw new RuntimeException($field.' が未設定です。');
            }
        }

        $document = new DOMDocument;

        // 多少壊れたHTMLでも収集を継続できるよう、libxmlの警告は抑制する。
        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            throw new RuntimeException('HTMLの解析に失敗しました。');
        }

        $xpath = new DOMXPath($document);
        $rows = $this->query($xpath, $source->list_selector);
        $items = [];

        foreach ($rows as $row) {
            $titleNode = $this->first($xpath, $source->title_selector, $row);
            $urlNode = $this->first($xpath, $source->url_selector, $row) ?: $titleNode;

            $title = $this->cleanText($titleNode?->textContent ?? '');
            $url = $this->extractUrl($urlNode);

            if ($title === '' || $url === null) {
                continue;
            }

            $dateText = $this->selectorText($xpath, $row, $source->date_selector);
            $bodyText = $source->body_selector
                ? $this->cleanText($this->first($xpath, $source->body_selector, $row)?->textContent ?? '')
                : '';
            $summary = $bodyText !== '' ? $bodyText : $this->cleanText($row->textContent ?? '');

            $items[] = [
                'title' => $title,
                'url' => $this->urlNormalizer->normalize($url, $source->source_url),
                'published_at' => $this->parseDate($dateText),
                'summary' => Str::limit($summary, 500, '...'),
                'body_text' => $bodyText !== '' ? $bodyText : null,
                'category' => $source->source_name,
            ];
        }

        return $items;
    }

    private function query(DOMXPath $xpath, string $selector, ?DOMNode $context = null): iterable
    {
        $expression = $this->converter->toXPath($selector);
        $nodes = $context ? $xpath->query($expression, $context) : $xpath->query($expression);

        if ($nodes === false) {
            throw new RuntimeException('CSSセレクタの評価に失敗しました: '.$selector);
        }

        return $nodes;
    }

    private function first(DOMXPath $xpath, ?string $selector, DOMNode $context): ?DOMNode
    {
        if (! $selector) {
            return null;
        }

        $nodes = $this->query($xpath, $selector, $context);

        return $nodes->item(0);
    }

    private function selectorText(DOMXPath $xpath, DOMNode $context, ?string $selector): string
    {
        if (! $selector) {
            return '';
        }

        if (str_starts_with($selector, 'previous:')) {
            // dt/dd型の一覧では、ddから見た直前のdtを日付として読む。
            $previous = $this->previousElement($context, substr($selector, 9));

            return $this->cleanText($previous?->textContent ?? '');
        }

        return $this->cleanText($this->first($xpath, $selector, $context)?->textContent ?? '');
    }

    private function previousElement(DOMNode $node, string $selector): ?DOMElement
    {
        $selector = trim($selector);
        $sibling = $node->previousSibling;

        while ($sibling) {
            if ($sibling instanceof DOMElement && $this->matchesSimpleSelector($sibling, $selector)) {
                return $sibling;
            }

            $sibling = $sibling->previousSibling;
        }

        return null;
    }

    private function matchesSimpleSelector(DOMElement $element, string $selector): bool
    {
        // previous:用なので、必要な単純セレクタだけを手元で判定する。
        if ($selector === '') {
            return true;
        }

        if (preg_match('/^([a-z0-9_-]+)$/i', $selector, $matches)) {
            return strtolower($element->tagName) === strtolower($matches[1]);
        }

        if (preg_match('/^\.([a-z0-9_-]+)$/i', $selector, $matches)) {
            return in_array($matches[1], preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
        }

        if (preg_match('/^([a-z0-9_-]+)\.([a-z0-9_-]+)$/i', $selector, $matches)) {
            return strtolower($element->tagName) === strtolower($matches[1])
                && in_array($matches[2], preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
        }

        return false;
    }

    private function extractUrl(?DOMNode $node): ?string
    {
        if (! $node) {
            return null;
        }

        if ($node instanceof DOMElement && $node->hasAttribute('href')) {
            return $node->getAttribute('href');
        }

        if ($node instanceof DOMElement) {
            $links = $node->getElementsByTagName('a');

            if ($links->length > 0) {
                return $links->item(0)?->getAttribute('href') ?: null;
            }
        }

        return null;
    }

    private function parseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $value, $matches)) {
            return Carbon::create((int) $matches[1], (int) $matches[2], (int) $matches[3])->startOfDay();
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

    /**
     * DaaS系サイトのNewsListArrayを読み、ページ内のtarget指定に合うカテゴリだけを取り込む。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseJsNewsList(string $html, WatchSource $source): array
    {
        $script = str_contains($html, 'NewsListArray[')
            ? $html
            : $this->loadNewsListScript($html, $source);
        $targets = $this->extractNewsListTargets($html);
        $targetSet = array_fill_keys($targets, true);
        $items = [];
        $seenUrls = [];

        preg_match_all('/NewsListArray\[(\d+)\]\[(\d+)\]\s*=\s*\[(.*?)\]\s*;/su', $script, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $target = $block[1].'_'.$block[2];

            // 一覧ページのhidden targetにないカテゴリは、この収集元の対象外にする。
            if ($targets !== [] && ! isset($targetSet[$target])) {
                continue;
            }

            preg_match_all(
                "/\[\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'\s*,\s*'((?:\\\\'|[^'])*)'\s*\]/u",
                $block[3],
                $entries,
                PREG_SET_ORDER,
            );

            foreach ($entries as $entry) {
                $date = $this->decodeJsString($entry[1]);
                $title = $this->cleanText($this->decodeJsString($entry[2]));
                $url = $this->decodeJsString($entry[3]);
                $normalizedUrl = $this->urlNormalizer->normalize($url, $source->source_url);

                if ($title === '' || $normalizedUrl === null || isset($seenUrls[$normalizedUrl])) {
                    continue;
                }

                // 複数カテゴリに同じURLが出ることがあるため、収集元内ではURLで一度だけ扱う。
                $seenUrls[$normalizedUrl] = true;

                $items[] = [
                    'title' => $title,
                    'url' => $normalizedUrl,
                    'published_at' => $this->parseDate($date),
                    'summary' => $title,
                    'body_text' => null,
                    'category' => $source->source_name,
                ];
            }
        }

        return $items;
    }

    private function loadNewsListScript(string $html, WatchSource $source): string
    {
        preg_match('/<script\b[^>]*\bsrc=["\']([^"\']*news_list\.js[^"\']*)["\']/i', $html, $matches);

        // common_js.js経由で読み込むサイトもあるため、見つからない場合は標準位置を試す。
        $scriptUrl = $this->urlNormalizer->normalize($matches[1] ?? '../js/news_list.js', $source->source_url);

        if ($scriptUrl === null) {
            throw new RuntimeException('news_list.js のURLを解決できません。');
        }

        $response = Http::timeout(20)
            ->retry(1, 500)
            ->withUserAgent('PeerScope/0.1 (+internal news monitoring)')
            ->accept('application/javascript, text/javascript, */*;q=0.8')
            ->get($scriptUrl);

        if ($response->status() >= 400) {
            throw new RuntimeException('news_list.js の取得に失敗しました: HTTP '.$response->status());
        }

        return $response->body();
    }

    /**
     * 一覧ページ内のhidden targetから、表示対象カテゴリを取り出す。
     *
     * @return array<int, string>
     */
    private function extractNewsListTargets(string $html): array
    {
        preg_match_all(
            '/<input\b(?=[^>]*\bname=["\']target["\'])(?=[^>]*\bvalue=["\']([^"\']+)["\'])[^>]*>/i',
            $html,
            $matches,
        );

        return collect($matches[1] ?? [])
            ->map(fn (string $target): string => trim($target))
            ->filter(fn (string $target): bool => (bool) preg_match('/^\d+_\d+$/', $target))
            ->unique()
            ->values()
            ->all();
    }

    private function decodeJsString(string $value): string
    {
        return stripcslashes($value);
    }
}
