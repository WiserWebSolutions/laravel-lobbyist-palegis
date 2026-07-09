<?php

namespace WiserWebSolutions\LaravelPalegis\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\LaravelPalegis\LaravelPalegisServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LobbyistServiceProvider::class,
            LaravelPalegisServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('palegis.cache.enabled', false);
    }

    /**
     * Build a minimal RSS document with the given items.
     *
     * @param  array<int, array{title?: string, link?: string, description?: string, guid?: string}>  $items
     */
    protected function rssXml(array $items, string $channelTitle = 'PA Feed'): string
    {
        $itemsXml = '';
        foreach ($items as $item) {
            $itemsXml .= sprintf(
                '<item><title>%s</title><link>%s</link><description>%s</description><pubDate>%s</pubDate><guid>%s</guid></item>',
                htmlspecialchars($item['title'] ?? ''),
                htmlspecialchars($item['link'] ?? ''),
                htmlspecialchars($item['description'] ?? ''),
                htmlspecialchars($item['pub_date'] ?? 'Mon, 01 May 2023 12:00:00 -0400'),
                htmlspecialchars($item['guid'] ?? ''),
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            ."<title>{$channelTitle}</title><link>https://www.palegis.us</link>"
            .'<description>Feed</description>'
            .$itemsXml
            .'</channel></rss>';
    }
}
