<?php

namespace Formaku\Cms\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class GitHubActionsArtifact
{
    private const ARCHIVE_LIMIT = 16384;

    private const RECEIPT_LIMIT = 4096;

    public static function validRepository(mixed $repository): bool
    {
        return is_string($repository)
            && preg_match('~\A(?!\.{1,2}/)[A-Za-z0-9_.-]+/(?!\.{1,2}\z)[A-Za-z0-9_.-]+\z~', $repository) === 1;
    }

    public function read(string $actions, string $token, int $runId, string $name, string $filename, bool $requireDigest = false): mixed
    {
        $response = $this->github($token, $actions.'/runs/'.$runId.'/artifacts', ['name' => $name, 'per_page' => 2]);
        abort_unless($response->successful(), 502, 'GitHub deployment artifacts are temporarily unavailable.');
        $result = $response->json();
        if (($result['total_count'] ?? null) !== 1 || ! is_array($result['artifacts'] ?? null)
            || ! array_is_list($result['artifacts']) || count($result['artifacts']) !== 1) {
            return null;
        }

        $artifact = $result['artifacts'][0];
        $artifactId = $artifact['id'] ?? null;
        $size = $artifact['size_in_bytes'] ?? null;
        $digest = $artifact['digest'] ?? null;
        if (($artifact['name'] ?? null) !== $name || ($artifact['expired'] ?? null) !== false
            || ! is_int($artifactId) || $artifactId < 1
            || ! is_int($size) || $size < 1 || $size > self::ARCHIVE_LIMIT
            || ($requireDigest && $digest === null)
            || ($digest !== null && (! is_string($digest) || ! preg_match('/\Asha256:[a-f0-9]{64}\z/', $digest)))
            || (isset($artifact['workflow_run']) && ($artifact['workflow_run']['id'] ?? null) !== $runId)) {
            return null;
        }

        $redirect = $this->github($token, $actions.'/artifacts/'.$artifactId.'/zip');
        if ($redirect->status() !== 302) {
            return null;
        }
        $url = $redirect->header('Location');
        if (! $this->allowedDownload($url)) {
            return null;
        }
        $bytes = $this->download($url);
        if ($bytes === null || ($digest !== null && ! hash_equals($digest, 'sha256:'.hash('sha256', $bytes)))) {
            return null;
        }

        return $this->readZip($bytes, $filename);
    }

    public function github(string $token, string $url, array $query = []): Response
    {
        try {
            return Http::withToken($token)->accept('application/vnd.github+json')
                ->withoutRedirecting()->connectTimeout(3)->timeout(5)->get($url, $query);
        } catch (ConnectionException) {
            abort(502, 'GitHub deployment status is temporarily unavailable.');
        }
    }

    private function allowedDownload(string $url): bool
    {
        if (strlen($url) > 8192 || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        return $host === 'results-receiver.actions.githubusercontent.com'
            || preg_match('/\A[a-z0-9]+\.blob\.core\.windows\.net\z/', $host) === 1;
    }

    private function download(string $url): ?string
    {
        try {
            $deadline = hrtime(true) + 5_000_000_000;
            $response = Http::withoutRedirecting()->connectTimeout(3)->timeout(5)->withOptions([
                'stream' => true,
                'read_timeout' => 5,
                'decode_content' => false,
            ])->get($url);
            $body = $response->toPsrResponse()->getBody();
            try {
                if ($response->status() !== 200 || ($body->getSize() ?? 0) > self::ARCHIVE_LIMIT) {
                    return null;
                }
                $bytes = '';
                while (! $body->eof() && strlen($bytes) <= self::ARCHIVE_LIMIT) {
                    if (hrtime(true) >= $deadline) {
                        return null;
                    }
                    $chunk = $body->read(self::ARCHIVE_LIMIT + 1 - strlen($bytes));
                    if ($chunk === '') {
                        break;
                    }
                    $bytes .= $chunk;
                }

                return $body->eof() && strlen($bytes) <= self::ARCHIVE_LIMIT ? $bytes : null;
            } finally {
                $body->close();
            }
        } catch (ConnectionException|RuntimeException) {
            return null;
        }
    }

    private function readZip(string $bytes, string $filename): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'formaku-receipt-');
        if ($path === false) {
            return null;
        }
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                return null;
            }
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                return null;
            }
            try {
                $file = $zip->statIndex(0);
                if ($zip->numFiles !== 1 || $file === false || $file['name'] !== $filename
                    || $file['size'] < 1 || $file['size'] > self::RECEIPT_LIMIT) {
                    return null;
                }
                $json = $zip->getFromIndex(0, self::RECEIPT_LIMIT + 1);

                return is_string($json) && strlen($json) === $file['size'] ? json_decode($json, true, 8) : null;
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
    }
}
