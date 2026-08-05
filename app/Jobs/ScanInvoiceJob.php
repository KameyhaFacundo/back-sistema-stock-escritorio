<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScanInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries   = 2;

    public function __construct(
        public string $base64Image,
        public string $mimeType,
        public string $prompt,
    ) {}

    public function handle(): array
    {
        $apiKey = config('services.gemini.api_key');

        if (!$apiKey) {
            Log::warning('Gemini API key not configured');
            return ['error' => 'Clave de Gemini no configurada.'];
        }

        $response = Http::timeout(45)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$apiKey}",
            [
                'contents' => [[
                    'parts' => [
                        ['inlineData' => ['mimeType' => $this->mimeType, 'data' => $this->base64Image]],
                        ['text' => $this->prompt],
                    ],
                ]],
                // thinkingBudget en 0 y maxOutputTokens generoso: sin esto, los modelos
                // Gemini 3.x gastan el límite de tokens "pensando" antes de escribir el
                // JSON y el texto queda vacío (finishReason MAX_TOKENS sin contenido) —
                // mismo bug ya encontrado y corregido en GeminiService.
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 2000, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ]
        );

        if (!$response->successful()) {
            Log::error('Gemini scan failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
            return ['error' => 'Error al comunicarse con el servicio de IA.'];
        }

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';
        $text = trim(str_replace(['```json', '```'], '', $text));
        $data = json_decode($text, true);

        if (!is_array($data)) {
            return ['error' => 'No se pudo interpretar la respuesta de la IA.'];
        }

        return $data;
    }
}
