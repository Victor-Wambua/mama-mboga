<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\View;

/**
 * Plain PHP templates with escaping helpers. Inside a template:
 *
 *   <?= $e($value) ?>      HTML-escape (use this for every dynamic value)
 *   <?= $raw($html) ?>     emit trusted markup unescaped (explicit, greppable)
 *   $layout('layouts/x', ['title' => '...'])   wrap this page in a layout
 *
 * A test scans every template and fails on any <?= that is not one of the
 * helpers, so forgetting to escape is caught in CI.
 */
final class View
{
    /** Helpers a template may echo directly: each escapes or builds safe markup. */
    public const SAFE_ECHO = ['$e(', '$raw(', '$csrf(', '$money(', '$qty(', '$asset(', '$attr(', '$when(', '$partial('];

    /** @param array<string, mixed> $shared variables available to every template */
    public function __construct(private readonly string $directory, private readonly array $shared = [])
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extraShared
     */
    public function render(string $template, array $data = [], array $extraShared = []): string
    {
        $layout = null;
        $layoutData = [];
        $vars = [...$this->shared, ...$extraShared, ...$data];

        $helpers = [
            'e' => self::escape(...),
            'raw' => static fn (string $html): string => $html,
            'money' => static fn (int $kes): string => 'KSh ' . number_format($kes),
            'qty' => static fn (int $milli): string => rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.'),
            'asset' => static fn (string $path): string => '/assets/' . ltrim($path, '/'),
            'attr' => static fn (bool $on, string $name): string => $on ? $name : '',
            'when' => static fn (bool $on, string $value): string => $on ? self::escape($value) : '',
            'layout' => function (string $name, array $with = []) use (&$layout, &$layoutData): void {
                $layout = $name;
                $layoutData = $with;
            },
        ];
        $helpers['partial'] = function (string $name, array $with = []) use (&$helpers, $vars): string {
            return $this->capture($name, [...$vars, ...$with, ...$helpers]);
        };
        $helpers['csrf'] = static fn (): string => '<input type="hidden" name="_csrf" value="' . self::escape($vars['csrfToken'] ?? '') . '">';

        $content = $this->capture($template, [...$vars, ...$helpers]);

        if ($layout !== null) {
            $content = $this->capture($layout, [...$vars, ...$layoutData, ...$helpers, 'content' => $content]);
        }

        return $content;
    }

    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \RuntimeException('Cannot escape a ' . get_debug_type($value) . '.');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, mixed> $vars */
    private function capture(string $template, array $vars): string
    {
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        $render = \Closure::bind(static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            include $__file;
        }, null, null);

        $level = ob_get_level();
        ob_start();
        try {
            $render($file, $vars);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
