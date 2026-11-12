<?php

namespace Formaku\Cms\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class DeploymentReceipt
{
    private const ARCHIVE_LIMIT = 16384;

    private const RECEIPT_LIMIT = 4096;

    private const FILENAME = 'formaku-deployment.json';

    public function read(string $repository, string $token, int $runId, string $release, string $image): array
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'Deployment receipts require the PHP ZIP extension.');

        $actions = 'https://api.github.com/repos/'.$repository.'/actions';
        $runResponse = $this->github($token, $actions.'/runs/'.$runId);
        abort_if($runResponse->notFound(), 404);
        abort_unless($runResponse->successful(), 502, 'GitHub deployment status is temporarily unavailable.');
        $run = $runResponse->json();

        abort_unless(
            is_array($run)
            && ($run['id'] ?? null) === $runId
            && ($run['event'] ?? null) === 'workflow_dispatch'
            && ($run['head_branch'] ?? null) === 'main'
            && ($run['path'] ?? null) === '.github/workflows/deploy.yml'
            && ($run['display_title'] ?? null) === 'Formaku deploy '.$release,
            404,
        );

        $attempt = $run['run_attempt'] ?? null;
        $status = $run['status'] ?? null;
        abort_unless(
            is_int($attempt) && $attempt > 0
            && in_array($status, ['queued', 'requested', 'waiting', 'pending', 'in_progress', 'completed'], true),
            502,
            'GitHub returned an invalid deployment status.',
        );
        $result = [
            'state' => 'pending',
            'run_id' => $runId,
            'run_attempt' => $attempt,
            'run_url' => 'https://github.com/'.$repository.'/actions/runs/'.$runId.'/attempts/'.$attempt,
            'receipt' => null,
        ];
        if ($status !== 'completed') {
            return $result;
        }

        $receipt = $this->readArtifact($actions, $token, $runId, $attempt, $release, $image);

        return array_replace($result, [
            'state' => $receipt === null ? 'unavailable' : 'verified',
            'receipt' => $receipt,
        ]);
    }

    private function readArtifact(string $actions, string $token, int $runId, int $attempt, string $release, string $image): ?array
    {
        $name = 'formaku-deployment-'.$attempt;
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

        return $this->validateReceipt($this->readZip($bytes), $release, $image);
    }

    private function github(string $token, string $url, array $query = []): Response
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

    private function readZip(string $bytes): mixed
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
                if ($zip->numFiles !== 1 || $file === false || $file['name'] !== self::FILENAME
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

    private function validateReceipt(mixed $receipt, string $release, string $image): ?array
    {
        $exit = $receipt['ssh_exit_code'] ?? null;
        if (! is_array($receipt) || ($receipt['format'] ?? null) !== 1 || ($receipt['release'] ?? null) !== $release
            || ($receipt['image'] ?? null) !== $image || ! is_int($exit) || $exit < 0 || $exit > 255
            || ! array_key_exists('host_status', $receipt)) {
            return null;
        }

        $host = $receipt['host_status'];
        $hostStatus = null;
        if ($host !== null) {
            if (! is_array($host) || ! array_key_exists('rollback', $host) || ! array_key_exists('backup', $host)) {
                return null;
            }
            $state = $host['state'] ?? null;
            $rollback = $host['rollback'];
            $backup = $host['backup'];
            if (! in_array($state, ['deploying', 'deployed', 'failed'], true)
                || ($state === 'failed' && ! in_array($rollback, ['not_attempted', 'restored', 'failed', 'unavailable'], true))
                || ($state !== 'failed' && $rollback !== null)
                || ($backup !== null && ($state !== 'failed' || $backup !== $release))) {
                return null;
            }
            $hostStatus = ['state' => $state, 'rollback' => $rollback, 'backup' => $backup];
        }

        return ['format' => 1, 'release' => $release, 'image' => $image, 'ssh_exit_code' => $exit, 'host_status' => $hostStatus];
    }
}
