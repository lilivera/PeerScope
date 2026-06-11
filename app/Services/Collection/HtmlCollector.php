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
    private const JSON_NEWS_LISTPAGE_DIR = [
        '1' => '',
        '2' => 'kojin/',
        '3' => 'hojin/',
    ];

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
            // 一覧がJavaScript配列だけで提供されるページ用の特別処理。
            return $this->parseJsNewsList($html, $source);
        }

        if ($source->list_selector === 'json-news-list') {
            // HTML表示をJSONから組み立てるサイト用の特別処理。
            return $this->parseJsonNewsList($html, $source);
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

    /**
     * URLだけで登録された収集先から、確度の高い新着一覧を推定する。
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseAuto(string $html, WatchSource $source, int $depth = 0, array $visitedUrls = []): array
    {
        $visitedUrls[$source->source_url] = true;
        $specialParsers = [];

        if (str_contains($html, 'NewsListArray[')
            || preg_match('/news_list\.js/i', $html)
            || $this->extractNewsListTargets($html) !== []) {
            $specialParsers[] = fn (): array => $this->parseJsNewsList($html, $source);
        }

        if (str_contains($html, '_cmn-newslist') || str_contains($html, 'assets/data/news/list.json')) {
            $specialParsers[] = fn (): array => $this->parseJsonNewsList($html, $source);
        }

        foreach ($specialParsers as $parser) {
            try {
                $items = $parser();

                if ($items !== []) {
                    return $items;
                }
            } catch (Throwable) {
                // 自動判定では、特殊形式に見えても失敗した場合は一般HTMLの推定へ進む。
            }
        }

        $items = $this->parseGenericNewsList($html, $source);

        if ($items !== []) {
            return $items;
        }

        if ($depth >= 2) {
            return [];
        }

        foreach ($this->extractSameOriginIframeUrls($html, $source->source_url) as $iframeUrl) {
            if (isset($visitedUrls[$iframeUrl])) {
                continue;
            }

            try {
                $response = Http::timeout(20)
                    ->retry(1, 500)
                    ->withUserAgent('PeerScope/0.1 (+internal news monitoring)')
                    ->accept('text/html, application/xhtml+xml, */*;q=0.8')
                    ->get($iframeUrl);

                if ($response->status() >= 400) {
                    continue;
                }

                $iframeSource = clone $source;
                $iframeSource->forceFill(['source_url' => $iframeUrl]);
                $items = $this->parseAuto($response->body(), $iframeSource, $depth + 1, $visitedUrls);

                if ($items !== []) {
                    return $items;
                }
            } catch (Throwable) {
                // 埋め込み先が読めない場合でも、ほかの候補を探し続ける。
            }
        }

        return [];
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

        if (preg_match('/(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})/', $value, $matches)) {
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
     * 一般的なニュース一覧から、日付に近いリンクを候補として抽出する。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseGenericNewsList(string $html, WatchSource $source): array
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $links = $xpath->query('//a[@href]');
        $candidates = [];
        $seenContainers = [];
        $index = 0;

        foreach ($links ?: [] as $link) {
            if (! $link instanceof DOMElement || $this->isInsideExcludedArea($link)) {
                continue;
            }

            $title = $this->cleanText($link->textContent ?? '');
            $url = $this->urlNormalizer->normalize($link->getAttribute('href'), $source->source_url);

            if (! $this->isPlausibleNewsTitle($title) || $url === null) {
                continue;
            }

            $container = $this->candidateContainer($link);
            $containerKey = $this->candidateContainerKey($container);

            if ($containerKey && isset($seenContainers[$containerKey])) {
                continue;
            }

            $dateText = $this->dateTextNear($container, $link);
            $publishedAt = $this->parseDate($dateText);
            $summary = $this->cleanText($container->textContent ?? $title);
            $score = $this->autoCandidateScore($link, $container, $title, $url, $publishedAt !== null);

            if ($score < 35) {
                continue;
            }

            if ($containerKey) {
                $seenContainers[$containerKey] = true;
            }

            $candidates[] = [
                'index' => $index++,
                'score' => $score,
                'title' => $title,
                'url' => $url,
                'published_at' => $publishedAt,
                'summary' => Str::limit($summary !== '' ? $summary : $title, 500, '...'),
                'body_text' => null,
                'category' => $source->source_name,
            ];
        }

        $dated = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $candidate['published_at'] !== null,
        ));
        $items = $dated !== [] ? $dated : $candidates;

        usort($items, fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return array_map(function (array $item): array {
            unset($item['index'], $item['score']);

            return $item;
        }, $items);
    }

    /**
     * @return array<int, string>
     */
    private function extractSameOriginIframeUrls(string $html, string $baseUrl): array
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return [];
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//iframe[@src]');
        $urls = [];

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->urlNormalizer->normalize($node->getAttribute('src'), $baseUrl);
            $host = $url ? parse_url($url, PHP_URL_HOST) : null;

            if ($url && $host && strtolower($host) === strtolower((string) $baseHost)) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    private function candidateContainer(DOMElement $link): DOMNode
    {
        $node = $link;

        while ($node->parentNode instanceof DOMElement) {
            $node = $node->parentNode;
            $tag = strtolower($node->tagName);

            if (in_array($tag, ['li', 'article', 'tr', 'dd', 'dt'], true)) {
                return $node;
            }

            if (in_array($tag, ['div', 'section', 'p'], true) && $this->looksLikeNewsContainer($node)) {
                return $node;
            }

            if (in_array($tag, ['body', 'html'], true)) {
                return $link->parentNode instanceof DOMNode ? $link->parentNode : $link;
            }
        }

        return $link;
    }

    private function dateTextNear(DOMNode $container, ?DOMElement $link = null): string
    {
        $dateText = $this->extractDateText($this->textWithoutLink($container, $link));

        if ($dateText !== '') {
            return $dateText;
        }

        $previous = $this->previousElement($container, '');

        return $this->extractDateText($previous?->textContent ?? '');
    }

    private function textWithoutLink(DOMNode $container, ?DOMElement $link): string
    {
        $text = (string) ($container->textContent ?? '');

        if (! $link) {
            return $text;
        }

        $linkText = (string) ($link->textContent ?? '');

        if ($linkText === '') {
            return $text;
        }

        return preg_replace('/'.preg_quote($linkText, '/').'/u', '', $text, 1) ?? $text;
    }

    private function extractDateText(string $text): string
    {
        foreach ([
            '/\d{4}\s*年\s*\d{1,2}\s*月\s*\d{1,2}\s*日(?:\s*\d{1,2}:\d{2})?/u',
            '/\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2}(?:\s+\d{1,2}:\d{2})?/',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $matches[0];
            }
        }

        return '';
    }

    private function autoCandidateScore(DOMElement $link, DOMNode $container, string $title, string $url, bool $hasDate): int
    {
        $score = $hasDate ? 50 : 0;
        $titleLength = Str::length($title);

        if ($titleLength >= 8 && $titleLength <= 120) {
            $score += 10;
        }

        if ($container instanceof DOMElement && $this->looksLikeNewsContainer($container)) {
            $score += 15;
        }

        if (preg_match('/(?:news|notice|topic|topics|info|press|pdf|ir)/i', $url.$this->nodeSignature($link))) {
            $score += 10;
        }

        $linkCount = $container instanceof DOMElement ? $container->getElementsByTagName('a')->length : 1;

        if ($linkCount > 8) {
            $score -= 25;
        } elseif ($linkCount > 3) {
            $score -= 10;
        }

        return $score;
    }

    private function candidateContainerKey(DOMNode $container): ?string
    {
        if (! $container instanceof DOMElement) {
            return null;
        }

        if (! in_array(strtolower($container->tagName), ['li', 'article', 'tr', 'dd', 'dt'], true)) {
            return null;
        }

        $segments = [];
        $node = $container;

        while ($node instanceof DOMElement) {
            $position = 1;
            $sibling = $node->previousSibling;

            while ($sibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === strtolower($node->tagName)) {
                    $position++;
                }

                $sibling = $sibling->previousSibling;
            }

            array_unshift($segments, strtolower($node->tagName).'['.$position.']');
            $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
        }

        return implode('/', $segments);
    }

    private function isPlausibleNewsTitle(string $title): bool
    {
        if ($title === '' || Str::length($title) < 4) {
            return false;
        }

        return ! in_array($title, [
            'TOP',
            'HOME',
            '戻る',
            '一覧',
            '詳細',
            'もっと見る',
            '続きを読む',
            'PDF',
        ], true);
    }

    private function isInsideExcludedArea(DOMElement $node): bool
    {
        while ($node->parentNode instanceof DOMElement) {
            $node = $node->parentNode;

            if (in_array(strtolower($node->tagName), ['nav', 'header', 'footer', 'aside'], true)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNewsContainer(DOMElement $node): bool
    {
        return (bool) preg_match('/(?:news|notice|topic|topics|info|press|release|entry|item|list)/i', $this->nodeSignature($node));
    }

    private function nodeSignature(DOMElement $node): string
    {
        return $node->tagName.' '.$node->getAttribute('id').' '.$node->getAttribute('class');
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

    /**
     * assets/data/news/list.json を使うサイトの新着一覧を、ブラウザと同じ条件で取り込む。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonNewsList(string $html, WatchSource $source): array
    {
        $webroot = $this->extractDataWebroot($html) ?? $this->inferJsonNewsWebroot($source);
        $listPages = $this->extractJsonNewsListPages($html);
        $listPageSet = array_fill_keys($listPages, true);
        $jsonUrl = $this->jsonNewsListUrl($webroot, $source);
        $rows = $this->loadJsonNewsRows($jsonUrl);
        $items = [];
        $seenUrls = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->jsonNewsBelongsToListPage($row, $listPageSet)) {
                continue;
            }

            $title = $this->cleanText((string) ($row['title'] ?? ''));
            $url = $this->jsonNewsItemUrl($row, $webroot, $listPages[0] ?? null);
            $normalizedUrl = $url ? $this->urlNormalizer->normalize($url, $source->source_url) : null;

            if ($title === '' || $normalizedUrl === null || isset($seenUrls[$normalizedUrl])) {
                continue;
            }

            // JSON内で同じ記事が複数カテゴリに属しても、収集元内ではURLで一度だけ扱う。
            $seenUrls[$normalizedUrl] = true;

            $items[] = [
                'title' => $title,
                'url' => $normalizedUrl,
                'published_at' => $this->parseDate((string) ($row['pubdate'] ?? '')),
                'summary' => $title,
                'body_text' => null,
                'category' => $source->source_name,
            ];
        }

        return $items;
    }

    private function extractDataWebroot(string $html): ?string
    {
        preg_match('/<body\b[^>]*\bdata-webroot=["\']([^"\']*)["\']/i', $html, $matches);
        $webroot = trim(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $webroot !== '' ? $webroot : null;
    }

    private function inferJsonNewsWebroot(WatchSource $source): string
    {
        $path = parse_url($source->source_url, PHP_URL_PATH) ?: '/';

        if (preg_match('#/news/?$#', $path)) {
            return (string) preg_replace('#news/?$#', '', $path);
        }

        return '/';
    }

    /**
     * ページ上の _cmn-newslist が指定している一覧ページ番号を取り出す。
     *
     * @return array<int, string>
     */
    private function extractJsonNewsListPages(string $html): array
    {
        preg_match_all('/<[^>]*_cmn-newslist[^>]*>/i', $html, $tags);
        $pages = [];

        foreach ($tags[0] ?? [] as $tag) {
            if (! preg_match('/\bdata-listpage=["\']?([^"\'\s>]+)["\']?/i', $tag, $matches)) {
                continue;
            }

            $page = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($page !== '') {
                $pages[$page] = true;
            }
        }

        return array_keys($pages);
    }

    private function jsonNewsListUrl(string $webroot, WatchSource $source): string
    {
        $path = rtrim($webroot, '/').'/assets/data/news/list.json';
        $url = $this->urlNormalizer->normalize($path, $source->source_url);

        if ($url === null) {
            throw new RuntimeException('ニュースJSONのURLを解決できません。');
        }

        return $url;
    }

    /**
     * @return array<int, mixed>
     */
    private function loadJsonNewsRows(string $jsonUrl): array
    {
        $response = Http::timeout(20)
            ->retry(1, 500)
            ->withUserAgent('PeerScope/0.1 (+internal news monitoring)')
            ->accept('application/json, */*;q=0.8')
            ->get($jsonUrl);

        if ($response->status() >= 400) {
            throw new RuntimeException('ニュースJSONの取得に失敗しました: HTTP '.$response->status());
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('ニュースJSONの解析に失敗しました: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload) || ! is_array($payload['list'] ?? null)) {
            throw new RuntimeException('ニュースJSONにlist配列がありません。');
        }

        return $payload['list'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $listPageSet
     */
    private function jsonNewsBelongsToListPage(array $row, array $listPageSet): bool
    {
        if ($listPageSet === []) {
            return true;
        }

        if (! is_array($row['listpages'] ?? null)) {
            return false;
        }

        foreach ($row['listpages'] as $page) {
            if (isset($listPageSet[(string) $page])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function jsonNewsItemUrl(array $row, string $webroot, ?string $listPage): ?string
    {
        return match ((string) ($row['type'] ?? '')) {
            '1' => $this->jsonNewsDetailUrl($row, $webroot, $listPage),
            '2' => (string) ($row['link_url'] ?? '') ?: null,
            '3' => (string) ($row['file'] ?? '') ?: null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function jsonNewsDetailUrl(array $row, string $webroot, ?string $listPage): ?string
    {
        $id = $row['id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        $root = rtrim($webroot, '/').'/';
        $dir = $this->jsonNewsListPageDir($listPage, $row);

        return $root.$dir.'news/detail/'.$id.'/';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function jsonNewsListPageDir(?string $listPage, array $row): string
    {
        if ($listPage !== null && array_key_exists($listPage, self::JSON_NEWS_LISTPAGE_DIR)) {
            return self::JSON_NEWS_LISTPAGE_DIR[$listPage];
        }

        if (is_array($row['listpages'] ?? null)) {
            foreach ($row['listpages'] as $page) {
                $key = (string) $page;

                if (array_key_exists($key, self::JSON_NEWS_LISTPAGE_DIR)) {
                    return self::JSON_NEWS_LISTPAGE_DIR[$key];
                }
            }
        }

        return '';
    }
}
