<?php

namespace App\Provisioning\Whmcs;

use App\Provisioning\Converted\ConvertedModule;
use ParseError;
use PhpToken;

/**
 * Rewrites a WHMCS module's main file as the parts of a class, token by
 * token, without changing what the code does:
 *
 * - each {name}_Function becomes a method (see $methods), and calls to them
 *   become $this->method(...);
 * - WHMCS\Database\Capsule becomes Laravel's DB (and Schema for schema());
 * - logModuleCall and logActivity become the module's own methods;
 * - $params['configoptionN'] becomes $params['setting_name'];
 * - the "defined('WHMCS') or die" guard is dropped, and other code outside
 *   functions (use statements, require_once, constants) is kept as it is.
 */
final class ModuleRewriter
{
    /** Statements that end with their block rather than a semicolon. */
    private const BLOCKS = [T_FUNCTION, T_IF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH, T_TRY, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_ABSTRACT, T_FINAL, T_READONLY];

    private const CONTINUES = [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY];

    private const OPENS = ['{', '(', '['];

    private const CLOSES = ['}', ')', ']'];

    private string $prefix;

    /**
     * @param  array<string, string>  $methods  lower-case function suffix (e.g. "createaccount") => method name
     * @param  array<int, string>  $settings  config option position => setting name
     */
    public function __construct(private string $name, private array $methods, private array $settings) {}

    /**
     * @return array{uses: list<string>, top: list<string>, methods: list<array{name: string, function: string, code: string}>}
     *
     * @throws ModuleUploadException when the file can't be converted
     */
    public function rewrite(string $source): array
    {
        $this->prefix = strtolower($this->name).'_';

        try {
            $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new ModuleUploadException("{$this->name}.php has a PHP error on line {$e->getLine()}: {$e->getMessage()}.");
        }

        $result = ['uses' => [], 'top' => [], 'methods' => []];

        foreach ($this->statements($tokens) as [$comments, $statement]) {
            $first = $this->significant($statement, 0);
            $text = $this->text($statement);

            if ($first === null) {
                continue;
            }

            if ($first->is(T_NAMESPACE)) {
                throw new ModuleUploadException("{$this->name}.php declares a namespace, so it can't be converted. Use the module as it is.");
            }

            if ($first->is(T_USE)) {
                if (! preg_match('/WHMCS\\\\Database\\\\Capsule\s*;/i', $text)) {
                    $result['uses'][] = trim($text);
                }

                continue;
            }

            if ($first->is(T_IF) && preg_match('/defined\s*\(\s*[\'"]WHMCS[\'"]\s*\)/i', $text)) {
                continue;
            }

            $function = $first->is(T_FUNCTION) ? $this->significant($statement, array_search($first, $statement, true) + 1) : null;

            if ($function?->is(T_STRING) && str_starts_with(strtolower($function->text), $this->prefix)) {
                $suffix = substr($function->text, strlen($this->prefix));

                if (! isset($this->methods[strtolower($suffix)])) {
                    throw new ModuleUploadException("{$function->text} has no method name. Upload the module again, then convert it.");
                }

                $result['methods'][] = [
                    'name' => $this->methods[strtolower($suffix)],
                    'function' => $suffix,
                    'code' => $comments.$this->method($statement, $function),
                ];

                continue;
            }

            $code = $this->body($statement);

            if (str_contains($code, '$this->')) {
                throw new ModuleUploadException("Code outside the functions of {$this->name}.php calls the module's functions, so it can't be converted. Use the module as it is.");
            }

            $result['top'][] = $comments.$code;
        }

        return $result;
    }

