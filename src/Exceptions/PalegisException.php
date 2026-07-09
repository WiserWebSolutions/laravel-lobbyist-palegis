<?php

namespace WiserWebSolutions\LaravelPalegis\Exceptions;

use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

class PalegisException extends LobbyistException
{
    public static function requestError(string $message): self
    {
        return new self("Palegis request error: {$message}");
    }

    public static function feedError(string $message): self
    {
        return new self("Palegis feed error: {$message}");
    }
}
