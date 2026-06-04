<?php

namespace App\Services\Collection;

class UrlNormalizer
{
    public function normalize(?string $url, ?string $baseUrl = null): ?string
    {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // 収集対象にならない疑似リンクや空リンクは保存しない。
        if ($url === '' || preg_match('/^(javascript|mailto|tel):/i', $url)) {
            return null;
        }

        $absolute = $this->toAbsoluteUrl($url, $baseUrl);
        $parts = parse_url($absolute);

        if ($parts === false || empty($parts['host'])) {
            return $absolute;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $this->normalizePath($parts['path'] ?? '/');
        $query = $this->cleanQuery($parts['query'] ?? '');

        return $scheme.'://'.$host.$port.$path.($query !== '' ? '?'.$query : '');
    }

    private function toAbsoluteUrl(string $url, ?string $baseUrl): string
    {
        // 既に絶対URLなら、後段の正規化へそのまま渡す。
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }

        $baseParts = $baseUrl ? parse_url($baseUrl) : false;
        if ($baseParts === false || empty($baseParts['host'])) {
            return $url;
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $origin = $scheme.'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        // //example.com/path 形式は、基準URLのスキームを補う。
        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath).'/';

        if (str_starts_with($url, '?')) {
            return $origin.$directory.$url;
        }

        return $origin.$directory.$url;
    }

    private function normalizePath(string $path): string
    {
        // ./ や ../ を畳み、同じ記事が別URLとして保存されることを避ける。
        $leadingSlash = str_starts_with($path, '/');
        $trailingSlash = str_ends_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = ($leadingSlash ? '/' : '').implode('/', $segments);

        if ($trailingSlash && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized === '' ? '/' : $normalized;
    }

    private function cleanQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $key = strtolower(urldecode(explode('=', $pair, 2)[0]));

            // 広告・計測パラメータは重複判定の邪魔になるため除外する。
            if (str_starts_with($key, 'utm_') || in_array($key, [
                'fbclid',
                'gclid',
                'yclid',
                'mc_cid',
                'mc_eid',
                '_hsenc',
                '_hsmi',
            ], true)) {
                continue;
            }

            $kept[] = $pair;
        }

        return implode('&', $kept);
    }
}
