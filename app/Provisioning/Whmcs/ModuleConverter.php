<?php

namespace App\Provisioning\Whmcs;

use App\Models\Activity;
use App\Models\Product;
use App\Models\Server;
use App\Models\WhmcsModule;
use App\Provisioning\Converted\ConvertedModule;
use App\Provisioning\Converted\ConvertedModules;
use App\Provisioning\Converted\ConvertedServerModule;
use App\Provisioning\Converted\ConvertedSsoServerModule;
use App\Provisioning\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ParseError;
use PhpToken;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Converts an enabled WHMCS module into a CMS module class (see
 * ConvertedModule and ModuleRewriter): its functions become methods, its
 * settings get names, and products and servers on it move to the new
 * module. The logic inside the functions is copied, not rewritten, so code
 * the upload check warned about still needs a person to change it.
 */
class ModuleConverter
{
    /** $params keys a setting can't be named, so it doesn't hide them. */
    private const RESERVED = [
        'accountid', 'serviceid', 'userid', 'domain', 'username', 'password', 'packageid', 'pid', 'status', 'type', 'producttype',
        'moduletype', 'dedicatedip', 'assignedips', 'customfields', 'configoptions', 'clientsdetails', 'server', 'serverid', 'serverip',
        'serverhostname', 'serverusername', 'serverpassword', 'serveraccesshash', 'serversecure', 'serverhttpprefix', 'serverport',
    ];

    /** WHMCS module functions with no counterpart in CMS, kept for reference. */
    private const WHMCS_ONLY = [
        'clientarea', 'admincustombuttonarray', 'clientareacustombuttonarray', 'adminservicestabfields', 'adminservicestabfieldssave',
        'adminlink', 'loginlink', 'usageupdate', 'adminsinglesignon', 'clientareaallowedfunctions', 'renew', 'listaccounts',
        'getusercount', 'metricprovider',
    ];

    public function __construct(private WhmcsModules $whmcs, private ConvertedModules $converted, private ModuleRegistry $registry) {}

    /** @return array{key: string, class: string} @throws ModuleUploadException */
    public function convert(WhmcsModule $module): array
    {
        if (! $module->enabled) {
            throw new ModuleUploadException('Enable the module before converting it, so CMS knows its settings.');
        }

        $key = $this->key($module);
        $class = $this->className($module);
        $settings = $this->settingNames($module->meta['config_fields'] ?? []);
        $methods = $this->methodNames($module, $class);
        $parts = (new ModuleRewriter($module->name, $methods, $settings))->rewrite((string) File::get($this->whmcs->file($module)));
        $code = $this->classFile($module, $key, $class, $settings, $parts);

        try {
            PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new ModuleUploadException("The converted code wouldn't run (line {$e->getLine()}: {$e->getMessage()}), so nothing was changed. Use the module as it is.");
        }

        $manifest = [
            'key' => $key,
            'class' => $class,
            'file' => "{$class}.php",
            'whmcs_name' => $module->name,
            'settings' => collect($settings)->mapWithKeys(fn (string $name, int $position) => ["configoption{$position}" => $name])->all(),
            'converted_at' => now()->toIso8601String(),
        ];

        $this->install($module, $code, $manifest);
        $this->move($module, $manifest);
        File::deleteDirectory($this->whmcs->folder($module->name));
        Artisan::call('queue:restart');
        Activity::record("Converted the WHMCS module {$module->name} into the {$class} module ({$key})");

        return ['key' => $key, 'class' => $class];
    }

    /** Removes a converted module's folder. @throws ModuleUploadException */
    public function remove(string $key): void
    {
        $manifest = $this->converted->find($key) ?? throw new ModuleUploadException('There is no converted module called '.$key.'.');

        if ($this->converted->inUse($key)) {
            throw new ModuleUploadException("{$manifest['class']} is used by products or servers, so it can't be removed. Move them to another module first.");
        }

        File::deleteDirectory($manifest['folder']);
        Activity::record("Removed the converted module {$manifest['class']} ({$key})");
    }

