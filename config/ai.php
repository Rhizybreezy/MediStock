<?php
/**
 * MediStock's own Gemini configuration. Independent from any other project.
 * The API key is read on the server only and is never sent to the browser.
 */
return [
    'api_key'  => env('GEMINI_API_KEY', ''),
    'model'    => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    // 'low' keeps answers fast; empty = model default (slower, more deliberate)
    'thinking_level' => (string) env('GEMINI_THINKING_LEVEL', 'low'),
    // Tried in order when the primary model is overloaded (503) or times out.
    'fallback_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_FALLBACK_MODELS', 'gemini-3.5-flash,gemini-3.8-flash'))))),
    'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    'timeout'  => (int) env('GEMINI_TIMEOUT', 30),
    'max_tool_rounds'    => 5,
    'max_message_chars'  => 2000,
    'history_messages'   => 20,
];
