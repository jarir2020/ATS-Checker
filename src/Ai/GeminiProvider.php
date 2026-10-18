<?php
namespace ATS\Ai;

final class GeminiProvider implements AiProviderInterface
{
    private const INTERACTIONS_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    public function complete(string $prompt): string
    {
        $key = trim((string)($_ENV['GEMINI_API_KEY'] ?? ''));
        if ($key === '') throw new \RuntimeException('Gemini is not configured. Add GEMINI_API_KEY to the server .env file.');
        if (!function_exists('curl_init')) throw new \RuntimeException('The PHP cURL extension is required for Gemini assistance.');

        $errors = [];
        foreach ($this->modelsToTry() as $model) {
            $attempt = $this->requestModel($model, $key, $prompt);
            if ($attempt['text'] !== null) return $attempt['text'];
            $errors[] = $model . ': ' . $attempt['error'];
            if (!$attempt['retryable']) break;
        }

        throw new \RuntimeException('Gemini could not respond with a configured model. ' . mb_substr(implode('; ', $errors), 0, 800));
    }

    private function modelsToTry(): array
    {
        $primary = trim((string)($_ENV['GEMINI_MODEL'] ?? 'gemini-3.6-flash'));
        $fallbacks = trim((string)($_ENV['GEMINI_FALLBACK_MODELS'] ?? 'gemini-3.5-flash-lite,gemini-2.5-flash'));
        $models = [];
        foreach (array_merge([$primary], explode(',', $fallbacks)) as $model) {
            $model = trim((string)$model);
            if ($model === '' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model) || in_array($model, $models, true)) continue;
            $models[] = $model;
        }
        return array_slice($models, 0, 4);
    }

    private function requestModel(string $model, string $key, string $prompt): array
    {
        $payload = json_encode([
            'model' => $model,
            'input' => $prompt,
            'store' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) return ['text' => null, 'error' => 'The request could not be prepared.', 'retryable' => false];

        $curl = curl_init(self::INTERACTIONS_URL);
        if ($curl === false) return ['text' => null, 'error' => 'The request could not be started.', 'retryable' => false];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body)) {
            return ['text' => null, 'error' => 'The network request failed: ' . ($error ?: 'network error'), 'retryable' => false];
        }

        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $providerError = is_array($decoded) ? ($decoded['error'] ?? $decoded[0]['error'] ?? null) : null;
            $message = is_array($providerError) ? ($providerError['message'] ?? 'Provider request failed.') : 'Provider request failed.';
            $message = mb_substr((string)$message, 0, 300);
            return ['text' => null, 'error' => $message, 'retryable' => $this->shouldTryNextModel($status, $message)];
        }

        if (!is_array($decoded)) return ['text' => null, 'error' => 'The provider returned an unreadable response.', 'retryable' => true];
        $texts = [];
        $outputs = is_array($decoded['output'] ?? null) ? $decoded['output'] : [];
        foreach ($outputs as $item) {
            if (!is_array($item)) continue;
            if (isset($item['text']) && is_string($item['text'])) $texts[] = $item['text'];
            foreach (($item['content'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) $texts[] = $part['text'];
            }
        }
        $steps = is_array($decoded['steps'] ?? null) ? $decoded['steps'] : [];
        foreach ($steps as $step) {
            if (!is_array($step) || ($step['type'] ?? null) !== 'model_output') continue;
            foreach (($step['content'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) $texts[] = $part['text'];
            }
        }
        if (!$texts && isset($decoded['output_text']) && is_string($decoded['output_text'])) $texts[] = $decoded['output_text'];
        $text = trim(implode("\n", $texts));
        if ($text === '') return ['text' => null, 'error' => 'The provider returned no text.', 'retryable' => true];
        return ['text' => $text, 'error' => '', 'retryable' => false];
    }

    private function shouldTryNextModel(int $status, string $message): bool
    {
        if (in_array($status, [408, 425, 429, 500, 502, 503, 504], true)) return true;
        if (preg_match('/high demand|overload|temporarily unavailable|spikes? in demand|rate.?limit|resource.?exhausted/i', $message)) return true;
        if (in_array($status, [400, 403, 404], true) && preg_match('/model.*(not found|not supported|unavailable|access|available)|access.*model|not available.*(plan|tier|account)/i', $message)) return true;
        return false;
    }
}
