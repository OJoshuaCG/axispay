<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Errors;

use App\Modules\Shared\Http\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders any exception raised on the API surface with the error envelope of
 * plan section 10.4:
 *
 *   {"error": {"type", "code", "message", "param", "request_id"}}
 *
 * Unknown exceptions become `500 internal_error` with a generic message, so
 * internal details never leak (the exception is still reported normally).
 */
final class ApiErrorRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $error = $this->toApiException($e);

        return new JsonResponse(
            [
                'error' => [
                    'type' => $error->errorCode->type()->value,
                    'code' => $error->errorCode->value,
                    'message' => $error->getMessage(),
                    'param' => $error->param,
                    'request_id' => RequestId::current(),
                ],
            ],
            $error->status(),
            $error->headers,
        );
    }

    private function toApiException(Throwable $e): ApiException
    {
        return match (true) {
            $e instanceof ApiException => $e,
            $e instanceof ValidationException => $this->fromValidation($e),
            $e instanceof AuthenticationException => ApiException::of(ApiErrorCode::InvalidApiKey),
            $e instanceof AccessDeniedHttpException => ApiException::of(ApiErrorCode::InsufficientScope),
            $e instanceof NotFoundHttpException => ApiException::of(ApiErrorCode::ResourceNotFound),
            $e instanceof MethodNotAllowedHttpException => new ApiException(
                ApiErrorCode::MethodNotAllowed,
                headers: $this->stringHeaders($e->getHeaders()),
            ),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => ApiException::of(ApiErrorCode::InternalError),
        };
    }

    private function fromValidation(ValidationException $e): ApiException
    {
        $failed = $e->validator->failed();
        $param = array_key_first($e->errors());
        $param = is_string($param) ? $param : null;
        $fieldRules = $param !== null ? ($failed[$param] ?? []) : [];
        $rules = is_array($fieldRules) ? array_keys($fieldRules) : [];
        $isMissing = array_intersect($rules, ['Required', 'Present', 'RequiredIf', 'RequiredWith', 'RequiredUnless']) !== [];
        $messages = $param !== null ? ($e->errors()[$param] ?? []) : [];
        $message = is_array($messages) ? ($messages[0] ?? null) : null;

        return new ApiException(
            $isMissing ? ApiErrorCode::ParameterMissing : ApiErrorCode::ParameterInvalid,
            is_string($message) ? $message : null,
            $param,
        );
    }

    private function fromHttpException(HttpExceptionInterface $e): ApiException
    {
        $status = $e->getStatusCode();
        $headers = $this->stringHeaders($e->getHeaders());

        $code = match (true) {
            $status === 401 => ApiErrorCode::InvalidApiKey,
            $status === 403 => ApiErrorCode::InsufficientScope,
            $status === 404 => ApiErrorCode::ResourceNotFound,
            $status === 429 => ApiErrorCode::RateLimited,
            $status === 503 => ApiErrorCode::ServiceUnavailable,
            $status >= 400 && $status < 500 => ApiErrorCode::ParameterInvalid,
            default => ApiErrorCode::InternalError,
        };

        return new ApiException($code, headers: $headers);
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }
}
