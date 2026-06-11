<?php

namespace App\Services\Collection;

use App\Models\CollectedItem;
use App\Models\CollectionError;
use App\Models\CollectionRun;
use App\Models\WatchSource;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class NewsCollectorService
{
    // 長いPDF保存中でもログ画面に動きが見えるよう、一定件数ごとに進捗を保存する。
    private const PROGRESS_UPDATE_EVERY = 10;

    public function __construct(
        private readonly RssCollector $rssCollector,
        private readonly HtmlCollector $htmlCollector,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly ContentHashService $hashService,
        private readonly PdfAttachmentDownloader $pdfAttachmentDownloader,
    ) {}

    public function collectDue(): CollectionRun
    {
        // スケジュール実行では、有効な会社に紐づく有効な収集先だけを対象にする。
        $sources = WatchSource::query()
            ->with('company')
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->get()
            ->filter(fn (WatchSource $source) => $source->isDue())
            ->values();

        return $this->collectSources($sources);
    }

    public function collectSource(WatchSource $source): CollectionRun
    {
        return $this->collectSources(collect([$source->load('company')]));
    }

    /**
     * 手実行時に先に作成しておく収集ログ。
     *
     * @param  Collection<int, WatchSource>  $sources
     */
    public function createRunForSources(Collection $sources, ?string $message = null): CollectionRun
    {
        return CollectionRun::create([
            'started_at' => now(),
            'status' => 'running',
            'target_count' => $sources->count(),
            'created_count' => 0,
            'updated_count' => 0,
            'error_count' => 0,
            'message' => $message ?? sprintf('対象 %d件の収集を開始しています。', $sources->count()),
        ]);
    }

    /**
     * 収集ログを更新しながら、指定された収集先を順番に処理する。
     *
     * @param  Collection<int, WatchSource>  $sources
     */
    public function collectRun(CollectionRun $run, Collection $sources): CollectionRun
    {
        $created = 0;
        $updated = 0;
        $errors = 0;
        $pdfSaved = 0;
        $sourceNumber = 0;

        $this->updateRunProgress(
            $run,
            $created,
            $updated,
            $errors,
            sprintf('対象 %d件の収集を開始しました。', $sources->count()),
        );

        foreach ($sources as $source) {
            $sourceNumber++;

            try {
                $this->collectOne($source, $run, $sourceNumber, $sources->count(), $created, $updated, $errors, $pdfSaved);
            } catch (Throwable $exception) {
                // 1つの収集先で失敗しても、ほかの収集先まで止めない。
                $errors++;

                CollectionError::create([
                    'collection_run_id' => $run->id,
                    'watch_source_id' => $source->id,
                    'error_type' => class_basename($exception),
                    'error_message' => $exception->getMessage(),
                    'occurred_at' => now(),
                ]);

                $this->updateRunProgress(
                    $run,
                    $created,
                    $updated,
                    $errors,
                    sprintf('[%d/%d] %s: エラーが発生しました。', $sourceNumber, $sources->count(), $source->source_name),
                );
            }
        }

        $run->update([
            'finished_at' => now(),
            'status' => $errors === 0 ? 'success' : ($created > 0 || $updated > 0 ? 'warning' : 'failed'),
            'created_count' => $created,
            'updated_count' => $updated,
            'error_count' => $errors,
            'message' => sprintf('対象 %d件、新規 %d件、更新 %d件、PDF保存 %d件、エラー %d件', $sources->count(), $created, $updated, $pdfSaved, $errors),
        ]);

        return $run->fresh(['errors']);
    }

    /**
     * @return array{http_status:int|null,count:int,items:array<int,array<string,mixed>>,error:string|null}
     */
    public function testSource(WatchSource $source, int $limit = 5): array
    {
        try {
            $result = $this->fetchAndParse($source);

            return [
                'http_status' => $result['http_status'],
                'count' => count($result['items']),
                'items' => array_slice($result['items'], 0, $limit),
                'error' => null,
            ];
        } catch (CollectionHttpException $exception) {
            return [
                'http_status' => $exception->status,
                'count' => 0,
                'items' => [],
                'error' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            return [
                'http_status' => null,
                'count' => 0,
                'items' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  Collection<int, WatchSource>  $sources
     */
    private function collectSources(Collection $sources): CollectionRun
    {
        return $this->collectRun($this->createRunForSources($sources), $sources);
    }

    private function collectOne(
        WatchSource $source,
        CollectionRun $run,
        int $sourceNumber,
        int $sourceTotal,
        int &$created,
        int &$updated,
        int &$errors,
        int &$pdfSaved,
    ): void {
        $result = $this->fetchAndParse($source);
        $itemTotal = count($result['items']);
        $sourcePdfSaved = 0;

        $this->updateRunProgress(
            $run,
            $created,
            $updated,
            $errors,
            sprintf('[%d/%d] %s: %d件を取得しました。', $sourceNumber, $sourceTotal, $source->source_name, $itemTotal),
        );

        foreach ($result['items'] as $index => $item) {
            // 記事レコードは短いトランザクションで保存し、重いPDF取得は外側で実行する。
            $persisted = DB::transaction(fn () => $this->persistItem($source, $item));
            $state = $persisted['state'];

            if ($state === 'created') {
                $created++;
            } elseif ($state === 'updated') {
                $updated++;
            }

            if ($persisted['item'] && $this->pdfAttachmentDownloader->downloadIfPdf($persisted['item'])) {
                // PDF保存数は今回の実行で新しく保存できた件数だけを数える。
                $pdfSaved++;
                $sourcePdfSaved++;
            }

            $processed = $index + 1;

            if ($this->shouldUpdateProgress($processed, $itemTotal)) {
                $this->updateRunProgress(
                    $run,
                    $created,
                    $updated,
                    $errors,
                    sprintf('[%d/%d] %s: %d/%d件処理中（PDF保存 %d件）', $sourceNumber, $sourceTotal, $source->source_name, $processed, $itemTotal, $sourcePdfSaved),
                );
            }
        }

        $source->forceFill(['last_crawled_at' => now()])->save();

        $this->updateRunProgress(
            $run,
            $created,
            $updated,
            $errors,
            sprintf('[%d/%d] %s: 完了 %d件（PDF保存 %d件）', $sourceNumber, $sourceTotal, $source->source_name, $itemTotal, $sourcePdfSaved),
        );
    }

    /**
     * 収集先URLを取得し、設定された方式に応じてRSSまたはHTMLとして解析する。
     *
     * @return array{http_status:int,items:array<int,array<string,mixed>>}
     *
     * @throws ConnectionException
     */
    private function fetchAndParse(WatchSource $source): array
    {
        $response = Http::timeout(20)
            ->retry(1, 500)
            ->withUserAgent('PeerScope/0.1 (+internal news monitoring)')
            ->accept('application/rss+xml, application/xml, text/xml, text/html;q=0.9, */*;q=0.8')
            ->get($source->source_url);

        if ($response->status() >= 400) {
            throw new CollectionHttpException($response->status(), 'HTTP '.$response->status().' で取得に失敗しました。');
        }

        $items = match ($source->source_type) {
            'auto' => $this->parseAutoSource($source, $response),
            'rss' => $this->rssCollector->parse($response->body(), $source->source_url),
            'html' => $this->htmlCollector->parse($response->body(), $source),
            default => throw new \RuntimeException('未対応の収集方式です: '.$source->source_type),
        };

        return [
            'http_status' => $response->status(),
            'items' => $items,
        ];
    }

    /**
     * 自動判定は、確度の高いRSS/Atomを優先し、見つからない場合だけHTML一覧の推定へ進む。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseAutoSource(WatchSource $source, Response $response): array
    {
        $body = $response->body();

        if ($this->looksLikeFeed($body, $response->header('Content-Type'))) {
            $items = $this->tryParseRss($body, $source->source_url);

            if ($items !== []) {
                return $items;
            }
        }

        $feedUrl = $this->discoverFeedUrl($body, $source->source_url);

        if ($feedUrl) {
            $feedResponse = Http::timeout(20)
                ->retry(1, 500)
                ->withUserAgent('PeerScope/0.1 (+internal news monitoring)')
                ->accept('application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.8')
                ->get($feedUrl);

            if ($feedResponse->status() < 400) {
                $items = $this->tryParseRss($feedResponse->body(), $feedUrl);

                if ($items !== []) {
                    return $items;
                }
            }
        }

        $items = $this->htmlCollector->parseAuto($body, $source);

        if ($items !== []) {
            return $items;
        }

        throw new \RuntimeException('自動判定で取得対象を見つけられませんでした。HTML詳細指定で登録してください。');
    }

    private function looksLikeFeed(string $body, ?string $contentType): bool
    {
        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'rss+xml')
            || str_contains($contentType, 'atom+xml')
            || str_contains($contentType, 'text/xml')
            || str_contains($contentType, 'application/xml')) {
            return true;
        }

        $head = strtolower(substr(ltrim($body), 0, 500));

        return str_contains($head, '<rss')
            || str_contains($head, '<feed')
            || str_contains($head, '<rdf:rdf');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tryParseRss(string $xml, string $baseUrl): array
    {
        try {
            return $this->rssCollector->parse($xml, $baseUrl);
        } catch (Throwable) {
            return [];
        }
    }

    private function discoverFeedUrl(string $html, string $baseUrl): ?string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//link[@href]');

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $rel = strtolower($node->getAttribute('rel'));
            $type = strtolower($node->getAttribute('type'));
            $href = trim($node->getAttribute('href'));

            if (! str_contains($rel, 'alternate') || $href === '') {
                continue;
            }

            $isFeed = str_contains($type, 'rss+xml')
                || str_contains($type, 'atom+xml')
                || str_contains($type, 'xml')
                || preg_match('/(?:rss|atom|feed)[^\/]*\.xml$/i', $href);

            if ($isFeed) {
                return $this->urlNormalizer->normalize($href, $baseUrl);
            }
        }

        return null;
    }

    /**
     * URLハッシュで同一記事を判定し、URLが変わった同内容の記事は内容ハッシュで重複を避ける。
     *
     * @param  array<string, mixed>  $item
     */
    private function persistItem(WatchSource $source, array $item): array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $normalizedUrl = $this->urlNormalizer->normalize((string) ($item['url'] ?? ''), $source->source_url);

        if ($title === '' || $normalizedUrl === null) {
            return ['state' => 'skipped', 'item' => null];
        }

        $summary = $this->trimText($item['summary'] ?? null);
        $bodyText = $this->trimText($item['body_text'] ?? null);
        $publishedAt = $item['published_at'] ?? null;
        $contentHash = $this->hashService->contentHash($title, $publishedAt, $summary, $bodyText);
        $urlHash = $this->hashService->urlHash($normalizedUrl);

        $payload = [
            'company_id' => $source->company_id,
            'watch_source_id' => $source->id,
            'title' => Str::limit($title, 1000, ''),
            'url' => Str::limit($normalizedUrl, 2048, ''),
            'url_hash' => $urlHash,
            'content_hash' => $contentHash,
            'published_at' => $publishedAt,
            'summary' => $summary,
            'body_text' => $bodyText,
            'category' => $this->trimText($item['category'] ?? $source->source_name, 100),
        ];

        $existing = CollectedItem::query()->where('url_hash', $urlHash)->first();

        if ($existing) {
            $existing->fill($payload);

            if ($existing->isDirty()) {
                $existing->save();

                return ['state' => 'updated', 'item' => $existing->fresh()];
            }

            return ['state' => 'skipped', 'item' => $existing];
        }

        $sameContent = CollectedItem::query()->where('content_hash', $contentHash)->first();

        if ($sameContent) {
            return ['state' => 'skipped', 'item' => $sameContent];
        }

        $created = CollectedItem::create($payload + [
            'detected_at' => now(),
        ]);

        return ['state' => 'created', 'item' => $created];
    }

    private function trimText(mixed $value, int $limit = 65000): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $limit, '');
    }

    private function shouldUpdateProgress(int $processed, int $total): bool
    {
        // 初回・一定件数ごと・最後の3点で更新して、DB書き込みを増やしすぎない。
        return $processed === 1
            || $processed % self::PROGRESS_UPDATE_EVERY === 0
            || $processed === $total;
    }

    private function updateRunProgress(CollectionRun $run, int $created, int $updated, int $errors, string $message): void
    {
        $run->forceFill([
            'created_count' => $created,
            'updated_count' => $updated,
            'error_count' => $errors,
            'message' => $message,
        ])->save();
    }
}
