<?php

namespace Irabbi360\LaravelDebugMate\Support;

/**
 * Shrinks report payloads to fit within configured byte limit.
 */
class PayloadLimiter
{
    protected int $maxBytes;

    public function __construct(?int $maxBytes = null)
    {
        $this->maxBytes = $maxBytes ?? (int) config('debugmate.max_payload_size', 1048576);
    }

    public function fit(array $payload): array
    {
        if ($this->maxBytes <= 0 || $this->byteSize($payload) <= $this->maxBytes) {
            return $payload;
        }

        $originalSize = $this->byteSize($payload);
        $payload = $this->applyTrimSteps($payload);

        if ($this->byteSize($payload) > $this->maxBytes) {
            $payload = $this->stripHeavyFields($payload);
        }

        $payload['_payload_truncated'] = true;
        $payload['_payload_original_bytes'] = $originalSize;
        $payload['_payload_max_bytes'] = $this->maxBytes;

        return $payload;
    }

    public function byteSize(array $payload): int
    {
        return strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    protected function applyTrimSteps(array $payload): array
    {
        $steps = [
            fn (array $data) => $this->dropStackTraceIfFrames($data),
            fn (array $data) => $this->limitListFields($data, [
                'frames' => 50,
                'spans' => 100,
                'all_queries' => 50,
                'slow_queries' => 50,
                'job_spans' => 50,
                'command_spans' => 50,
                'http_client_spans' => 50,
                'view_spans' => 50,
                'livewire_spans' => 50,
            ]),
            fn (array $data) => $this->limitNestedQueryLog($data, 50),
            fn (array $data) => $this->truncateContextStrings($data),
            fn (array $data) => $this->limitListFields($data, [
                'frames' => 25,
                'spans' => 50,
                'all_queries' => 25,
                'slow_queries' => 25,
                'job_spans' => 25,
                'command_spans' => 25,
                'http_client_spans' => 25,
                'view_spans' => 25,
                'livewire_spans' => 25,
            ]),
            fn (array $data) => $this->limitNestedQueryLog($data, 25),
            fn (array $data) => $this->limitListFields($data, [
                'frames' => 10,
                'spans' => 20,
                'all_queries' => 10,
                'slow_queries' => 10,
                'job_spans' => 10,
                'command_spans' => 10,
                'http_client_spans' => 10,
                'view_spans' => 10,
                'livewire_spans' => 10,
            ]),
        ];

        foreach ($steps as $step) {
            $payload = $step($payload);

            if ($this->byteSize($payload) <= $this->maxBytes) {
                return $payload;
            }
        }

        return $payload;
    }

    protected function dropStackTraceIfFrames(array $payload): array
    {
        if (! empty($payload['frames']) && isset($payload['stack_trace'])) {
            unset($payload['stack_trace']);
        }

        return $payload;
    }

    /**
     * @param  array<string, int>  $limits
     */
    protected function limitListFields(array $payload, array $limits): array
    {
        foreach ($limits as $field => $limit) {
            if (! isset($payload[$field]) || ! is_array($payload[$field])) {
                continue;
            }

            if (count($payload[$field]) > $limit) {
                $payload[$field] = array_slice($payload[$field], 0, $limit);
                $payload["_truncated_{$field}"] = true;
            }
        }

        return $payload;
    }

    protected function limitNestedQueryLog(array $payload, int $limit): array
    {
        if (! isset($payload['context']['queries']['queries'])
            || ! is_array($payload['context']['queries']['queries'])) {
            return $payload;
        }

        $queries = $payload['context']['queries']['queries'];

        if (count($queries) > $limit) {
            $payload['context']['queries']['queries'] = array_slice($queries, 0, $limit);
            $payload['context']['queries']['executed'] = count($payload['context']['queries']['queries']);
            $payload['_truncated_context_queries'] = true;
        }

        return $payload;
    }

    protected function truncateContextStrings(array $payload, int $maxLength = 500): array
    {
        if (isset($payload['context']) && is_array($payload['context'])) {
            $payload['context'] = $this->truncateStringsRecursive($payload['context'], $maxLength);
        }

        if (isset($payload['message']) && is_string($payload['message']) && strlen($payload['message']) > $maxLength) {
            $payload['message'] = substr($payload['message'], 0, $maxLength).'...';
        }

        return $payload;
    }

    protected function truncateStringsRecursive(array $data, int $maxLength): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && strlen($value) > $maxLength) {
                $data[$key] = substr($value, 0, $maxLength).'...';
            } elseif (is_array($value)) {
                $data[$key] = $this->truncateStringsRecursive($value, $maxLength);
            }
        }

        return $data;
    }

    protected function stripHeavyFields(array $payload): array
    {
        foreach ([
            'all_queries',
            'slow_queries',
            'spans',
            'job_spans',
            'command_spans',
            'http_client_spans',
            'view_spans',
            'livewire_spans',
            'stack_trace',
        ] as $field) {
            unset($payload[$field]);
        }

        if (isset($payload['frames']) && is_array($payload['frames'])) {
            $payload['frames'] = array_slice($payload['frames'], 0, 5);
        }

        if (isset($payload['context']) && is_array($payload['context'])) {
            unset($payload['context']['queries']);
        }

        return $payload;
    }
}
