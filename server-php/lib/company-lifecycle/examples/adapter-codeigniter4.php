<?php
// CodeIgniter 4 — the route, the write gate and the kit, as a product wires them.
// Kit vendored at server-php/lib/company-lifecycle/ (see README § Vendor).

// app/Config/Services.php (or any shared factory): one Kit per request.
//   public static function lifecycleKit(bool $getShared = true): \Aicountly\Lifecycle\Kit
//   {
//       if ($getShared) { return static::getSharedInstance('lifecycleKit'); }
//       require_once ROOTPATH . 'lib/company-lifecycle/autoload.php';
//       return \Aicountly\Lifecycle\Kit::fromEnv('books', manifest: APPPATH . 'Config/lifecycle-manifest.php');
//   }

// app/Config/Routes.php — outside every session/auth filter (the HMAC is the only credential):
//   $routes->post('api/internal/manage/company-lifecycle', 'Api\CompanyLifecycleController::receive');

namespace App\Controllers\Api;

use CodeIgniter\Controller;

class CompanyLifecycleController extends Controller
{
    public function receive()
    {
        [$status, $json] = service('lifecycleKit')->receiver()->handle((string) $this->request->getBody(), $this->request->headers());

        return $this->response->setStatusCode($status)->setJSON($json);
    }
}

// The write gate — in the base controller, right after the company-access check passed:
//   $refusal = service('lifecycleKit')->guard(self::LIFECYCLE_ALLOW)->check($cmpId, $this->request->getMethod(), $this->request->getPath());
//   if ($refusal !== null) { return $this->response->setStatusCode($refusal[0])->setJSON($refusal[1]); }
// and where the product reads Manage companyinfo:
//   service('lifecycleKit')->guard()->syncFromCompanyInfo($cmpId, $companyInfo['data'] ?? []);
