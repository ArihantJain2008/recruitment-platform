<?php

function aiSetLastError($code) {
    $GLOBALS["AI_LAST_ERROR"] = (string)$code;
}

function aiGetLastError() {
    return isset($GLOBALS["AI_LAST_ERROR"]) ? (string)$GLOBALS["AI_LAST_ERROR"] : null;
}

function aiEvaluateCandidateText($requiredSkills, $requiredExperience, $candidateText) {
    aiSetLastError("");

    $provider = strtolower(aiGetEnv("AI_PROVIDER", "kimi"));
    $apiKey = "";
    $model = "";
    $endpoint = "";
    $headers = ["Content-Type: application/json"];
    $payload = [];

    if ($provider === "openai") {
        $apiKey = aiGetEnv("OPENAI_API_KEY");
        $model = aiGetEnv("OPENAI_MODEL", "gpt-4o-mini");
        $endpoint = "https://api.openai.com/v1/chat/completions";
    } elseif ($provider === "perplexity") {
        $apiKey = aiGetEnv("PERPLEXITY_API_KEY");
        $model = aiGetEnv("PERPLEXITY_MODEL", "sonar");
        $endpoint = "https://api.perplexity.ai/chat/completions";
    } elseif ($provider === "kimi" || $provider === "moonshot") {
        $apiKey = aiGetEnv("KIMI_API_KEY");
        if ($apiKey === "") {
            $apiKey = aiGetEnv("MOONSHOT_API_KEY");
        }

        $model = aiGetEnv("KIMI_MODEL", aiGetEnv("MOONSHOT_MODEL", "kimi-k2.5"));
        $baseUrl = rtrim(aiGetEnv("KIMI_BASE_URL", aiGetEnv("MOONSHOT_BASE_URL", "https://api.moonshot.ai/v1")), "/");
        $endpoint = $baseUrl . "/chat/completions";
    } elseif ($provider === "gemini" || $provider === "google") {
        $apiKey = aiGetEnv("GEMINI_API_KEY");
        if ($apiKey === "") {
            $apiKey = aiGetEnv("GOOGLE_API_KEY");
        }

        $model = aiGetEnv("GEMINI_MODEL", "gemini-1.5-flash");
        $baseUrl = rtrim(aiGetEnv("GEMINI_BASE_URL", "https://generativelanguage.googleapis.com/v1beta"), "/");
        $endpoint = $baseUrl . "/models/" . rawurlencode($model) . ":generateContent?key=" . rawurlencode($apiKey);
    } else {
        aiSetLastError("provider_unsupported");
        return null;
    }

    if ($apiKey === "") {
        aiSetLastError("api_key_missing");
        return null;
    }

    $normalizedSkills = array_values(array_filter(array_map("trim", (array)$requiredSkills), function ($skill) {
        return $skill !== "";
    }));

    $prompt = "You are a strict recruitment scoring assistant. Return JSON only, no markdown.\n\n"
        . "Evaluate the candidate text for the role.\n"
        . "Required skills: " . json_encode($normalizedSkills) . "\n"
        . "Required experience years: " . intval($requiredExperience) . "\n"
        . "Candidate text: " . $candidateText . "\n\n"
        . "Return compact valid JSON in one object exactly in this structure (double quotes only, no extra text):\n"
        . "{\"score\":0-100,\"matched_skills\":[],\"missing_skills\":[],\"summary\":\"short plain text summary\"}";

    if ($provider === "gemini" || $provider === "google") {
        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.1,
                "maxOutputTokens" => 450,
                "responseMimeType" => "application/json"
            ]
        ];
    } else {
        $payload = [
            "model" => $model,
            "temperature" => 0.1,
            "max_tokens" => 350,
            "messages" => [
                [
                    "role" => "system",
                    "content" => "You are a strict recruitment scoring assistant. Return JSON only, no markdown."
                ],
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ]
        ];
        $headers[] = "Authorization: Bearer " . $apiKey;
    }

    $rawResponse = aiPostJson(
        $endpoint,
        $payload,
        $headers
    );

    if ($rawResponse === null) {
        if (!aiGetLastError()) {
            aiSetLastError("request_failed");
        }
        return null;
    }

    $response = json_decode($rawResponse, true);
    if (!is_array($response)) {
        aiSetLastError("response_not_json");
        return null;
    }

    $content = aiExtractTextFromResponse($response);
    if ($content === "") {
        aiSetLastError("response_content_missing");
        return null;
    }

    $parsed = aiDecodeJsonObject((string)$content);
    if (!is_array($parsed)) {
        $snippet = preg_replace('/\s+/', ' ', (string)$content);
        $snippet = trim((string)$snippet);
        if (strlen($snippet) > 220) {
            $snippet = substr($snippet, 0, 220);
        }
        aiSetLastError("model_output_not_json:" . $snippet);
        return null;
    }

    $score = isset($parsed["score"]) ? intval($parsed["score"]) : null;
    if ($score === null) {
        aiSetLastError("score_missing");
        return null;
    }

    $score = max(0, min(100, $score));
    $matchedSkills = aiNormalizeSkills($parsed["matched_skills"] ?? []);
    $missingSkills = aiNormalizeSkills($parsed["missing_skills"] ?? []);
    $summary = trim((string)($parsed["summary"] ?? ""));

    return [
        "score" => $score,
        "matched_skills" => $matchedSkills,
        "missing_skills" => $missingSkills,
        "summary" => $summary,
        "model" => $model
    ];
}

