<?php

namespace App\Services\Ai;

use App\Models\CollectedItem;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ArticleTextExtractor
{
    /**
     * AI要約へ渡すため、リンク先ページや保存済みPDFから追加本文を抽出する。
     *
     * @return array{web:?string,pdf:?string}
     */
    public function extract(CollectedItem $item): array
    {
        return [
            'web' => $this->extractWebText($item),
            'pdf' => $this->extractPdfText($item),
        ];
    }

    private function extractWebText(CollectedItem $item): ?string
    {
        if ($this->isPdfUrl($item->url)) {
            return null;
        }

        try {
            $response = Http::connectTimeout((int) config('services.ollama.connect_timeout', 5))
                ->timeout((int) config('services.ollama.fetch_timeout', 20))
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->withUserAgent('PeerScope/0.1 (+internal AI summary)')
                ->accept('text/html,application/xhtml+xml;q=0.9,*/*;q=0.5')
                ->get($item->url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        $html = $response->body();

        if (! str_contains($contentType, 'html') && ! str_contains($html, '<html')) {
            return null;
        }

        return $this->extractMainTextFromHtml($html);
    }

    private function extractPdfText(CollectedItem $item): ?string
    {
        if (! $item->hasDownloadedPdf() || ! Storage::disk('local')->exists($item->pdf_storage_path)) {
            return null;
        }

        $path = Storage::disk('local')->path($item->pdf_storage_path);

        return $this->extractWithPdfToText($path)
            ?: $this->extractWithPdfCMap($path)
            ?: $this->extractWithPdfParser($path);
    }

    private function extractMainTextFromHtml(string $html): ?string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);

        // ナビゲーションや装飾を外して、本文候補だけを残す。
        foreach ($this->nodes($xpath, '//script|//style|//noscript|//svg|//nav|//header|//footer|//form|//aside') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $queries = [
            '//main',
            '//article',
            '//*[@id="main" or @id="content" or @id="contents" or @id="article" or @id="news"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " detail ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " news ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
            '//body',
        ];

        $best = '';

        foreach ($queries as $query) {
            foreach ($this->nodes($xpath, $query) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                $text = $this->normalizeText($node->textContent);

                if (mb_strlen($text) > mb_strlen($best)) {
                    $best = $text;
                }
            }

            if (mb_strlen($best) >= 300) {
                break;
            }
        }

        return $best !== '' ? $best : null;
    }

    private function extractWithPdfToText(string $path): ?string
    {
        $binary = (new ExecutableFinder)->find('pdftotext');

        if (! $binary) {
            return null;
        }

        $output = tempnam(sys_get_temp_dir(), 'peerscope_pdf_');

        if (! is_string($output)) {
            return null;
        }

        try {
            $process = new Process([$binary, '-enc', 'UTF-8', '-layout', $path, $output]);
            $process->setTimeout(45);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($output)) {
                return null;
            }

            $text = $this->normalizeText((string) file_get_contents($output));

            return $text !== '' ? $text : null;
        } finally {
            @unlink($output);
        }
    }

    private function extractWithPdfParser(string $path): ?string
    {
        try {
            $pdf = (new Parser)->parseFile($path);

            $text = $this->normalizeText($pdf->getText());

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractWithPdfCMap(string $path): ?string
    {
        $data = @file_get_contents($path);

        if (! is_string($data) || $data === '') {
            return null;
        }

        $objects = $this->pdfObjects($data);
        $fontMaps = $this->pdfFontMaps($objects);

        if ($fontMaps === []) {
            return null;
        }

        $text = '';

        foreach ($objects as $body) {
            $stream = $this->pdfStream($body);

            if ($stream === null || ! str_contains($stream, 'Tj') && ! str_contains($stream, 'TJ')) {
                continue;
            }

            $text .= "\n".$this->decodePdfContentStream($stream, $fontMaps);
        }

        $text = $this->normalizeText($text);

        return $text !== '' ? $text : null;
    }

    /**
     * @return array<int, string>
     */
    private function pdfObjects(string $data): array
    {
        preg_match_all('/(\d+)\s+\d+\s+obj(.*?)endobj/s', $data, $matches, PREG_SET_ORDER);

        $objects = [];

        foreach ($matches as $match) {
            $objects[(int) $match[1]] = $match[2];
        }

        return $objects;
    }

    /**
     * @param  array<int, string>  $objects
     * @return array<string, array<string, string>>
     */
    private function pdfFontMaps(array $objects): array
    {
        $maps = [];

        foreach ($objects as $body) {
            preg_match_all('/\/([A-Za-z0-9_.-]+)\s+(\d+)\s+\d+\s+R/', $body, $fontRefs, PREG_SET_ORDER);

            foreach ($fontRefs as $fontRef) {
                $fontObject = $objects[(int) $fontRef[2]] ?? null;

                if (! is_string($fontObject) || ! preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fontObject, $toUnicode)) {
                    continue;
                }

                $cmapObject = $objects[(int) $toUnicode[1]] ?? null;

                if (! is_string($cmapObject)) {
                    continue;
                }

                $map = $this->parsePdfToUnicodeMap($this->pdfStream($cmapObject) ?? $cmapObject);

                if ($map !== []) {
                    $maps[$fontRef[1]] = $map;
                }
            }
        }

        return $maps;
    }

    /**
     * @return array<string, string>
     */
    private function parsePdfToUnicodeMap(string $cmap): array
    {
        $map = [];

        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $charBlocks);

        foreach ($charBlocks[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $block, $entries, PREG_SET_ORDER);

            foreach ($entries as $entry) {
                $map[strtoupper($entry[1])] = $this->utf16BeHexToUtf8($entry[2]);
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $rangeBlocks);

        foreach ($rangeBlocks[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+(?:<([0-9A-Fa-f]+)>|\[(.*?)\])/', $block, $entries, PREG_SET_ORDER);

            foreach ($entries as $entry) {
                $start = hexdec($entry[1]);
                $end = hexdec($entry[2]);

                if ($start > $end || ($end - $start) > 1000) {
                    continue;
                }

                if (($entry[3] ?? '') !== '') {
                    $unicode = hexdec($entry[3]);

                    for ($code = $start; $code <= $end; $code++, $unicode++) {
                        $map[$this->pdfCodeKey($code, strlen($entry[1]))] = mb_chr($unicode, 'UTF-8');
                    }

                    continue;
                }

                preg_match_all('/<([0-9A-Fa-f]+)>/', $entry[4] ?? '', $unicodeEntries);

                foreach ($unicodeEntries[1] as $index => $unicodeHex) {
                    $map[$this->pdfCodeKey($start + $index, strlen($entry[1]))] = $this->utf16BeHexToUtf8($unicodeHex);
                }
            }
        }

        return $map;
    }

    private function pdfStream(string $objectBody): ?string
    {
        if (! preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $objectBody, $match)) {
            return null;
        }

        $stream = $match[1];

        if (str_contains($objectBody, 'FlateDecode')) {
            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = @gzdecode($stream);
            }

            if ($decoded !== false) {
                $stream = $decoded;
            }
        }

        return $stream;
    }

    /**
     * @param  array<string, array<string, string>>  $fontMaps
     */
    private function decodePdfContentStream(string $stream, array $fontMaps): string
    {
        $currentFont = null;
        $text = '';

        preg_match_all('/\/([A-Za-z0-9_.-]+)\s+[\d.]+\s+Tf|<([0-9A-Fa-f]+)>\s*Tj|\[(.*?)\]\s*TJ|\((.*?)\)\s*Tj/s', $stream, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            if (($token[1] ?? '') !== '') {
                $currentFont = $token[1];

                continue;
            }

            if (($token[2] ?? '') !== '') {
                $text .= $this->decodePdfHexText($token[2], $fontMaps[$currentFont] ?? []);

                continue;
            }

            if (($token[3] ?? '') !== '') {
                preg_match_all('/<([0-9A-Fa-f]+)>/', $token[3], $hexStrings);

                foreach ($hexStrings[1] as $hex) {
                    $text .= $this->decodePdfHexText($hex, $fontMaps[$currentFont] ?? []);
                }

                continue;
            }

            if (($token[4] ?? '') !== '') {
                $text .= $this->decodePdfLiteralText($token[4]);

                continue;
            }

        }

        return $text;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function decodePdfHexText(string $hex, array $map): string
    {
        $text = '';

        foreach (str_split($hex, 4) as $chunk) {
            if (strlen($chunk) !== 4) {
                continue;
            }

            $text .= $map[strtoupper($chunk)] ?? '';
        }

        return $text;
    }

    private function decodePdfLiteralText(string $value): string
    {
        $value = preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $value) ?? $value;

        return preg_replace('/\\\\\d{1,3}/', ' ', $value) ?? $value;
    }

    private function utf16BeHexToUtf8(string $hex): string
    {
        $bytes = @hex2bin($hex);

        if (! is_string($bytes)) {
            return '';
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    }

    private function pdfCodeKey(int $code, int $hexLength): string
    {
        return strtoupper(str_pad(dechex($code), $hexLength, '0', STR_PAD_LEFT));
    }

    private function normalizeText(string $text): string
    {
        if ($this->hasTooManyControlCharacters($text)) {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/u", "\n", $text) ?? $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t\x{3000}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? $text;
        $text = trim($text);

        if (! $this->looksReadable($text)) {
            return '';
        }

        return Str::limit($text, (int) config('services.ollama.extracted_text_chars', 12000), '');
    }

    private function hasTooManyControlCharacters(string $text): bool
    {
        $length = max(mb_strlen($text), 1);
        $count = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text);

        return $count > 10 && ($count / $length) > 0.01;
    }

    private function looksReadable(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? $text;
        $length = mb_strlen($compact);

        if ($length < 20) {
            return false;
        }

        $readable = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}A-Za-z0-9。、，,.・:：;；()（）「」『』!?！？ー%％¥￥\-]/u', $compact);

        return ($readable / $length) >= 0.45;
    }

    private function isPdfUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower($path), '.pdf');
    }

    /**
     * DOMNodeListを配列化して、削除処理中にリストが変化しても安全に扱う。
     *
     * @return array<int, \DOMNode>
     */
    private function nodes(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);

        return $nodes ? iterator_to_array($nodes) : [];
    }
}
