<?php

namespace App\Http\Middleware {
    use Closure;
    use Illuminate\Http\Request;

    class StartSession
    {
        /**
         * Handle an incoming request.
         *
         * @param Request $request
         * @param Closure $next
         * @return mixed
         */
        public function handle(Request $request, Closure $next)
        {
            // Force initialization of the session
            sessionHelper();

            return $next($request);
        }
    }
}

// Shortcuts for sessionHelper
namespace {
    use App\Player;
    use App\Helpers\SessionHelper;

    function sessionHelper(): SessionHelper
    {
        return SessionHelper::getInstance();
    }

    function sessionKey(): string
    {
        return sessionHelper()->getSessionKey();
    }

    function user(): ?Player
    {
        return sessionHelper()->getPlayer();
    }

    function license(): ?string
    {
        return sessionHelper()->getCurrentLicense();
    }

    function discord(): ?array
    {
        return sessionHelper()->getDiscord();
    }
}
