<?php

/*
|--------------------------------------------------------------------------
| Local LLM (Ollama) configuration
|--------------------------------------------------------------------------
| The LLM only GENERATES a structured command. It never decides scope.
| Ollama must stay on loopback; LlmService refuses any non-loopback URL.
*/

return [

    'base_url' => rtrim((string) env('LLM_BASE_URL', 'http://127.0.0.1:11434'), '/'),

    'model' => (string) env('LLM_MODEL', 'deepseek-coder:6.7b'),

    // Seconds. A 6.7B model can be slow on first load.
    'connect_timeout' => (float) env('LLM_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('LLM_TIMEOUT', 120),
    'health_timeout' => 3,

    // Deterministic, short output.
    'temperature' => 0,
    'num_predict' => 256,

    'max_instruction_chars' => 1000,
    'max_response_bytes' => 65536,

];
