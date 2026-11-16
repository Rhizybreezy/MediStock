<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Support\AuthService;

final class PasswordController extends Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    public function show(Request $request): Response
    {
        $forced = (int) (user()['must_change_password'] ?? 0) === 1;
        return view('auth/change-password', ['title' => 'Change password', 'forced' => $forced], $forced ? 'layouts/auth' : 'layouts/app');
    }

    public function update(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string|max:200',
            'new_password'     => 'required|string|max:200|password|confirmed',
        ]);
        $this->auth->changePassword($this->userId(), $data['current_password'], $data['new_password']);
        flash('success', 'Your password has been changed.');
        return redirect(url('/'));
    }
}
