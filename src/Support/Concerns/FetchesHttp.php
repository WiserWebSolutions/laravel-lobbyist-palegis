<?php

namespace WiserWebSolutions\LaravelPalegis\Support\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WiserWebSolutions\LaravelPalegis\Exceptions\PalegisException;

/**
 * Shared HTTP GET helper for palegis.us requests, used by both RSS feed
 * fetching and the Bill History bulk download.
 */
trait FetchesHttp
{
    /**
     * Perform a GET request and return the raw body, throwing an informative
     * PalegisException (including the attempted URL) on any failure.
     *
     * @param  string|null  $notFoundHint  Extra guidance to include on a 404
     *
     * @throws PalegisException
     */
    protected function fetchBody(string $url, ?string $notFoundHint = null): string
    {
        Log::debug('palegis request: '.$url);

        try {
            $response = Http::timeout($this->request['timeout'] ?? 30)
                ->retry($this->request['retry_times'] ?? 2, $this->request['retry_sleep_ms'] ?? 200)
                ->get($url);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw PalegisException::requestFailed($url, $status, $this->hintFor($status, $notFoundHint), $e);
        } catch (ConnectionException $e) {
            throw PalegisException::requestFailed($url, null, 'Could not connect to palegis.us: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw PalegisException::requestFailed($url, $response->status(), $this->hintFor($response->status(), $notFoundHint));
        }

        return $response->body();
    }

    private function hintFor(?int $status, ?string $notFoundHint): ?string
    {
        return ($status === 404 && $notFoundHint !== null) ? $notFoundHint : null;
    }
}
