<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Support\AuditService;
use Throwable;

/**
 * Turns exceptions into safe responses.
 * - Users never see stack traces or SQL in production (APP_DEBUG=false).
 * - JSON requests get {ok:false, error:{code,message,fields}}.
 * - Form submissions with validation/business errors are redirected back
 *   with the messages and the previous input.
 */
final class ExceptionHandler
{
    public function __construct(private Logger $logger)
    {
    }

    public function render(Throwable $e, Request $request): Response
    {
        $json = $request->wantsJson();

        if ($e instanceof ValidationException) {
            if ($json) {
                return Response::json(['ok' => false, 'error' => [
                    'code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors(),
                ]], 422);
            }
            Session::flash('_errors', $e->errors());
            Session::flash('_old', $this->safeOldInput($request));
            flash('danger', $e->getMessage());
            return Response::redirect($request->previousUrl());
        }

        if ($e instanceof BusinessRuleException) {
            if ($json) {
                return Response::json(['ok' => false, 'error' => ['code' => 'business_rule', 'message' => $e->getMessage()]], 422);
            }
            Session::flash('_old', $this->safeOldInput($request));
            flash('danger', $e->getMessage());
            return Response::redirect($request->previousUrl());
        }

        if ($e instanceof AuthorizationException) {
            try {
                app(AuditService::class)->log('access_denied', null, null, mb_substr($request->method() . ' ' . $request->path() . ' [' . ($e->permission() ?? '-') . ']', 0, 255));
            } catch (Throwable) {
            }
            return $this->error(403, $e->getMessage(), $request);
        }

        if ($e instanceof HttpException) {
            $response = $this->error($e->status(), $e->getMessage(), $request, null, $e->title());
            foreach ($e->headers() as $k => $v) {
                $response->header($k, $v);
            }
            return $response;
        }

        $this->logger->error(get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request->method() . ' ' . $request->path(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 4000),
        ]);
        $message = Config::get('app.debug')
            ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            : 'An unexpected error occurred. The problem has been logged.';
        return $this->error(500, $message, $request, Config::get('app.debug') ? $e->getTraceAsString() : null);
    }

    private function error(int $status, string $message, Request $request, ?string $trace = null, ?string $title = null): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => ['code' => 'http_' . $status, 'message' => $message]], $status);
        }
        $titles = [
            403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed',
            429 => 'Too many requests', 500 => 'Something went wrong',
        ];
        try {
            $html = View::render('errors/error', [
                'status' => $status,
                'title'  => $title ?? $titles[$status] ?? 'Error',
                'message'=> $message,
                'trace'  => $trace,
            ], 'layouts/minimal');
        } catch (Throwable) {
            $html = '<!doctype html><title>Error ' . $status . '</title><h1>Error ' . $status . '</h1><p>' . e($message) . '</p>';
        }
        return new Response($html, $status);
    }

    private function safeOldInput(Request $request): array
    {
        $input = $request->body();
        foreach (['password', 'password_confirmation', 'current_password', 'new_password', 'new_password_confirmation', '_csrf'] as $key) {
            unset($input[$key]);
        }
        return $input;
    }
}
