<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    /**
     * Sugiere un precio de venta basado en el costo, nombre y tipo de producto.
     */
    public function sugerirPrecio(float $costo, string $nombre, string $categoria = null): ?float
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) return null;

        $prompt = "Actuá como un experto en precios minoristas argentinos. Dado el siguiente producto:\n"
            . "- Nombre: {$nombre}\n"
            . "- Costo de compra: \${$costo}\n"
            . ($categoria ? "- Categoría: {$categoria}\n" : "")
            . "Sugerí un precio de venta final (IVA incluido) en pesos argentinos. "
            . "Respondé ÚNICAMENTE con el número (sin símbolo \$ ni puntos de miles, solo el número con decimales si corresponde). "
            . "Ejemplo de respuesta: 1250.50";

        try {
            $response = Http::timeout(20)->post($this->endpoint($apiKey), [
                'contents' => [['parts' => [['text' => $prompt]]]],
                // thinkingBudget en 0: sin esto, los modelos Gemini 3.x gastan el
                // límite de tokens "pensando" antes de escribir la respuesta y
                // el texto queda vacío (finishReason MAX_TOKENS sin contenido).
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 60, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ]);

            $text = $response->json('candidates.0.content.parts.0.text', '');
            $precio = floatval(trim($text));

            return $precio > 0 ? $precio : null;
        } catch (\Exception $e) {
            Log::warning('Gemini price suggestion failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sugiere una categoría basada en el nombre del producto.
     */
    public function sugerirCategoria(string $nombre, array $categoriasExistentes): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) return null;

        $lista = implode(', ', $categoriasExistentes);
        $prompt = "Dado este producto: \"{$nombre}\"\n"
            . "Y estas categorías existentes: [{$lista}]\n"
            . "Respondé ÚNICAMENTE con el nombre de la categoría que mejor corresponda. "
            . "Si no coincide con ninguna, respondé con la palabra 'NUEVA'. "
            . "No agregues explicaciones ni puntuación extra.";

        try {
            $response = Http::timeout(20)->post($this->endpoint($apiKey), [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 40, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ]);

            $text = trim($response->json('candidates.0.content.parts.0.text', ''));
            return $text && $text !== 'NUEVA' ? $text : null;
        } catch (\Exception $e) {
            Log::warning('Gemini category suggestion failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Genera una foto de producto estilo catálogo (fondo blanco, sin texto) con
     * el modelo de generación de imágenes de Gemini. Devuelve el contenido en
     * base64 (PNG) o null si la IA no devolvió ninguna imagen o falló la
     * llamada — el llamador decide qué hacer (mostrar error, dejar subir una
     * manual), nunca rompe el flujo de creación/edición del producto.
     */
    public function generarImagenProducto(string $nombre, ?string $categoria = null): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) return null;

        $prompt = 'Generá una foto de producto profesional estilo catálogo de e-commerce: '
            . "fondo blanco liso, buena iluminación de estudio, el producto centrado y ocupando "
            . "la mayor parte del cuadro, sin texto ni marcas de agua, formato cuadrado.\n"
            . "Producto: {$nombre}"
            . ($categoria ? " (categoría: {$categoria})" : '');

        try {
            $response = Http::timeout(30)->post($this->endpointImagen($apiKey), [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseModalities' => ['IMAGE']],
            ]);

            foreach ($response->json('candidates.0.content.parts', []) as $part) {
                if (!empty($part['inlineData']['data'])) {
                    return $part['inlineData']['data'];
                }
            }

            Log::warning('Gemini image generation returned no image part', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::warning('Gemini image generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Compone una única foto de combo a partir de las fotos reales de sus
     * productos componentes (referencia visual, no texto-a-imagen puro).
     * Nunca incluye precio ni texto en la imagen a propósito: los modelos de
     * IA generativa escriben texto/números de forma poco confiable, y el
     * precio real ya se muestra como texto normal al lado de la foto — así
     * nunca queda desactualizado si el precio del combo cambia después.
     *
     * $imagenesReferencia: array de ['data' => base64, 'mimeType' => string].
     */
    public function componerImagenCombo(array $imagenesReferencia, string $nombreCombo): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey || empty($imagenesReferencia)) return null;

        $prompt = 'Generá una única foto de producto profesional estilo catálogo de e-commerce que combine '
            . 'visualmente los productos de las imágenes de referencia en una sola composición: fondo blanco '
            . 'liso, buena iluminación de estudio, los productos centrados y bien distribuidos ocupando la mayor '
            . "parte del cuadro, formato cuadrado. IMPORTANTE: no agregues texto, números, precios ni marcas de "
            . "agua a la imagen.\n"
            . "Combo: {$nombreCombo}";

        $parts = [['text' => $prompt]];
        foreach ($imagenesReferencia as $img) {
            $parts[] = ['inlineData' => ['mimeType' => $img['mimeType'], 'data' => $img['data']]];
        }

        try {
            $response = Http::timeout(30)->post($this->endpointImagen($apiKey), [
                'contents' => [['parts' => $parts]],
                'generationConfig' => ['responseModalities' => ['IMAGE']],
            ]);

            foreach ($response->json('candidates.0.content.parts', []) as $part) {
                if (!empty($part['inlineData']['data'])) {
                    return $part['inlineData']['data'];
                }
            }

            Log::warning('Gemini combo image composition returned no image part', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::warning('Gemini combo image composition failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Asistente de IA del sistema (autenticado): responde preguntas usando
     * "tools" (function calling) que el propio llamador implementa — así
     * GeminiService no sabe nada de Eloquent/negocio, solo orquesta el ida y
     * vuelta con la API. Cuando Gemini pide ejecutar una tool, la corremos
     * acá mismo vía $ejecutarTool y le devolvemos el resultado real para que
     * arme la respuesta final en base a datos concretos, no inventados.
     *
     * $toolDeclarations: array de function declarations (formato Gemini).
     * $ejecutarTool: callable(string $nombre, array $args): array.
     * $historial: turnos previos [{role: 'user'|'ia', texto: string}].
     */
    public function preguntarAsistenteSistema(
        string $systemPrompt,
        array $toolDeclarations,
        callable $ejecutarTool,
        string $pregunta,
        array $historial = []
    ): ?string {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) return null;

        $contents = [];
        foreach (array_slice($historial, -6) as $turno) {
            $texto = trim((string) ($turno['texto'] ?? ''));
            if ($texto === '') continue;
            $contents[] = ['role' => ($turno['role'] ?? '') === 'ia' ? 'model' : 'user', 'parts' => [['text' => $texto]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $pregunta]]];

        $payloadBase = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'tools' => [['functionDeclarations' => $toolDeclarations]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 500, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ];

        try {
            // Hasta 3 idas y vueltas: Gemini puede encadenar más de una tool
            // antes de responder en texto (ej: buscar el producto y después
            // recién contestar). Se corta igual si se pasa, para no colgar
            // el request de un visitante.
            for ($vuelta = 0; $vuelta < 3; $vuelta++) {
                $response = Http::timeout(25)->post($this->endpoint($apiKey), $payloadBase + ['contents' => $contents]);
                $parts = $response->json('candidates.0.content.parts', []);

                if (empty($parts)) {
                    Log::warning('Gemini system assistant returned no parts', [
                        'status' => $response->status(),
                        'body'   => substr($response->body(), 0, 800),
                    ]);
                    return null;
                }

                $functionCallPart = null;
                $textoFinal = null;
                foreach ($parts as $part) {
                    if (!empty($part['functionCall'])) {
                        // Guardamos la parte COMPLETA (no solo functionCall) — trae un
                        // "id" y un "thoughtSignature" que la API espera recibir de vuelta
                        // tal cual en el siguiente turno, si no responde 400.
                        $functionCallPart = $part;
                    } elseif (!empty($part['text'])) {
                        $textoFinal = $part['text'];
                    }
                }

                if ($functionCallPart) {
                    $nombreTool = $functionCallPart['functionCall']['name'] ?? '';
                    $args = $functionCallPart['functionCall']['args'] ?? [];
                    $resultado = $ejecutarTool($nombreTool, is_array($args) ? $args : []);

                    $contents[] = ['role' => 'model', 'parts' => [$functionCallPart]];
                    $contents[] = ['role' => 'function', 'parts' => [['functionResponse' => ['name' => $nombreTool, 'response' => $resultado]]]];
                    continue;
                }

                return $textoFinal ? trim($textoFinal) : null;
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Gemini system assistant failed: ' . $e->getMessage());
            return null;
        }
    }

    private function endpoint(string $apiKey): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$apiKey}";
    }

    private function endpointImagen(string $apiKey): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key={$apiKey}";
    }
}
