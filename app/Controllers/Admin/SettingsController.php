<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Support\AuditService;
use App\Services\Support\SettingsService;

final class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private AuditService $audit)
    {
    }

    public function index(Request $request): Response
    {
        return view('admin/settings/index', ['title' => 'System settings', 'groups' => $this->settings->grouped()]);
    }

    public function update(Request $request): Response
    {
        $changes = $this->settings->update($request->body(), $this->userId());
        if ($changes) {
            $this->audit->log('update', 'settings', null, 'Updated ' . count($changes) . ' setting(s)',
                array_map(fn ($c) => $c[0], $changes), array_map(fn ($c) => $c[1], $changes));
            flash('success', count($changes) . ' setting(s) saved.');
        } else {
            flash('info', 'No changes to save.');
        }
        return redirect(route('admin.settings'));
    }
}
