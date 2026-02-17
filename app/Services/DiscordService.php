<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class DiscordService
{
    public static function sendToDiscord($exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): void {
            $enabled = (bool) config('services.discord.exceptions', false);

            if (! $enabled) {
                return;
            }

            // Collect environment details
            $environmentData = [
                ['name' => 'App Name', 'value' => config('app.name'), 'inline' => true],
                ['name' => 'Environment', 'value' => app()->environment(), 'inline' => true],
                ['name' => 'Debug Mode', 'value' => config('app.debug') ? 'Enabled' : 'Disabled', 'inline' => true],
                ['name' => 'PHP Version', 'value' => PHP_VERSION, 'inline' => true],
                ['name' => 'Laravel Version', 'value' => app()->version(), 'inline' => true],
                ['name' => 'DB Connection', 'value' => config('database.default'), 'inline' => true],
                ['name' => 'Server IP', 'value' => request()->server('SERVER_ADDR') ?? 'N/A', 'inline' => true],
                ['name' => 'Client IP', 'value' => request()->ip(), 'inline' => true],
            ];

            $errorData = [
                'title' => 'Laravel Exception Alert',
                'description' => '**Message:** '.$exception->getMessage(),
                'fields' => array_merge([
                    ['name' => 'File', 'value' => $exception->getFile(), 'inline' => false],
                    ['name' => 'Line', 'value' => (string) $exception->getLine(), 'inline' => true],
                    ['name' => 'Environment', 'value' => app()->environment(), 'inline' => true],
                    ['name' => 'Request Method', 'value' => request()->method(), 'inline' => true],
                    ['name' => 'Request', 'value' => request()->fullUrl() ?? 'N/A', 'inline' => false],
                ], $environmentData),
                'color' => 16711680, // Red color for errors
                'timestamp' => now()->toIso8601String(),
            ];

            $payload = [
                'username' => 'Laravel Bot',
                'avatar_url' => 'https://laravel.com/img/logomark.min.svg',
                'embeds' => [[
                    'title' => $errorData['title'],
                    'description' => $errorData['description'],
                    'color' => $errorData['color'],
                    'fields' => $errorData['fields'],
                    'timestamp' => $errorData['timestamp'],
                ]],
            ];

            $webhookUrl = env('DISCORD_ALERT_WEBHOOK');

            if (!empty($webhookUrl) &&  in_array(app()->environment(),['production','prod'])){
                Http::post($webhookUrl, $payload);
            }
        });
    }
}


