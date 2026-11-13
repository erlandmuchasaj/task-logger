<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who and where an audited change came from (D-38, BR-32): the client IP, the user agent and, when
 * someone is acting as another user, the real person behind it (the impersonator).
 *
 * The causer of an entry is still the user being acted as; `impersonator_id` says who really did it.
 * Everything is null outside an HTTP request (console, queued jobs). Impersonation is read from the
 * session key lab404/laravel-impersonate uses, so this works as soon as that package, or any code that
 * sets the same key, starts an impersonation (M6, optional).
 */
final class ActivityContext
{
    private const USER_AGENT_LIMIT = 1000;

    /** @var \WeakMap<Request, int|null> resolved impersonator per request (a WeakMap never confuses a new request with a freed one) */
    private \WeakMap $impersonators;

    public function __construct()
    {
        $this->impersonators = new \WeakMap;
    }

    /** @var array{client_ip?: ?string, user_agent?: ?string, impersonator_id?: ?int}|null context captured earlier (queued jobs) */
    private ?array $override = null;

    /**
     * The context of the current request, to carry into a queued job (D-38).
     *
     * @return array{client_ip: ?string, user_agent: ?string, impersonator_id: ?int}
     */
    public function snapshot(): array
    {
        return ['client_ip' => $this->clientIp(), 'user_agent' => $this->userAgent(), 'impersonator_id' => $this->impersonatorId()];
    }

    /**
     * Runs the callback with a context captured earlier, e.g. the request that queued an export.
     *
     * @param  array{client_ip?: ?string, user_agent?: ?string, impersonator_id?: ?int}  $context
     */
    public function using(array $context, \Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $context;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function clientIp(): ?string
    {
        if ($this->override !== null) {
            return $this->override['client_ip'] ?? null;
        }

        $ip = $this->request()->ip();

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    public function userAgent(): ?string
    {
        if ($this->override !== null) {
            return $this->override['user_agent'] ?? null;
        }

        $agent = $this->request()->userAgent();

        return $agent === null || $agent === '' ? null : Str::limit($agent, self::USER_AGENT_LIMIT, '');
    }

    /** The impersonator's user id, or null when nobody is impersonating (or the id is stale). */
    public function impersonatorId(): ?int
    {
        if ($this->override !== null) {
            return $this->override['impersonator_id'] ?? null;
        }

        $request = $this->request();

        if (isset($this->impersonators[$request])) {
            return $this->impersonators[$request];
        }

        if ($this->impersonators->offsetExists($request)) {
            return null; // already resolved as "nobody"
        }

        $id = $this->session($request)?->get((string) config('task-logger.impersonation.session_key'));

        // A stale or forged id must never break the audited write: the column is a foreign key.
        $valid = is_numeric($id) && User::query()->withTrashed()->whereKey((int) $id)->exists();

        return $this->impersonators[$request] = $valid ? (int) $id : null;
    }

    /** The request's session, or the app's session store when the request carries none (Livewire, tests). Null when no session is started. */
    private function session(Request $request): ?Session
    {
        $store = $request->hasSession() ? $request->session() : (app()->bound('session.store') ? app('session.store') : null);

        return $store?->isStarted() ? $store : null;
    }

    private function request(): Request
    {
        return app('request');
    }
}