    /** Writes the module's folder: the WHMCS module's other files, the class and module.json. Loads it in its own process first. */
    private function install(WhmcsModule $module, string $code, array $manifest): void
    {
        $target = $this->converted->folder($manifest['key']);
        $staging = storage_path('app/converted-modules/'.Str::random(12));

        try {
            File::copyDirectory($this->whmcs->folder($module->name), $staging);
            File::delete("{$staging}/{$module->name}.php");
            File::put("{$staging}/{$manifest['file']}", $code);
            File::put("{$staging}/module.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            ModuleInspection::check("{$staging}/{$manifest['file']}", $manifest['class']);

            File::ensureDirectoryExists(dirname($target));
            File::copyDirectory($staging, $target) || throw new ModuleUploadException("Can't write {$target}.");
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** Moves products and servers to the converted module, with settings saved by name, and drops the WHMCS module. */
    private function move(WhmcsModule $module, array $manifest): void
    {
        try {
            DB::transaction(function () use ($module, $manifest) {
                foreach (Product::where('module', $module->key())->get() as $product) {
                    $config = [];

                    foreach ($product->module_config ?? [] as $name => $value) {
                        $config[$manifest['settings'][$name] ?? $name] = $value;
                    }

                    $product->update(['module' => $manifest['key'], 'module_config' => $config]);
                }

                Server::where('module', $module->key())->update(['module' => $manifest['key']]);
                $module->delete();
            });
        } catch (Throwable $e) {
            File::deleteDirectory($this->converted->folder($manifest['key']));

            throw $e;
        }
    }

    /** The module's name in lower case, unless another module has that key. */
    private function key(WhmcsModule $module): string
    {
        $key = strtolower($module->name);
        $taken = fn (string $key) => $this->registry->find($key) !== null || $this->converted->find($key) !== null || is_dir($this->converted->folder($key));

        if ($taken($key)) {
            $key .= '_custom';
        }

        return $taken($key)
            ? throw new ModuleUploadException("There is already a module called {$key}. Remove it first.")
            : $key;
    }

    private function className(WhmcsModule $module): string
    {
        $class = Str::studly($module->name).'Module';

        if (class_exists($class, false) || $this->converted->manifests() !== [] && collect($this->converted->manifests())->contains('class', $class)) {
            throw new ModuleUploadException("There is already a class called {$class}. Remove the converted module first.");
        }

        return $class;
    }

    /**
     * Names for the settings from their labels, such as "plan" for "Plan".
     *
     * @param  array<string, array<string, mixed>>  $fields  keyed configoption1 to 24
     * @return array<int, string> position => name
     */
    private function settingNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $field => $definition) {
            $position = (int) substr($field, strlen('configoption'));
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii((string) ($definition['label'] ?? '')))), '_');
            $name = $name === '' || ctype_digit($name[0]) ? "setting_{$position}" : $name;

            if (in_array($name, self::RESERVED, true) || preg_match('/^configoption\d+$/', $name)) {
                $name .= '_setting';
            }

