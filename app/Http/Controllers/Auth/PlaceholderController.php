<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Temporary backing for the auth/account route names.
 *
 * The public site links to login, register and the account area, so those route
 * names have to resolve for Phase 2 to render. Phase 3 replaces this class and
 * the routes/auth.php entries with the real controllers.
 */
class PlaceholderController extends Controller
{
    public function __invoke(): View
    {
        return view('auth.placeholder');
    }
}
