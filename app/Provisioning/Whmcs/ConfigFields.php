<?php

namespace App\Provisioning\Whmcs;

/**
 * Turns what a WHMCS module's ConfigOptions returns into product settings.
 * WHMCS hands them to the module by position, as configoption1 to
 * configoption24, so they are saved under those names.
 */
final class ConfigFields
{
    public const MAX = 24;

    /** @return array<string, array{label: string, help?: string, type?: string, options?: array<string, string>, default?: string}> */
    public static function from(mixed $options): array
    {
        $fields = [];
        $position = 0;

        foreach (is_array($options) ? $options : [] as $label => $option) {
            if (++$position > self::MAX) {
                break;
            }

            $option = is_array($option) ? $option : [];
            $field = ['label' => self::text($option['FriendlyName'] ?? $label) ?: "Setting {$position}"];
            $help = self::text($option['Description'] ?? '');

            if ($help !== '') {
                $field['help'] = $help;
            }

            [$field, $default] = self::typed($field, $option);

            if (is_scalar($default) && (string) $default !== '') {
                $field['default'] = (string) $default;
            }

            $fields["configoption{$position}"] = $field;
        }

        return $fields;
    }

    /**
     * The field with the input type of the option, and the option's default
     * as the field saves it.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $option
     * @return array{array<string, mixed>, mixed}
     */
    private static function typed(array $field, array $option): array
    {
        $default = $option['Default'] ?? null;

        return match (strtolower((string) ($option['Type'] ?? 'text'))) {
            'yesno' => [
                $field + ['type' => 'select', 'options' => ['' => 'No', 'on' => 'Yes']],
                $default === true || in_array(strtolower((string) $default), ['on', 'yes', '1', 'true'], true) ? 'on' : null,
            ],
            'dropdown', 'radio' => [$field + ['type' => 'select', 'options' => self::choices($option['Options'] ?? [])], $default],
            'textarea' => [$field + ['type' => 'textarea'], $default],
            default => [$field, $default],
        };
    }

    /** "a,b,c", a list, or value => label. @return array<string, string> */
    private static function choices(mixed $options): array
    {
        $options = is_array($options) ? $options : explode(',', (string) $options);
        $list = array_is_list($options);
        $choices = [];

        foreach ($options as $value => $label) {
            $choices[trim((string) ($list ? self::text($label) : $value))] = self::text($label);
        }

        return $choices;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES)) : '';
    }
}
