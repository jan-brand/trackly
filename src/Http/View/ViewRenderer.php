<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * Renders a view template wrapped in the shared layout.
 */
class ViewRenderer
{
    public function __construct(
        private readonly string $viewsPath,
    ) {}

    /**
     * Render a view template and wrap it in the layout.
     *
     * @param string               $template  Relative path inside the views directory, without .php extension
     * @param array<string, mixed> $data      Variables to extract into the template scope
     */
    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->viewsPath . '/' . $template . '.php';
        $layoutPath   = $this->viewsPath . '/layout.php';

        extract($data);

        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        ob_start();
        require $layoutPath;
        return (string) ob_get_clean();
    }
}
