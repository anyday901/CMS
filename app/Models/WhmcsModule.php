<?php

namespace App\Models;

use App\Provisioning\Whmcs\WhmcsModules;
use Illuminate\Database\Eloquent\Model;

/**
 * A WHMCS server module uploaded under Settings > WHMCS modules. Its files
 * are in extensions/whmcs/servers/{name}. "report" is what the upload check
 * found in its code; "meta" is what the module said about itself
 * (MetaData and ConfigOptions) when it was enabled.
 */
class WhmcsModule extends Model
{
    protected $fillable = ['name', 'display_name', 'enabled', 'needs_domain', 'meta', 'report'];

    protected $attributes = ['enabled' => false, 'needs_domain' => true];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'needs_domain' => 'boolean',
            'meta' => 'array',
            'report' => 'array',
        ];
    }

    /** The provisioning module key products and servers store, e.g. "whmcs_mypanel". */
    public function key(): string
    {
        return WhmcsModules::PREFIX.strtolower($this->name);
    }

    /** Whether the module has the WHMCS function, e.g. "ChangePassword". */
    public function has(string $function): bool
    {
        return in_array(strtolower($function), array_map('strtolower', $this->report['functions'] ?? []), true);
    }
}