            $names[$position] = self::firstFree(
                fn (int $n) => $n === 1 ? $name : "{$name}_{$n}",
                fn (string $candidate) => in_array($candidate, $names, true),
            );
        }

        return $names;
    }

    /**
     * The first name the candidate callback gives, counting from 1, that
     * isn't taken.
     *
     * @param  callable(int): string  $candidate
     * @param  callable(string): bool  $taken
     */
    private static function firstFree(callable $candidate, callable $taken): string
    {
        $n = 1;

        do {
            $name = $candidate($n++);
        } while ($taken($name));

        return $name;
    }

    /**
     * The method each of the module's functions becomes: the action methods
     * ConvertedModule calls, and camelCase names for the rest.
     *
     * @return array<string, string> lower-case function suffix => method
     */
    private function methodNames(WhmcsModule $module, string $class): array
    {
        $taken = array_map(fn (ReflectionMethod $method) => strtolower($method->getName()),
            (new ReflectionClass(ConvertedSsoServerModule::class))->getMethods());
        $taken[] = strtolower($class);
        $actions = array_change_key_case(ConvertedModule::METHODS);
        $methods = [];

        foreach ([...$module->report['functions'] ?? [], ...$module->report['unsupported'] ?? []] as $function) {
            $suffix = strtolower($function);
            $method = $actions[$suffix] ?? Str::camel($function);

            if (! isset($actions[$suffix])) {
                $base = $method;
                $method = self::firstFree(
                    fn (int $n) => match ($n) {
                        1 => $base,
                        2 => $base.'Helper',
                        default => $base.'Helper'.$n,
                    },
                    fn (string $candidate) => in_array(strtolower($candidate), [...$taken, ...array_map('strtolower', $actions)], true),
                );
            }

            $methods[$suffix] = $method;
            $taken[] = strtolower($method);
        }

        return $methods;
    }

    /**
     * @param  array<int, string>  $settings
     * @param  array{uses: list<string>, top: list<string>, methods: list<array{name: string, function: string, code: string}>}  $parts
     */
    private function classFile(WhmcsModule $module, string $key, string $class, array $settings, array $parts): string
    {
        $meta = $module->meta ?? [];
        $server = (bool) ($meta['requires_server'] ?? true);
        $base = match (true) {
            ! $server => ConvertedModule::class,
            $module->has('ServiceSingleSignOn') => ConvertedSsoServerModule::class,
            default => ConvertedServerModule::class,
        };
        $body = implode("\n", array_map(fn (array $method) => $method['code'], $parts['methods']));
        $uses = ["use {$base};"];

        foreach ([DB::class, Schema::class] as $facade) {
            $marker = class_basename($facade).'::';

            if (str_contains($body, $marker) || str_contains(implode("\n", $parts['top']), $marker)) {
                $uses[] = "use {$facade};";
            }
        }

        $uses = collect([...$uses, ...$parts['uses']])->unique()->sort()->values()->all();
        $fields = [];

        foreach ($meta['config_fields'] ?? [] as $field => $definition) {
            $fields[$settings[(int) substr($field, strlen('configoption'))]] = $definition;
        }

        $members = [
            "public function key(): string\n{\n    return ".var_export($key, true).";\n}",
            "public function label(): string\n{\n    return ".var_export((string) $module->display_name, true).";\n}",
            "public function whmcsName(): string\n{\n    return ".var_export($module->name, true).";\n}",
            "public function configFields(): array\n{\n    return ".self::export($fields, 1).";\n}",
            "public function settingPositions(): array\n{\n    return ".self::export($settings, 1).";\n}",
        ];

        if ($server) {
            $members[] = "public function defaultPort(bool \$secure): int\n{\n    return \$secure ? ".(int) ($meta['ports']['secure'] ?? 443).' : '.(int) ($meta['ports']['plain'] ?? 80).";\n}";
            $members[] = "public function needsDomain(): bool\n{\n    return ".($module->needs_domain ? 'true' : 'false').";\n}";
        }

        foreach ($parts['methods'] as $method) {
            $members[] = match (true) {
                in_array(strtolower($method['function']), ['metadata', 'configoptions'], true) => "// WHMCS's {$method['function']}: read when the module was converted, into the methods above.\n",
                in_array(strtolower($method['function']), self::WHMCS_ONLY, true) => "// WHMCS's {$method['function']}: CMS doesn't call it.\n",
                default => '',
            }.$method['code'];
        }

        $warnings = array_filter($module->report['warnings'] ?? [], fn (string $warning) => ! str_starts_with($warning, 'The zip also had'));
        $header = "<?php\n\n/*\n * {$module->display_name}, converted from the WHMCS module \"{$module->name}\" on ".now()->toDateString().".\n"
            ." *\n * Each action calls the method its WHMCS function became, with the \$params\n"
            ." * WHMCS would pass; product settings arrive by name as well. Edit it like\n"
            ." * any other provisioning module (docs/developers/provisioning.md).\n"
            .($warnings === [] ? '' : " *\n * The upload check found code that won't work here, left as it was:\n".implode('', array_map(fn ($w) => ' * - '.wordwrap(str_replace('*/', '* /', $w), 74, "\n *   ")."\n", $warnings)))
            ." */\n\n";

        return $header.implode("\n", $uses)."\n\n"
            .($parts['top'] === [] ? '' : implode("\n\n", $parts['top'])."\n\n")
            ."class {$class} extends ".class_basename($base)."\n{\n"
            .implode("\n\n", array_map(fn (string $member) => self::indent($member), $members))
            ."\n}\n";
    }

    /** Indents a member by four spaces, unless that would change a multi-line string in it. */
    private static function indent(string $code): string
    {
        foreach (PhpToken::tokenize("<?php {$code}") as $token) {
            if ($token->is([T_START_HEREDOC, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && str_contains($token->text, "\n")) {
                return $code;
            }
        }

        return implode("\n", array_map(fn (string $line) => $line === '' ? '' : '    '.$line, explode("\n", rtrim($code))));
    }

    /** A PHP array literal with short syntax. */
    private static function export(mixed $value, int $level): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $level + 1);
        $lines = array_map(fn ($key, $item) => $pad.(array_is_list($value) ? '' : var_export($key, true).' => ').self::export($item, $level + 1).',', array_keys($value), $value);

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $level).']';
    }
}
