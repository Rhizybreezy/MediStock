<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\BusinessRuleException;
use App\Services\Support\AuthService;

final class LoginController extends Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    public function show(Request $request): Response
    {
        return view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'login'    => 'required|string|max:150',
            'password' => 'required|string|max:200',
        ], ['login' => 'Username or email']);

        try {
            $this->auth->attempt($data['login'], $data['password'], $request);
        } catch (BusinessRuleException $e) {
            Session::flash('_old', ['login' => $data['login']]);
            flash('danger', $e->getMessage());
            return redirect(url('/login'));
        }

        $intended = Session::get('intended_url');
        Session::forget('intended_url');
        return redirect(self::safeIntended($intended) ?? url('/'));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        Session::start($request->isSecure());
        flash('success', 'You have been signed out.');
        return redirect(url('/login'));
    }

    /** Only same-application relative paths are allowed (blocks //host and /\host open redirects). */
    public static function safeIntended(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || strlen($url) > 2000) {
            return null;
        }
        if (!str_starts_with($url, '/') || preg_match('#^/[/\\\\]#', $url) || preg_match('/[\x00-\x1f]/', $url)) {
            return null;
        }
        return str_starts_with($url, url('/')) ? $url : null;
    }
}
