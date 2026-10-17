<?php

declare(strict_types=1);

namespace PhpServer\Protocol\TextRpc;

use PhpServer\Exception\ProtocolException;

/**
 * JSON bodies for REQ, RES, and ERR.
 *
 * Invalid UTF-8 and malformed JSON become {@see ErrorCode::ParseError}
 * (-32700) with `frameLevel` false, so the connection stays open. A JSON
 * value of the wrong shape becomes {@see ErrorCode::InvalidRequest}
 * (-32600), also body-level. Decoding never builds a class other than
 * `stdClass`, and the depth limit is 64.
 *
 * @internal
 */
final class JsonCodec
{
    public const string NAME = 'json';

    public const int MAX_DEPTH = 64;

    private const int ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<array-key, mixed>|\stdClass|null $params
     */
    public function encodeRequest(string $method, array|\stdClass|null $params = null): string
    {
        if ($method === '') {
            throw new \InvalidArgumentException('method must be a non-empty string');
        }
        $payload = ['method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        return $this->encode($payload);
    }

    public function encodeResult(mixed $result): string
    {
        return $this->encode(['result' => $result]);
    }

    public function encodeError(ErrorBody $error): string
    {
        $payload = [
            'code' => $error->code,
            'message' => $error->message,
        ];
        if ($error->hasData) {
            $payload['data'] = $error->data;
        }

        return $this->encode(['error' => $payload]);
    }

    public function decodeRequest(string $body): RequestBody
    {
        $value = $this->decode($body);
        if (!$value instanceof \stdClass) {
            throw $this->invalid('Request body must be a JSON object');
        }
        $fields = get_object_vars($value);
        if (!array_key_exists('method', $fields) || !is_string($fields['method']) || $fields['method'] === '') {
            throw $this->invalid('Request method must be a non-empty string');
        }
        $params = null;
        if (array_key_exists('params', $fields)) {
            $raw = $fields['params'];
            if (!$raw instanceof \stdClass && !is_array($raw)) {
                throw $this->invalid('Request params must be a JSON array or object');
            }
            $normalized = $this->normalize($raw);
            if (!is_array($normalized)) {
                throw $this->invalid('Request params must be a JSON array or object');
            }
            $params = $normalized;
        }

        return new RequestBody($fields['method'], $params);
    }

    public function decodeResult(string $body): mixed
    {
        $value = $this->decode($body);
        if (!$value instanceof \stdClass || !property_exists($value, 'result')) {
            throw $this->invalid('Result body must be an object with a result');
        }

        return $this->normalize($value->result);
    }

    public function decodeError(string $body): ErrorBody
    {
        $value = $this->decode($body);
        if (!$value instanceof \stdClass || !isset($value->error) || !$value->error instanceof \stdClass) {
            throw $this->invalid('Error body must be an object with an error');
        }
        $error = $value->error;
        if (!property_exists($error, 'code') || !is_int($error->code)) {
            throw $this->invalid('Error code must be an integer');
        }
        if (!property_exists($error, 'message') || !is_string($error->message)) {
            throw $this->invalid('Error message must be a string');
        }
        $hasData = property_exists($error, 'data');

        return new ErrorBody(
            $error->code,
            $error->message,
            $hasData ? $this->normalize($error->data) : null,
            $hasData,
        );
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS, self::MAX_DEPTH);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Cannot encode JSON body', 0, $exception);
        }
    }

    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ProtocolException(
                'Invalid JSON body',
                ErrorCode::ParseError->value,
                $exception,
                false,
            );
        }
    }

    private function invalid(string $message): ProtocolException
    {
        return new ProtocolException($message, ErrorCode::InvalidRequest->value, null, false);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $object = [];
            foreach (get_object_vars($value) as $key => $item) {
                $object[$key] = $this->normalize($item);
            }

            return $object;
        }
        if (is_array($value)) {
            $list = [];
            foreach ($value as $key => $item) {
                $list[$key] = $this->normalize($item);
            }

            return $list;
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw $this->invalid('Unsupported JSON value');
    }
}
