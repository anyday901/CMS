<?php

namespace App\Addons\ProjectManagement;

use App\Addons\Addon;

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
}
