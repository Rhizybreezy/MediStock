<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object. Nothing is echoed until send() is called, so
 * middleware can still add headers (security headers, etc.).
 */
class Response
{
    private array $headers = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** JSON response. Arrays without an 'ok' key are wrapped as {ok:true, data:...}. */
    public static function json(mixed $data, int $status = 200): self
    {
        if (!is_array($data) || !array_key_exists('ok', $data)) {
            $data = ['ok' => $status < 400, 'data' => $data];
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self((string) $body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function download(string $content, string $filename, string $contentType): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
        }
        echo $this->body;
    }
}
