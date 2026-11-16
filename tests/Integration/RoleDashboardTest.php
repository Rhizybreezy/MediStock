<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\DashboardController;
use App\Core\App;
use App\Core\Request;
use App\Services\Dashboard\DashboardResolver;
use App\Services\Dashboard\RoleDashboardService;

/**
 * Each role gets its own dashboard; every link and button on it opens for that role,
 * and the management dashboard offers no create/edit actions.
 */
final class RoleDashboardTest extends DatabaseTestCase
{
    private const EXPECTED = [
        'admin'       => ['admin', 'System Administrator'],
        'manager'     => ['manager', 'Hospital Management'],
        'inventory'   => ['inventory', 'Inventory Manager'],
        'procurement' => ['procurement', 'Procurement Officer'],
        'pharmacist'  => ['pharmacist', 'Pharmacist'],
        'maternity'   => ['storekeeper', 'Department Storekeeper'],
    ];

    private function render(string $username): string
    {
        $this->actingAs($username);
        $app = App::instance();
        $app->request = Request::create('GET', '/');
        $response = app(DashboardController::class)->index($app->request);
        $this->assertSame(200, $response->status());
        return $response->body();
    }

    /** Paths of all internal page links in the HTML (static assets excluded). */
    private function links(string $html): array
    {
        preg_match_all('/href="([^"#]+)"/', $html, $m);
        $out = [];
        foreach (array_unique($m[1]) as $href) {
            $path = (string) parse_url(html_entity_decode($href), PHP_URL_PATH);
            $base = Request::basePath();
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base)) ?: '/';
            }
            if (!str_starts_with($path, '/assets/')) {
                $out[] = $path;
            }
        }
        return $out;
    }

    public function testEachRoleResolvesToItsOwnDashboard(): void
    {
        foreach (self::EXPECTED as $user => [$type]) {
            $id = $this->actingAs($user);
            $this->assertSame($type, app(DashboardResolver::class)->type($id), "$user dashboard");
        }
    }

    public function testCustomRoleFallsBackToGeneralDashboard(): void
    {
        $uid = $this->actingAs('maternity');
        $roleId = $this->db->insert('roles', ['code' => 'ward_clerk_' . bin2hex(random_bytes(2)), 'name' => 'Ward clerk', 'scope' => 'department', 'is_system' => 0]);
        $this->db->execute('DELETE FROM user_roles WHERE user_id = :u', ['u' => $uid]);
        $this->db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', ['u' => $uid, 'r' => $roleId]);
        $this->assertSame('general', app(DashboardResolver::class)->type($uid));
    }

    public function testEveryDashboardRendersWithItsRoleAndOnlyAllowedLinks(): void
    {
        $router = App::instance()->router;
        foreach (self::EXPECTED as $user => [, $roleLabel]) {
            $html = $this->render($user);
            $this->assertStringContainsString('me-1">' . $roleLabel . '</span>', $html, "$user sees the $roleLabel dashboard");
            $gate = app(\App\Core\Gate::class);
            foreach ($this->links($html) as $path) {
                $route = null;
                foreach ($router->routes() as $r) {
                    if ($r->method === 'GET' && $r->match($path) !== null) {
                        $route = $r;
                        break;
                    }
                }
                $this->assertNotNull($route, "$user: link $path has no route");
                foreach ($route->middleware as $mw) {
                    if (str_starts_with($mw, 'can:')) {
                        $this->assertTrue($gate->canAny(explode('|', substr($mw, 4))), "$user: link $path is forbidden ($mw)");
                    }
                }
            }
        }
    }

    public function testManagementDashboardHasNoCreateOrEditActions(): void
    {
        $html = $this->render('manager');
        foreach (['/create', '/receipts/po', '/issues/create', '/consumption/create', '/adjustments/create', '/from-requests'] as $fragment) {
            $this->assertStringNotContainsString($fragment . '"', $html, "Manager dashboard must not link to $fragment");
        }
    }

    public function testApprovalQueueExcludesOwnSubmissions(): void
    {
        $uid = $this->actingAs('manager');
        $before = array_column(app(RoleDashboardService::class)->approvalQueue(), 'count', 'label')['Purchase requests to approve'];
        $cs = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'CS'");
        $this->db->insert('purchase_requests', ['pr_number' => 'PR-T-' . bin2hex(random_bytes(3)), 'department_id' => $cs, 'source' => 'manual', 'priority' => 'routine', 'status' => 'submitted', 'requested_by' => $uid]);
        $after = array_column(app(RoleDashboardService::class)->approvalQueue(), 'count', 'label')['Purchase requests to approve'];
        $this->assertSame($before, $after, 'A request the manager submitted is not counted as awaiting the manager');
    }

    public function testEveryDashboardOffersTheAssistant(): void
    {
        foreach (array_keys(DashboardResolver::TITLES) as $type) {
            $this->assertNotEmpty(RoleDashboardService::aiPrompts($type), "$type has assistant prompts");
        }
        foreach (array_keys(self::EXPECTED) as $user) {
            $this->actingAs($user);
            $this->assertTrue(app(\App\Core\Gate::class)->can('ai.use'), "$user can use the assistant");
        }
    }
}
