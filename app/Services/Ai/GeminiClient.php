<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * Minimal server-side client for the Gemini generateContent REST API.
 * - The API key is sent in the x-goog-api-key header from the server only.
 * - Transient failures (429, 500, 503) are retried with backoff.
 * - The key is never logged; errors are logged without request bodies.
 */
final class GeminiClient
{
    public function __construct(private Logger $logger)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string) Config::get('ai.api_key', '')) !== '';
    }

    public function model(): string
    {
        return (string) Config::get('ai.model', 'gemini-2.5-flash');
    }

    /**
     * @param array $contents  Gemini "contents" array (role user|model, parts[])
     * @param array $functionDeclarations
     * @param ?string $model pin a model (used for follow-up calls in the same turn); null = primary with fallbacks
     * @return array{parts: array, text: string, function_calls: array, usage: array, finish_reason: ?string, latency_ms: int, model: string}
     */
    public function generate(string $systemInstruction, array $contents, array $functionDeclarations = [], ?string $model = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('The AI assistant is not configured (missing GEMINI_API_KEY).');
        }
        $candidates = $model !== null ? [$model] : array_values(array_unique(array_merge([$this->model()], (array) Config::get('ai.fallback_models', []))));
        $lastError = null;
        foreach ($candidates as $m) {
            try {
                return $this->call($m, $systemInstruction, $contents, $functionDeclarations);
            } catch (ModelUnavailableException $e) {
                $lastError = $e;
                $this->logger->warning("Gemini model $m unavailable, trying next: " . $e->getMessage());
            }
        }
        throw new RuntimeException('The AI service is very busy right now. Please try again in a minute.', 0, $lastError);
    }

    private function call(string $model, string $systemInstruction, array $contents, array $functionDeclarations): array
    {
        $model = preg_replace('/[^a-z0-9.\-]/i', '', $model);
        $body = [
            'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 4096],
        ];
        $level = trim((string) Config::get('ai.thinking_level', ''));
        if ($level !== '') {
            $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $level];
        }
        if ($functionDeclarations) {
            foreach ($functionDeclarations as &$fd) {
                $fd['parameters']['properties'] = (object) ($fd['parameters']['properties'] ?? []);
            }
            unset($fd);
            $body['tools'] = [['functionDeclarations' => $functionDeclarations]];
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }
        $url = rtrim((string) Config::get('ai.endpoint'), '/') . '/' . $model . ':generateContent';
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $attempt = 0;
        $started = microtime(true);
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout(),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . Config::get('ai.api_key')],
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $curlError) {
                if (str_contains($curlError, 'timed out')) {
                    throw new ModelUnavailableException("timeout after {$this->timeout()}s");
                }
                if ($attempt < 2) {
                    usleep(500000);
                    continue;
                }
                $this->logger->error('Gemini network error: ' . $curlError);
                throw new RuntimeException('Could not reach the AI service. Please check the server\'s internet connection.');
            }
            if (in_array($status, [500, 502, 503, 504], true)) {
                if ($attempt < 2) {
                    usleep(700000);
                    continue;
                }
                throw new ModelUnavailableException("HTTP $status");
            }
            if ($status === 404) {
                throw new ModelUnavailableException('model not available (404)');
            }
            $data = json_decode((string) $raw, true);
            // Second decode keeps JSON objects as objects ({} stays {}), so the model's parts
            // (function calls, thought signatures) can be sent back byte-for-byte equivalent.
            $dataObj = json_decode((string) $raw, false);
            if ($status !== 200 || !is_array($data)) {
                $msg = is_array($data) ? (string) ($data['error']['message'] ?? 'unknown error') : 'invalid response';
                $this->logger->error("Gemini HTTP $status: " . mb_substr($msg, 0, 300));
                throw new RuntimeException($status === 429 ? 'The AI service is busy (rate limit). Please wait a moment and try again.' : 'The AI service returned an error. Please try again later.');
            }
            break;
        }

        $candidate = $data['candidates'][0] ?? null;
        $parts = $candidate['content']['parts'] ?? [];
        $rawParts = $dataObj->candidates[0]->content->parts ?? [];
        $text = '';
        $calls = [];
        foreach ($parts as $p) {
            if (isset($p['text']) && empty($p['thought'])) {
                $text .= $p['text'];
            }
            if (isset($p['functionCall'])) {
                $calls[] = ['name' => (string) ($p['functionCall']['name'] ?? ''), 'args' => (array) ($p['functionCall']['args'] ?? [])];
            }
        }
        return [
            'parts' => $rawParts,
            'text' => trim($text),
            'function_calls' => $calls,
            'usage' => [
                'prompt' => (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
                'output' => (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
            'finish_reason' => $candidate['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? null),
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'model' => $model,
        ];
    }

    private function timeout(): int
    {
        return max(10, (int) Config::get('ai.timeout', 60));
    }
}
