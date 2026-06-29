<?php

namespace App\Services\Ai;

use App\Models\CollectedItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OllamaSummaryService
{
    public function __construct(
        private readonly ArticleTextExtractor $articleTextExtractor,
    ) {}

    public function generate(CollectedItem $item): ?string
    {
        $source = $this->sourceMaterial($item);
        $sourceText = $source['text'];

        if ($sourceText === '') {
            return null;
        }

        if (! $source['has_content']) {
            throw new RuntimeException('リンク先本文やPDF本文を抽出できず、要約に必要な情報が不足しています。元URLまたはPDFを確認してください。');
        }

        $baseUrl = rtrim((string) config('services.ollama.base_url'), '/');
        $model = $this->model();
        $timeout = (int) config('services.ollama.timeout', 180);

        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }

        $response = Http::timeout($timeout)
            ->connectTimeout((int) config('services.ollama.connect_timeout', 5))
            ->post($baseUrl.'/api/generate', [
                'model' => $model,
                'prompt' => $this->prompt($item, $sourceText),
                'stream' => false,
                'think' => $this->thinkingEnabled(),
                'keep_alive' => (string) config('services.ollama.keep_alive', '5m'),
                'options' => [
                    'temperature' => 0.2,
                    'num_predict' => (int) config('services.ollama.num_predict', 280),
                ],
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('Ollama APIがHTTP '.$response->status().' を返しました。');
        }

        $summary = $this->cleanSummary((string) $response->json('response'));

        if ($summary === '') {
            throw new RuntimeException('Ollamaから空の要約が返りました。');
        }

        if (! $this->isUsableSummary($summary)) {
            throw new RuntimeException('Ollamaから要約として扱えない短い応答が返りました。本文抽出結果またはモデル設定を確認してください。');
        }

        return $summary;
    }

    public function model(): string
    {
        return (string) config('services.ollama.model', 'gemma4:e2b');
    }

    /**
     * 保存済み情報に加え、リンク先から抽出した本文もAIへ渡す。
     *
     * @return array{text:string,has_content:bool}
     */
    private function sourceMaterial(CollectedItem $item): array
    {
        $extracted = $this->articleTextExtractor->extract($item);
        $parts = array_filter([
            'タイトル: '.$item->title,
            $item->company?->name ? '会社: '.$item->company->name : null,
            $item->published_at ? '掲載日時: '.$item->published_at->format('Y-m-d H:i') : null,
            $item->summary ? "収集時要約:\n".$item->summary : null,
            $item->body_text ? "本文:\n".$item->body_text : null,
            $extracted['web'] ? "リンク先本文（抽出）:\n".$extracted['web'] : null,
            $extracted['pdf'] ? "PDF本文（抽出）:\n".$extracted['pdf'] : null,
            'URL: '.$item->url,
        ]);

        $hasContent = $this->hasUsefulContent($item->summary)
            || $this->hasUsefulContent($item->body_text)
            || $this->hasUsefulContent($extracted['web'])
            || $this->hasUsefulContent($extracted['pdf']);

        return [
            'text' => trim(Str::limit(implode("\n\n", $parts), (int) config('services.ollama.max_input_chars', 12000), '')),
            'has_content' => $hasContent,
        ];
    }

    private function prompt(CollectedItem $item, string $sourceText): string
    {
        return <<<PROMPT
あなたは社内で新着情報を確認する担当者向けに、Web記事やPDF告知を要約するアシスタントです。
以下の記事情報を読み、担当者が元資料を開くべきか判断できる密度で日本語要約を作成してください。

出力形式:
概要: 記事全体で何を知らせているか
主な内容: 変更点、制度、商品、障害、注意喚起などの具体情報
対象・日付: 対象者、開始日、期限、停止時間など。記載がなければ「記載なし」
確認事項: 利用者や担当者が確認・対応すべきこと。記載がなければ「記載なし」

条件:
- 4〜7行で、1行ごとに内容のある文にする
- 原文にある固有名詞、日付、対象、手続、金額、時間、連絡先は省略しすぎない
- 原文にない推測や補足はしない
- 「お知らせ」「発行しました」だけで終えず、本文から読み取れる中身を書く
- URLや定型文は繰り返さない
- 「はい」「承知しました」「要約します」などの応答文は含めない

記事情報:
{$sourceText}
PROMPT;
    }

    private function cleanSummary(string $summary): string
    {
        $summary = trim($summary);
        $summary = preg_replace('/^```(?:text|markdown)?/i', '', $summary) ?? $summary;
        $summary = preg_replace('/```$/', '', $summary) ?? $summary;
        $summary = preg_replace("/\r\n|\r/", "\n", $summary) ?? $summary;
        $summary = preg_replace("/\n{3,}/", "\n\n", $summary) ?? $summary;
        $summary = collect(explode("\n", $summary))
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '')
            ->reject(fn (string $line): bool => (bool) preg_match('/^(はい|承知しました|承知いたしました|かしこまりました|以下|要約します)/u', $line))
            ->map(fn (string $line): string => trim((string) preg_replace('/^(?:[-*・]|\d+[.)、])\s*/u', '', $line)))
            ->implode("\n");

        return trim(Str::limit($summary, 2000, ''));
    }

    private function hasUsefulContent(?string $text): bool
    {
        if (! filled($text)) {
            return false;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($normalized) >= 80;
    }

    private function isUsableSummary(string $summary): bool
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $summary));

        if (mb_strlen($normalized) < 20) {
            return false;
        }

        if (! preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $normalized)) {
            return false;
        }

        return ! preg_match('/^\[?[A-Za-z]{1,20}\]?$/', $normalized);
    }

    private function thinkingEnabled(): bool
    {
        return filter_var(config('services.ollama.think', false), FILTER_VALIDATE_BOOLEAN);
    }
}
