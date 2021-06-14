<?php

namespace App\Http\Middleware;

use App\Helpers\LoggingHelper;
use App\Helpers\SessionHelper;
use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to check if user is a staff member on our game-servers.
 *
 * @package App\Http\Middleware
 */
class StaffMiddleware
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
        // Check for staff status.
        if (!$this->isStaff($request)) {
            $session = SessionHelper::getInstance();
            LoggingHelper::log($session->getSessionKey(), 'StaffMiddleware user is not staff, dropping session');
            LoggingHelper::log($session->getSessionKey(), 'session.user->' . json_encode($session->get('user')));

            SessionHelper::drop();

            return redirect('/login')->with('error',
                'You must be a staff member to access the dashboard! If you believe this is a mistake, contact a developer.'
            );
        }

        return $next($request);
    }

    /**
     * Checks if the user that sent request is staff.
     *
     * @param Request $request
     * @return bool
     */
    protected function isStaff(Request $request): bool
    {
        $session = SessionHelper::getInstance();

        if ($session->exists('user')) {
            $user = $session->get('user');

            $request->setUserResolver(function () use ($user) {
                return json_decode(json_encode($user), FALSE);
            });

            return !empty($user['player']) && $user['player']['is_staff'];
        } else {
            LoggingHelper::log($session->getSessionKey(), 'StaffMiddleware "user" is not set in session');
        }
        return false;
    }

}
