<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Profile;
use App\Services\AdminService;
use App\Services\LogService;
use App\Services\SettingService;
use App\Services\WebAuthService;

class AdminApiController extends Controller
{
    protected $adminService;
    protected $settingService;
    protected $webAuthService;
    protected $logService;

    public function __construct(
        AdminService $adminService,
        SettingService $settingService,
        WebAuthService $webAuthService,
        LogService $logService
    ) {
        $this->adminService = $adminService;
        $this->settingService = $settingService;
        $this->webAuthService = $webAuthService;
        $this->logService = $logService;
    }

    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'server_version' => SettingService::$server_version,
            ],
        ]);
    }

    public function getSettings()
    {
        $this->settingService->initializeDefaultSettings();

        $storageType = $this->settingService->get('storage_type', 'local');
        $s3 = $this->settingService->getS3Config();
        $cacheExt = $this->settingService->get('cache_extension', 'off');
        $writeLog = $this->settingService->get('write_log', 'off');

        return response()->json([
            'success' => true,
            'data' => [
                'storage_type' => $storageType,
                's3' => [
                    'S3_KEY' => $s3->S3_KEY,
                    'S3_PASSWORD' => $s3->S3_PASSWORD,
                    'S3_BUCKET' => $s3->S3_BUCKET,
                    'S3_REGION' => $s3->S3_REGION,
                    'S3_ENDPOINT' => $s3->S3_ENDPOINT,
                    'S3_ENDPOINT_ENABLED' => $s3->S3_ENDPOINT_ENABLED,
                ],
                'cache_extension' => $cacheExt,
                'write_log' => $writeLog,
                'server_version' => SettingService::$server_version,
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        // Make sure the logs table exists before users toggle write_log on.
        $this->logService->ensureTableExists();

        $message = $this->adminService->saveSettings(
            $request->input('storage_type', 'local'),
            $request->input('S3_KEY'),
            $request->input('S3_PASSWORD'),
            $request->input('S3_BUCKET'),
            $request->input('S3_REGION'),
            $request->boolean('cache_extension') ? 'on' : 'off',
            $request->boolean('write_log') ? 'on' : 'off',
            $request->input('S3_ENDPOINT'),
            $request->boolean('S3_ENDPOINT_ENABLED') ? 'on' : 'off'
        );

        return response()->json(['success' => true, 'message' => $message]);
    }

    public function getUsers(Request $request)
    {
        $loginUser = Auth::user();
        $search = trim((string) $request->query('search', ''));

        $query = User::where('id', '<>', $loginUser->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('email')->get();

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function toggleActiveUser($id)
    {
        $ok = $this->adminService->toggleUserActiveStatus($id);

        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'user_not_found'], 404);
        }

        $user = User::find($id);
        return response()->json(['success' => true, 'data' => $user]);
    }

    public function resetUserPassword($id)
    {
        $result = $this->adminService->resetUserPassword($id);
        $status = $result['success'] ? 200 : 404;
        return response()->json($result, $status);
    }

    public function resetProfileStatus()
    {
        $this->adminService->resetProfileStatuses();
        return response()->json(['success' => true, 'message' => 'Reset profile status successfully']);
    }

    public function runMigrations()
    {
        $result = $this->adminService->runMigrations();
        return response()->json($result);
    }

    public function logout(Request $request)
    {
        $this->webAuthService->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true]);
    }

    /**
     * Batch lookup of local storage sizes. Input: ids[]= (query). Output:
     * { id: { bytes: int, exists: bool, path: string } } for each profile
     * whose storage_path resolves to an existing file on the host.
     */
    public function profileStorageSizes(Request $request)
    {
        $ids = $request->query('ids', []);
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }
        if (!is_array($ids) || count($ids) === 0) {
            return response()->json(['success' => true, 'data' => new \stdClass()]);
        }

        $profiles = Profile::whereIn('id', $ids)
            ->select('id', 'storage_path')
            ->get();

        $result = [];
        foreach ($profiles as $profile) {
            $path = (string) $profile->storage_path;
            if ($path === '' || !preg_match('#^storage/profiles/#i', $path)) {
                continue;
            }

            // "storage/profiles/abc.zip" → "profiles/abc.zip" → {project}/storage/app/public/profiles/abc.zip
            $relative = ltrim(preg_replace('#^storage/#i', '', $path), '/');
            $absolute = storage_path('app/public/' . $relative);

            if (is_file($absolute)) {
                $result[$profile->id] = [
                    'bytes' => @filesize($absolute) ?: 0,
                    'exists' => true,
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