    /**
     * Splits the file into its top-level statements, each with the
     * comments before it.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{string, list<PhpToken>}>
     */
    private function statements(array $tokens): array
    {
        $statements = [];
        $comments = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE])) {
                continue;
            }

            if ($token->is(T_INLINE_HTML)) {
                if (trim($token->text) !== '') {
                    throw new ModuleUploadException("{$this->name}.php has HTML outside its PHP code, so it can't be converted. Use the module as it is.");
                }

                continue;
            }

            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $comments .= $token->text.(str_ends_with($token->text, "\n") ? '' : "\n");

                continue;
            }

            $block = $token->is(self::BLOCKS);
            $depth = 0;
            $statement = [];

            for (; $i < $count; $i++) {
                $current = $tokens[$i];
                $statement[] = $current;

                if (in_array($current->text, self::OPENS, true) || $current->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                    $depth++;
                } elseif (in_array($current->text, self::CLOSES, true)) {
                    $depth--;
                }

                if ($depth === 0 && ($current->text === ';' || $current->is(T_CLOSE_TAG))) {
                    break;
                }

                if ($depth === 0 && $block && $current->text === '}') {
                    $next = $this->significant($tokens, $i + 1);

                    if (! $next?->is(self::CONTINUES)) {
                        break;
                    }
                }
            }

            $statements[] = [$comments, $statement];
            $comments = '';
        }

        return $statements;
    }

    /** A method from a function statement: its header renamed, its body rewritten. @param  list<PhpToken>  $statement */
    private function method(array $statement, PhpToken $function): string
    {
        $suffix = strtolower(substr($function->text, strlen($this->prefix)));
        $visibility = in_array($this->methods[$suffix], ConvertedModule::METHODS, true) ? 'protected' : 'private';
        $start = array_search($function, $statement, true);

        return "{$visibility} function {$this->methods[$suffix]}".$this->body(array_slice($statement, $start + 1));
    }

    /** The code of the tokens with the rewrites applied. @param  list<PhpToken>  $tokens */
    private function body(array $tokens): string
    {
        $out = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $this->significantBefore($tokens, $i);
            $next = $this->significant($tokens, $i + 1);
            $called = $next?->text === '(' && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);
            $name = ltrim($token->text, '\\');

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $called && str_starts_with(strtolower($name), $this->prefix) && isset($this->methods[strtolower(substr($name, strlen($this->prefix)))])) {
                $out .= '$this->'.$this->methods[strtolower(substr($name, strlen($this->prefix)))];
            } elseif ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $called && in_array(strtolower($name), ['logmodulecall', 'logactivity'], true)) {
                $out .= '$this->'.(strtolower($name) === 'logactivity' ? 'logActivity' : 'logModuleCall');
            } elseif ($this->isCapsule($token) && $next?->is(T_DOUBLE_COLON)) {
                $schema = $this->schemaCall($tokens, $i);
                $out .= $schema === null ? 'DB' : 'Schema::';
                $i = $schema ?? $i;
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && $previous?->text === '[' && preg_match('/^([\'"])configoption(\d+)\1$/', $token->text, $match) && isset($this->settings[(int) $match[2]])) {
                $out .= "'{$this->settings[(int) $match[2]]}'";
            } else {
                $out .= $token->text;
            }
        }

        return $out;
    }

    private function isCapsule(PhpToken $token): bool
    {
        return ($token->is(T_STRING) && $token->text === 'Capsule')
            || ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && strcasecmp(ltrim($token->text, '\\'), 'WHMCS\\Database\\Capsule') === 0);
    }

    /** For "Capsule::schema()->", the index of the "->"; otherwise null. @param  list<PhpToken>  $tokens */
    private function schemaCall(array $tokens, int $i): ?int
    {
        $expected = ['::', 'schema', '(', ')', '->'];

        for ($j = $i + 1, $k = 0; $k < count($expected) && $j < count($tokens); $j++) {
            if ($tokens[$j]->is(T_WHITESPACE)) {
                continue;
            }

            if (strtolower($tokens[$j]->text) !== $expected[$k]) {
                return null;
            }

            $k++;
        }

        return $k === count($expected) ? $j - 1 : null;
    }

    /** @param  list<PhpToken>  $tokens */
    private function text(array $tokens): string
    {
        return implode('', array_map(fn (PhpToken $token) => $token->text, $tokens));
    }

    /** The first token from $from on that isn't whitespace or a comment. @param  list<PhpToken>  $tokens */
    private function significant(array $tokens, int $from): ?PhpToken
    {
        for ($j = $from; $j < count($tokens); $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /** @param  list<PhpToken>  $tokens */
    private function significantBefore(array $tokens, int $before): ?PhpToken
    {
        for ($j = $before - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->isIgnorable()) {
                return $tokens[$j];
            }
        }

        return null;
    }
}
