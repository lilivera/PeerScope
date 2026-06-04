<?php

namespace App\Services\Collection;

use App\Models\CollectedItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PdfAttachmentDownloader
{
    public function downloadIfPdf(CollectedItem $item): bool
    {
        // 現状はURLパスが.pdfで終わるリンクだけを添付保存の対象にする。
        if (! $this->isPdfUrl($item->url)) {
            return false;
        }

        // 既に保存済みなら再ダウンロードしない。
        if ($item->pdf_storage_path && Storage::disk('local')->exists($item->pdf_storage_path)) {
            return false;
        }

        try {
            $response = Http::connectTimeout(15)
                ->timeout(180)
                ->retry(1, 1000)
                ->withUserAgent('PeerScope/0.1 (+internal PDF archival)')
                ->accept('application/pdf, application/octet-stream;q=0.9, */*;q=0.5')
                ->get($item->url);
        } catch (Throwable) {
            // PDF取得失敗は記事収集全体を止めず、後続の記事を優先する。
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));

        // Content-Typeが曖昧なサーバーもあるため、PDFシグネチャも確認する。
        if (! str_contains($contentType, 'pdf') && ! str_starts_with($body, '%PDF')) {
            return false;
        }

        // privateディスク配下に保存し、認証済みダウンロード経路だけで配布する。
        $path = sprintf('pdfs/%d/%s.pdf', $item->company_id, $item->url_hash);

        Storage::disk('local')->put($path, $body);

        $item->forceFill([
            'pdf_storage_path' => $path,
            'pdf_original_filename' => $this->filenameFromUrl($item->url, $item->title),
            'pdf_mime_type' => str_contains($contentType, 'pdf') ? 'application/pdf' : ($contentType ?: 'application/pdf'),
            'pdf_file_size' => strlen($body),
            'pdf_downloaded_at' => now(),
        ])->save();

        return true;
    }

    private function isPdfUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower($path), '.pdf');
    }

    private function filenameFromUrl(string $url, string $title): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';
        $basename = rawurldecode($basename);

        if (strtolower(pathinfo($basename, PATHINFO_EXTENSION)) === 'pdf') {
            return $basename;
        }

        $safeTitle = Str::of($title)
            ->replaceMatches('/[\\\\\/:*?"<>|]+/u', '_')
            ->limit(120, '')
            ->trim()
            ->toString();

        return ($safeTitle !== '' ? $safeTitle : 'document').'.pdf';
    }
}
