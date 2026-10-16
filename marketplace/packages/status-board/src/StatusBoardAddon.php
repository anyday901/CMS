<?php

namespace CmsAddons\StatusBoard;

use App\Addons\Addon;
use Illuminate\Support\Facades\Route;

/** An example marketplace add-on: one admin page of today's numbers. */
class StatusBoardAddon extends Addon
{
    public function key(): string
    {
        return 'status-board';
    }

    public function name(): string
    {
        return 'Status board';
    }

    public function description(): string
    {
        return 'Today\'s payments, new orders and open tickets in big numbers, refreshed every minute.';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function author(): string
    {
        return 'CMS';
    }

    public function settings(): array
    {
        return [
            'title' => ['label' => 'Board title', 'type' => 'text', 'default' => 'Today', 'rules' => ['nullable', 'string', 'max:60']],
        ];
    }

    public function viewsPath(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function langPath(): ?string
    {
        return __DIR__.'/../lang';
    }

    public function routes(): void
    {
        $this->adminRoutes('status-board', function () {
            Route::get('/', BoardController::class)->middleware('can:manage-billing')->name('index');
        });
    }

    public function adminLinks(): array
    {
        return ['admin.status-board.index' => ['Board', 'manage-billing']];
    }
}
