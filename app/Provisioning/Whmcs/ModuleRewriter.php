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

    private const THIS = '$this->';

    /** WHMCS's log functions => the ConvertedModule methods that replace them. */
    private const LOG_METHODS = ['logmodulecall' => 'logModuleCall', 'logactivity' => 'logActivity'];

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
            $this->add($result, $comments, $statement);
        }

        return $result;
    }

    /**
     * Adds a top-level statement to the parts of the class.
     *
     * @param  array{uses: list<string>, top: list<string>, methods: list<array{name: string, function: string, code: string}>}  $result
     * @param  list<PhpToken>  $statement
     */
    private function add(array &$result, string $comments, array $statement): void
    {
        $first = $this->significant($statement, 0);
        $text = $this->text($statement);

        if ($first === null || ($first->is(T_IF) && preg_match('/defined\s*\(\s*[\'"]WHMCS[\'"]\s*\)/i', $text))) {
            return;
        }

        if ($first->is(T_NAMESPACE)) {
            throw new ModuleUploadException("{$this->name}.php declares a namespace, so it can't be converted. Use the module as it is.");
        }

        $function = $first->is(T_FUNCTION) ? $this->significant($statement, array_search($first, $statement, true) + 1) : null;

        if ($first->is(T_USE)) {
            if (! preg_match('/WHMCS\\\\Database\\\\Capsule\s*;/i', $text)) {
                $result['uses'][] = trim($text);
            }
        } elseif ($function?->is(T_STRING) && str_starts_with(strtolower($function->text), $this->prefix)) {
            $result['methods'][] = $this->method($comments, $statement, $function);
        } else {
            $code = $this->body($statement);

            if (str_contains($code, self::THIS)) {
                throw new ModuleUploadException("Code outside the functions of {$this->name}.php calls the module's functions, so it can't be converted. Use the module as it is.");
            }

            $result['top'][] = $comments.$code;
        }
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
        $i = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($this->outsideCode($token)) {
                $i++;
            } elseif ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $comments .= $token->text.(str_ends_with($token->text, "\n") ? '' : "\n");
                $i++;
            } else {
                $end = $this->statementEnd($tokens, $i);
                $statements[] = [$comments, array_slice($tokens, $i, $end - $i + 1)];
                $comments = '';
                $i = $end + 1;
            }
        }

        return $statements;
    }

    /** Whether the token is outside any statement: PHP tags, whitespace, or blank text between tags. */
    private function outsideCode(PhpToken $token): bool
    {
        if ($token->is(T_INLINE_HTML) && trim($token->text) !== '') {
            throw new ModuleUploadException("{$this->name}.php has HTML outside its PHP code, so it can't be converted. Use the module as it is.");
        }

        return $token->is([T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_INLINE_HTML]);
    }

    /** The index of the last token of the statement starting at $start. @param  list<PhpToken>  $tokens */
    private function statementEnd(array $tokens, int $start): int
    {
        $block = $tokens[$start]->is(self::BLOCKS);
        $depth = 0;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $depth += $this->depthChange($tokens[$i]);

            if ($depth === 0 && $this->endsStatement($tokens, $i, $block)) {
                return $i;
            }
        }

        return $count - 1;
    }

    private function depthChange(PhpToken $token): int
    {
        return match (true) {
            in_array($token->text, self::OPENS, true), $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE]) => 1,
            in_array($token->text, self::CLOSES, true) => -1,
            default => 0,
        };
    }

    /** Whether the token at $i, at depth 0, ends its statement. @param  list<PhpToken>  $tokens */
    private function endsStatement(array $tokens, int $i, bool $block): bool
    {
        $token = $tokens[$i];

        return $token->text === ';'
            || $token->is(T_CLOSE_TAG)
            || ($block && $token->text === '}' && ! $this->significant($tokens, $i + 1)?->is(self::CONTINUES));
    }

    /**
     * A method from a function statement: its header renamed, its body rewritten.
     *
     * @param  list<PhpToken>  $statement
     * @return array{name: string, function: string, code: string}
     */
    private function method(string $comments, array $statement, PhpToken $function): array
    {
        $suffix = substr($function->text, strlen($this->prefix));
        $name = $this->methods[strtolower($suffix)]
            ?? throw new ModuleUploadException("{$function->text} has no method name. Upload the module again, then convert it.");
        $visibility = in_array($name, ConvertedModule::METHODS, true) ? 'protected' : 'private';
        $start = array_search($function, $statement, true);

        return [
            'name' => $name,
            'function' => $suffix,
            'code' => $comments."{$visibility} function {$name}".$this->body(array_slice($statement, $start + 1)),
        ];
    }

    /** The code of the tokens with the rewrites applied. @param  list<PhpToken>  $tokens */
    private function body(array $tokens): string
    {
        $out = '';
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            [$code, $i] = $this->rewriteAt($tokens, $i);
            $out .= $code;
        }

        return $out;
    }

    /**
     * The code for the token at $i, rewritten if it needs to be.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{string, int} the code and the index of the next token
     */
    private function rewriteAt(array $tokens, int $i): array
    {
        $token = $tokens[$i];

        if ($this->isCapsule($token) && $this->significant($tokens, $i + 1)?->is(T_DOUBLE_COLON)) {
            $schema = $this->schemaCall($tokens, $i);

            return $schema === null ? ['DB', $i + 1] : ['Schema::', $schema + 1];
        }

        return [$this->call($tokens, $i) ?? $this->settingKey($tokens, $i) ?? $token->text, $i + 1];
    }

    /** "$this->method" for a call to one of the module's functions or to WHMCS's log functions, otherwise null. @param  list<PhpToken>  $tokens */
    private function call(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        $previous = $this->significantBefore($tokens, $i);

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
            || $this->significant($tokens, $i + 1)?->text !== '('
            || $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
            return null;
        }

        $name = strtolower(ltrim($token->text, '\\'));
        $method = self::LOG_METHODS[$name] ?? null;

        if (str_starts_with($name, $this->prefix)) {
            $method = $this->methods[substr($name, strlen($this->prefix))] ?? $method;
        }

        return $method === null ? null : self::THIS.$method;
    }

    /** The setting name for a 'configoptionN' array key, otherwise null. @param  list<PhpToken>  $tokens */
    private function settingKey(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];

        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)
            || $this->significantBefore($tokens, $i)?->text !== '['
            || ! preg_match('/^([\'"])configoption(\d+)\1$/', $token->text, $match)) {
            return null;
        }

        $setting = $this->settings[(int) $match[2]] ?? null;

        return $setting === null ? null : "'{$setting}'";
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
        $k = 0;

        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j]->is(T_WHITESPACE)) {
                continue;
            }

            if (strtolower($tokens[$j]->text) !== $expected[$k]) {
                return null;
            }

            if (++$k === count($expected)) {
                return $j;
            }
        }

        return null;
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
