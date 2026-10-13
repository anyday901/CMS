<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\UsesServers;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    /** Kept when the form leaves them blank, removed with their "clear_" box. */
    private const SECRETS = ['password', 'api_token'];

    private const INDEX = 'admin.servers.index';

    private const SHORT_TEXT = 'max:255';

    public function __construct(private ModuleRegistry $modules) {}

    public function index(): View
    {
        return view('admin.servers.index', [
            'servers' => Server::withAccountCount()->with('groups')->orderBy('name')->get(),
            'groups' => ServerGroup::with('servers')->withCount('products')->orderBy('name')->get(),
            'modules' => $this->modules->withServers(),
            'group' => new ServerGroup,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.servers.form', [
            'server' => new Server(['module' => $request->query('module')]),
            'modules' => $this->modules->withServers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $server = Server::create($this->validated($request, new Server));
        Activity::record("Added server {$server->name}", $server);

        return redirect()->route('admin.servers.edit', $server)->with('status', 'Server added. Use Test connection to check its details.');
    }

    public function edit(Server $server): View
    {
        return view('admin.servers.form', [
            'server' => $server->loadCount(['accounts as account_count']),
            'modules' => $this->modules->withServers(),
        ]);
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $server->update($this->validated($request, $server));
        Activity::record("Updated server {$server->name}", $server);

        return redirect()->route(self::INDEX)->with('status', 'Server updated.');
    }

    /** A server with accounts on it stays; move or end its services first, or turn it off. */
    public function destroy(Server $server): RedirectResponse
    {
        $accounts = $server->accounts()->count();
        abort_if($accounts > 0, 422, "{$accounts} service(s) are still on this server. Move or end them first, or untick Active so it gets no new ones.");

        $server->delete();
        Activity::record("Deleted server {$server->name}");

        return redirect()->route(self::INDEX)->with('status', 'Server deleted.');
    }

    /** Logs in to the server with its saved details and reports what happened. */
    public function test(Server $server): RedirectResponse
    {
        $module = $this->modules->find($server->module);
        abort_unless($module instanceof UsesServers, 422, "The {$server->module} module isn't installed.");

        $result = $module->testConnection($server);

        return back()->with($result->ok ? 'status' : 'connection_error', $result->message ?? ($result->ok ? 'Connected.' : 'The connection failed.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Server $server): array
    {
        $request->validate(['module' => ['required', Rule::in($this->modules->withServers()->keys()->all())]]);
        $fields = $this->modules->find($request->input('module'))->serverFields();
        $required = fn (string $field) => ($fields[$field]['required'] ?? false) ? 'required' : 'nullable';

        $data = $request->validate([
            'name' => ['required', 'string', self::SHORT_TEXT],
            'hostname' => ['required', 'string', self::SHORT_TEXT, $this->hostRule()],
            'ip_address' => [$required('ip_address'), 'ip'],
            'assigned_ips' => ['nullable', 'string', 'max:20000', $this->poolRule()],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => [$required('username'), 'string', self::SHORT_TEXT],
            'password' => ['nullable', 'string', 'max:1000'],
            'api_token' => ['nullable', 'string', 'max:5000'],
            'max_accounts' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        foreach (self::SECRETS as $secret) {
            if ($request->boolean("clear_{$secret}")) {
                $data[$secret] = null;
            } elseif (blank($data[$secret] ?? null)) {
                $data[$secret] = $server->{$secret};
            }
        }

        // Fields the module doesn't use are left empty.
        foreach (['username', 'password', 'api_token', 'ip_address', 'assigned_ips'] as $field) {
            $data[$field] = isset($fields[$field]) ? $data[$field] : null;
        }

        return [
            ...$data,
            'module' => $request->input('module'),
            'secure' => $request->boolean('secure'),
            'verify_tls' => $request->boolean('verify_tls'),
            'active' => $request->boolean('active'),
        ];
    }

    /** A host name or an IP address. */
    private function hostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (filter_var($value, FILTER_VALIDATE_IP) === false && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $fail('Enter the server\'s host name, such as server1.example.com, or its IP address.');
            }
        };
    }

    /** One address per line, each with an optional /prefix and gateway. */
    private function poolRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            foreach (preg_split('/\R/', (string) $value) as $number => $line) {
                $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);

                if ($parts !== [] && ! self::poolLine($parts)) {
                    $fail('Line '.($number + 1).' of the assigned IP addresses should be an address with an optional prefix length and gateway, like 203.0.113.10/24 203.0.113.1.');

                    return;
                }
            }
        };
    }

    /** @param  list<string>  $parts */
    private static function poolLine(array $parts): bool
    {
        [$address, $prefix] = array_pad(explode('/', $parts[0], 2), 2, null);
        $limit = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return count($parts) <= 2
            && filter_var($address, FILTER_VALIDATE_IP) !== false
            && ($prefix === null || (ctype_digit($prefix) && (int) $prefix <= $limit))
            && (! isset($parts[1]) || filter_var($parts[1], FILTER_VALIDATE_IP) !== false);
    }
}
