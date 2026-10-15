<?php

namespace App\Addons\ProjectManagement;

use App\Addons\Addon;
use App\Addons\ProjectManagement\Http\Controllers\PortalProjectController;
use App\Addons\ProjectManagement\Http\Controllers\ProjectController;
use App\Addons\ProjectManagement\Http\Controllers\TaskController;
use App\Addons\ProjectManagement\Http\Controllers\TimeEntryController;
use App\Addons\ProjectManagement\Models\Project;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Support\Money;
use Illuminate\Support\Facades\Route;

/** Projects for clients, with tasks and time that goes on invoices. */
class ProjectManagementAddon extends Addon
{
    public function key(): string
    {
        return 'projects';
    }

    public function name(): string
    {
        return 'Project management';
    }

    public function description(): string
    {
        return 'Projects for clients, with tasks, staff time tracking and unbilled time added to invoices.';
    }

    public function settings(): array
    {
        return [
            'default_rate' => [
                'label' => 'Default hourly rate',
                'type' => 'text',
                'help' => 'New projects start with this rate, in the client\'s currency. Each project can have its own.',
                'rules' => [Money::rule()],
                'default' => '75.00',
            ],
            'taxable' => [
                'label' => 'Charge tax on invoiced time',
                'type' => 'checkbox',
                'default' => true,
            ],
            'invoice_line' => [
                'label' => 'Invoice line wording',
                'type' => 'text',
                'help' => 'Each time entry becomes a line like this. You can use {project}, {task}, {description}, {date}, {time}, {rate} and {staff}.',
                'rules' => ['max:200'],
                'default' => ProjectInvoicer::DEFAULT_LINE,
            ],
            'portal' => [
                'label' => 'Show projects to clients',
                'type' => 'checkbox',
                'help' => 'Clients see their projects in the portal, with tasks and status. Task notes and rates stay private.',
                'default' => false,
            ],
            'portal_time' => [
                'label' => 'Show time logged to clients',
                'type' => 'checkbox',
                'help' => 'Clients also see the time spent on each project, without rates.',
                'default' => false,
            ],
        ];
    }

    public function migrationsPath(): string
    {
        return __DIR__.'/database/migrations';
    }

    public function viewsPath(): string
    {
        return __DIR__.'/resources/views';
    }

    public function routes(): void
    {
        $this->adminRoutes('projects', fn () => Route::where(['project' => '[0-9]+', 'task' => '[0-9]+', 'entry' => '[0-9]+', 'invoice' => '[0-9]+'])->group(function () {
            Route::controller(ProjectController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('time', 'report')->name('report');
                Route::get('{project}', 'show')->name('show');
                Route::middleware('can:manage-clients')->group(function () {
                    Route::get('create', 'create')->name('create');
                    Route::post('/', 'store')->name('store');
                    Route::get('{project}/edit', 'edit')->name('edit');
                    Route::put('{project}', 'update')->name('update');
                    Route::delete('{project}', 'destroy')->name('destroy');
                });
                Route::middleware('can:manage-billing')->group(function () {
                    Route::post('{project}/invoice', 'invoice')->name('invoice');
                    Route::post('{project}/invoices', 'link')->name('invoices.link');
                    Route::delete('{project}/invoices/{invoice}', 'unlink')->name('invoices.unlink');
                });
            });

            Route::controller(TaskController::class)->name('tasks.')->group(function () {
                Route::post('{project}/tasks', 'store')->name('store');
                Route::get('tasks/{task}/edit', 'edit')->name('edit');
                Route::put('tasks/{task}', 'update')->name('update');
                Route::put('tasks/{task}/status', 'status')->name('status');
                Route::put('tasks/{task}/move', 'move')->name('move');
                Route::delete('tasks/{task}', 'destroy')->name('destroy');
            });

            Route::controller(TimeEntryController::class)->name('time.')->group(function () {
                Route::post('{project}/time', 'store')->name('store');
                Route::get('time/{entry}/edit', 'edit')->name('edit');
                Route::put('time/{entry}', 'update')->name('update');
                Route::delete('time/{entry}', 'destroy')->name('destroy');
                Route::post('{project}/timer', 'start')->name('timer.start');
                Route::post('timer/stop', 'stop')->name('timer.stop');
                Route::delete('timer', 'discard')->name('timer.discard');
            });
        }));

        $this->portalRoutes('projects', function () {
            Route::get('/', [PortalProjectController::class, 'index'])->name('index');
            Route::get('{project}', [PortalProjectController::class, 'show'])->whereNumber('project')->name('show');
        });
    }

    public function boot(): void
    {
        $hooks = app(Hooks::class);

        foreach (ProjectHooks::events() as $key => $label) {
            $hooks->define($key, $label);
        }
    }

    public function bootInstalled(): void
    {
        // Merging clients: the merged client's projects move with everything
        // else, even while the add-on is turned off.
        app(Hooks::class)->listen('client.merged', function (HookEvent $event) {
            Project::where('client_id', $event->data['from_client_id'])->update(['client_id' => $event->data['into_client_id']]);
        });
    }

    public function adminLinks(): array
    {
        return ['admin.projects.index' => 'Projects'];
    }

    public function portalLinks(): array
    {
        return $this->setting('portal') ? ['portal.projects.index' => 'Projects'] : [];
    }

    /** The hourly rate new projects start with, in cents. */
    public function defaultRate(): int
    {
        try {
            return Money::parse((string) $this->setting('default_rate', '0'));
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }
}