function aiGetEnv($key, $default = "") {
    $sources = [];
    $sources[] = getenv($key);
    $sources[] = $_SERVER[$key] ?? null;
    $sources[] = $_ENV[$key] ?? null;

    foreach ($sources as $value) {
        if ($value === false || $value === null) {
            continue;
        }

        $value = trim((string)$value);
        if ($value !== "") {
            return $value;
        }
    }

    return $default;
}

function aiPostJson($url, $payload, $headers) {
    $jsonPayload = json_encode($payload);

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $response = curl_exec($ch);
        if ($response === false) {
            aiSetLastError("curl_exec_failed");
            curl_close($ch);
            return null;
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            aiSetLastError("http_" . $statusCode);
            return null;
        }

        return $response;
    }

    if (!function_exists("stream_context_create")) {
        aiSetLastError("no_http_client");
        return null;
    }

    $headerLines = implode("\r\n", $headers);
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => $headerLines . "\r\n",
            "content" => $jsonPayload,
            "timeout" => 25,
            "ignore_errors" => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        aiSetLastError("stream_request_failed");
        return null;
    }

    $statusCode = 0;
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $statusCode = intval($matches[1]);
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        aiSetLastError("http_" . $statusCode);
        return null;
    }

    return $response;
}

function aiExtractTextFromResponse($response) {
    if (!is_array($response)) {
        return "";
    }

    // OpenAI / Perplexity / Kimi style response.
    $content = $response["choices"][0]["message"]["content"] ?? null;
    if (is_string($content) && trim($content) !== "") {
        return $content;
    }
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $item) {
            if (isset($item["text"])) {
                $parts[] = (string)$item["text"];
            }
        }
        $joined = trim(implode("\n", $parts));
        if ($joined !== "") {
            return $joined;
        }
    }

    // Gemini style response.
    if (isset($response["candidates"]) && is_array($response["candidates"]) && !empty($response["candidates"])) {
        $first = $response["candidates"][0];
        $parts = $first["content"]["parts"] ?? [];
        if (is_array($parts)) {
            $texts = [];
            foreach ($parts as $part) {
                if (isset($part["text"])) {
                    $texts[] = (string)$part["text"];
                }
            }
            $joined = trim(implode("\n", $texts));
            if ($joined !== "") {
                return $joined;
            }
        }
    }

    return "";
}

function aiDecodeJsonObject($content) {
    $content = trim($content);
    if ($content === "") {
        return null;
    }

    if (strpos($content, "```") === 0) {
        $content = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', "", $content);
        $content = preg_replace('/```$/', "", trim($content));
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $firstBrace = strpos($content, "{");
    if ($firstBrace !== false) {
        $depth = 0;
        $inString = false;
        $escape = false;
        $start = -1;

        $length = strlen($content);
        for ($i = $firstBrace; $i < $length; $i++) {
            $ch = $content[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === "\\") {
                    $escape = true;
                    continue;
                }
                if ($ch === "\"") {
                    $inString = false;
                }
                continue;
            }

            if ($ch === "\"") {
                $inString = true;
                continue;
            }

            if ($ch === "{") {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($ch === "}") {
                $depth--;
                if ($depth === 0 && $start >= 0) {
                    $candidate = substr($content, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }
    }

    return null;
}

function aiNormalizeSkills($skills) {
    if (!is_array($skills)) {
        return [];
    }

    $normalized = [];
    foreach ($skills as $skill) {
        $clean = strtolower(trim((string)$skill));
        if ($clean === "") {
            continue;
        }
        $normalized[$clean] = true;
    }

    return array_keys($normalized);
}
