<?php

namespace App\Exceptions;

use App\Exceptions\Subscription\FeatureNotAllowedException;
use App\Exceptions\Subscription\NoActiveSubscriptionException;
use App\Exceptions\Subscription\QuotaExceededException;
use App\Exceptions\Subscription\SubscriptionException;
use App\Exceptions\Subscription\SubscriptionExpiredException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var string[]
     */
    protected $dontReport = [
        // Don't report subscription exceptions (expected behavior)
        NoActiveSubscriptionException::class,
        FeatureNotAllowedException::class,
        QuotaExceededException::class,
        SubscriptionExpiredException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var string[]
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Handle subscription exceptions
        $this->renderable(function (SubscriptionException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                    'upgrade_url' => route('subscription.index'),
                ], $e->getHttpStatusCode());
            }

            // Web request - redirect based on exception type
            $route = 'subscription.index';
            $errorType = 'subscription_error';

            if ($e instanceof NoActiveSubscriptionException) {
                $errorType = 'no_subscription';
            } elseif ($e instanceof SubscriptionExpiredException) {
                $errorType = 'subscription_expired';
            } elseif ($e instanceof FeatureNotAllowedException) {
                $errorType = 'feature_not_allowed';
            } elseif ($e instanceof QuotaExceededException) {
                $errorType = 'quota_exceeded';
            }

            return redirect()
                ->route($route)
                ->with('error', $e->getMessage())
                ->with('error_type', $errorType);
        });
    }
}
