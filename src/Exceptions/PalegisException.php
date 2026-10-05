<?php

namespace WiserWebSolutions\LaravelPalegis\Exceptions;

use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

class PalegisException extends LobbyistException
{
    public static function requestFailed(string $url, ?int $status = null, ?string $detail = null, ?\Throwable $previous = null): static
    {
        $exception = parent::requestFailed($url, $status, $detail, $previous);
        $exception->code = $status ?? 0;

        return $exception;
    }

    public static function requestError(string $message): self
    {
        return new self("Palegis request error: {$message}");
    }

    public static function feedError(string $message): self
    {
        return new self("Palegis feed error: {$message}");
    }
}
