<?php
namespace ATS\Ai;

final class AgnesProvider implements AiProviderInterface
{
    private const CHAT_COMPLETIONS_URL = 'https://apihub.agnes-ai.com/v1/chat/completions';

    public function complete(string $prompt): string
    {
        $key = trim((string)($_ENV['AGNES_API_KEY'] ?? ''));
        if ($key === '') throw new \RuntimeException('Agnes AI is not configured. Add AGNES_API_KEY to the server .env file.');
        if (!function_exists('curl_init')) throw new \RuntimeException('The PHP cURL extension is required for Agnes AI assistance.');

        $model = trim((string)($_ENV['AGNES_MODEL'] ?? ''));
        if ($model === '') throw new \RuntimeException('Set AGNES_MODEL to a model ID available in your Agnes AI account.');
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 4096,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) throw new \RuntimeException('The Agnes AI request could not be prepared.');

        $curl = curl_init(self::CHAT_COMPLETIONS_URL);
        if ($curl === false) throw new \RuntimeException('The Agnes AI request could not be started.');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body)) throw new \RuntimeException('Agnes AI request failed: ' . ($error ?: 'network error'));
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? 'Provider request failed.') : 'Provider request failed.';
            throw new \RuntimeException('Agnes AI returned an error: ' . mb_substr((string)$message, 0, 300));
        }
        if (!is_array($decoded)) throw new \RuntimeException('Agnes AI returned an unreadable response.');

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (is_string($content) && trim($content) !== '') return $content;
        if (is_array($content)) {
            $texts = [];
            foreach ($content as $part) {
                if (is_string($part)) $texts[] = $part;
                elseif (is_array($part) && is_string($part['text'] ?? null)) $texts[] = $part['text'];
            }
            if ($texts) return implode("\n", $texts);
        }
        throw new \RuntimeException('Agnes AI returned no text suggestion.');
    }
}
