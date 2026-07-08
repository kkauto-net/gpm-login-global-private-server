<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class UploadService
{
    protected SettingService $settingService;
    protected S3UploadService $s3UploadService;

    public function __construct(SettingService $settingService, S3UploadService $s3UploadService)
    {
        $this->settingService = $settingService;
        $this->s3UploadService = $s3UploadService;
    }
    /**
     * Store uploaded file
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $fileName
     * @return array
     */
    public function storeFile($file, string $fileName,
            ?string $checksumMD5 = null)
    {
        try {
            // PUT binary >= v16
            if (is_resource($file)) {
                return $this->storeRawStreamLocally($file, $fileName, $checksumMD5);
            }

            // Nếu là string (raw binary content)
            // PUT binary <= v15
            if (is_string($file)) {
                if (strlen($file) > 0) {
                    return $this->storeRawContentLocally($file, $fileName,
                            $checksumMD5);
                } else {
                    return [
                        'success' => false,
                        'message' => 'upload_failed',
                        'data' => ['message' => 'invalid_file_input']
                    ];
                }
            }

            // Nếu là UploadedFile
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->getSize() > 0) {
                $this->settingService->initializeDefaultSettings();

                // POST form-data
                $storageType = $this->settingService->getSetting('storage_type')->value ?? 'local';

                if ($storageType === 's3') {
                    return [
                        'success' => false,
                        'message' => 'use_endpoint_create_upload_url',
                        'data' => ['message' => 's3_upload_not_supported']
                    ];
                } else {
                    return $this->storeFileLocally($file, $fileName,
                            $checksumMD5);
                }
            }

            return [
                'success' => false,
                'message' => 'upload_failed',
                'data' => ['message' => 'invalid_file_input']
            ];
        } catch (\Exception $ex) {
            return [
                'success' => false,
                'message' => 'upload_failed',
                'data' => $ex->getMessage() // tránh trả về cả exception object
            ];
        }
    }

    protected function storeRawStreamLocally($stream, string $fileName,
            ?string $checksumMD5 = null)
    {
        $path = storage_path('app/public/profiles');

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $fileName = basename($fileName);

        $tempFile = $path . '/' . bin2hex(random_bytes(16)) . '.temp';
        $out = fopen($tempFile, 'wb');

        $hashContext = hash_init('md5');

        // stream_copy_to_stream($stream, $out);
        $chunkSize = 4 * 1024 * 1024; // 4MB
        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            fwrite($out, $chunk);
            hash_update($hashContext, $chunk);
        }

        fclose($out);

        $etag = hash_final($hashContext);

        if ($checksumMD5 && $etag !== $checksumMD5) {
            unlink($tempFile);
            return [
                'success'=>false,
                'message'=>'checksum_mismatch',
                'data'=>$etag
            ];
        }

        $fullPath = $path.'/'.$fileName;

        rename($tempFile, $fullPath);

        return [
                'success' => true,
                'message' => 'upload_success',
                'data' => [
                    'path' => 'storage/profiles',
                    'file_name' => $fileName,
                    'file_key' => 'storage/profiles/' . $fileName,
                    'storage_path' => 'storage/profiles/' . $fileName,
                    'etag' => $etag
                ]
            ];
    }

    protected function storeRawContentLocally(string $content, string $fileName,
            ?string $checksumMD5 = null)
    {
        try {
            $path = storage_path('app/public/profiles');
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
            $fileName = basename($fileName);

            $fullPath = $path . '/' . $fileName;
            $etag     = '';

            if (!empty($checksumMD5)) {
                $tempFileName = bin2hex(random_bytes(16)) . '.temp';
                $tempFilePath = $path . '/' . $tempFileName;
                if (file_put_contents($tempFilePath, $content) === false) {
                    return [
                        'success' => false,
                        'message' => 'write_failed',
                        'data' => null
                    ];
                }
                $etag = md5_file($tempFilePath);
                if ($etag !== $checksumMD5) {
                    unlink($tempFilePath);
                    return [
                        'success' => false,
                        'message' => 'checksum_mismatch',
                        'data' => $etag
                    ];
                }
                // Rename temp file to final file name
                if (!rename($tempFilePath, $fullPath)) {
                    unlink($tempFilePath);
                    return [
                        'success' => false,
                        'message' => 'rename_failed',
                        'data' => null
                    ];
                }
            } else {
                if (file_put_contents($fullPath, $content) === false) {
                    return [
                        'success' => false,
                        'message' => 'write_failed',
                        'data' => null
                    ];
                }
                $etag = md5_file($fullPath);
            }

            return [
                'success' => true,
                'message' => 'upload_success',
                'data' => [
                    'path' => 'storage/profiles',
                    'file_name' => $fileName,
                    'file_key' => 'storage/profiles/' . $fileName,
                    'storage_path' => 'storage/profiles/' . $fileName,
                    'etag' => $etag
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'upload_failed',
                'data' => $e->getMessage()
            ];
        }
    }

    /**
     * Store file locally
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $fileName
     * @return array
     */
    private function storeFileLocally($file, string $fileName,
            ?string $checksumMD5 = null)
    {
        $fileName = basename($fileName);
        $storedFile = '';
        $etag       = '';

        if (!empty($checksumMD5)) {
            $tempFileName = bin2hex(random_bytes(16)) . '.temp';
            $storedFile = $file->storeAs('public/profiles', $tempFileName);

            $fullPath = storage_path('app/' . $storedFile);
            $etag = md5_file($fullPath);
            if ($etag !== $checksumMD5) {
                Storage::disk('public')->delete('profiles/' . $tempFileName);
                return [
                    'success' => false,
                    'message' => 'checksum_mismatch',
                    'data' => $etag
                ];
            }

             // Rename file to the original file name
             $newStoredFile = 'profiles/' . $fileName;
             Storage::disk('public')->move('profiles/' . $tempFileName,
                    $newStoredFile);

        } else {
            $storedFile = $file->storeAs('public/profiles', $fileName);
            $fullPath = storage_path('app/' . $storedFile);
            $etag = md5_file($fullPath);
        }

        return [
            'success' => true,
            'message' => 'ok',
            'data' => [
                'path' => 'storage/profiles',
                'file_name' => $fileName,
                'file_key' => 'storage/profiles/' . $fileName,
                'storage_path' => 'storage/profiles/' . $fileName,
                'etag' => $etag
            ]
        ];
    }

    /**
     * Configure S3 from database settings
     *
     * @return void
     */
    private function configureS3FromDatabase()
    {
        $s3UploadService = new S3UploadService($this->settingService);
        $s3Key = $this->settingService->getSetting('s3_key')->value ?? '';
        $s3Secret = $this->settingService->getSetting('s3_secret')->value ?? '';
        $s3Bucket = $this->settingService->getSetting('s3_bucket')->value ?? '';
        $s3Region = $this->settingService->getSetting('s3_region')->value ?? '';
        $s3RegionCode = $s3UploadService->getS3RegionCode($this->settingService->getSetting('s3_region')->value ?? '');

        $endpointEnabled = ($this->settingService->getSetting('s3_endpoint_enabled')?->value ?? 'off') === 'on';
        $customEndpoint = trim($this->settingService->getSetting('s3_endpoint')?->value ?? '');

        $isDO = $s3UploadService->getDORegion($s3Region) != null;

        config(['filesystems.disks.s3.key' => $s3Key]);
        config(['filesystems.disks.s3.secret' => $s3Secret]);
        config(['filesystems.disks.s3.bucket' => $s3Bucket]);
        config(['filesystems.disks.s3.region' => $s3RegionCode]);

        // Priority: explicit custom endpoint (e.g. Cloudflare R2) > DigitalOcean Spaces.
        if ($endpointEnabled && $customEndpoint !== '') {
            config(['filesystems.disks.s3.endpoint' => $customEndpoint]);
            config(['filesystems.disks.s3.use_path_style_endpoint' => true]);
        } elseif ($isDO) {
            config(['filesystems.disks.s3.endpoint' => $s3Region]);
        }
        // config(['filesystems.disks.s3.url' => $s3Url]);
    }

    /**
     * Delete file from storage
     *
     * @param string $fileName
     * @return array
     */
    public function deleteFile(string $storage_path)
    {
        try {
            // Initialize settings if needed
            // $this->settingService->initializeDefaultSettings();

            // Get storage type from database
            $storageType = $this->settingService->getSetting('storage_type')->value ?? 'local';

            if ($storageType === 's3') {
                $this->configureS3FromDatabase();
                $s3Bucket = $this->settingService->getSetting('s3_bucket')->value ?? '';
                if (strpos($storage_path, $s3Bucket) === 0) {
                    $storage_path = substr($storage_path, strlen($s3Bucket) + 1);
                }
                Storage::disk('s3')->delete($storage_path);
            } else {
                $relativePath = ltrim(preg_replace('/^storage\//', '', $storage_path));
                Storage::disk('public')->delete($relativePath);
            }

            return [
                'success' => true,
                'message' => 'ok',
                'data' => []
            ];
        } catch (\Exception $ex) {
            return [
                'success' => false,
                'message' => 'Thất bại',
                'data' => $ex->getMessage()
            ];
        }
    }

    public function createDownloadUrl(string $storage_path, $checkFileExists)
    {
        try {
            // Initialize settings if needed
            // $this->settingService->initializeDefaultSettings();

            // Get storage type from database
            $storageType = $this->settingService->getSetting('storage_type')?->value ?? 'local';

            if ($storageType === 's3') {
                if ($checkFileExists == true) {
                    $pathCheckFileExists = $storage_path;
                    $s3Bucket = $this->settingService->getSetting('s3_bucket')?->value ?? '';
                    if (strpos($pathCheckFileExists, $s3Bucket) === 0) {
                        $pathCheckFileExists = substr($pathCheckFileExists, strlen($s3Bucket) + 1);
                    }
                    $this->configureS3FromDatabase();
                    if (!Storage::disk('s3')->exists($pathCheckFileExists)) {
                        return [
                            'success' => false,
                            'message' => 'file_not_found',
                            'data' => null
                        ];
                    }
                }
                $result = $this->s3UploadService->generateDownloadPresignedUrl($storage_path);
            } else {
                $relativePath = ltrim(preg_replace('/^storage\//', '', $storage_path));
                if (!Storage::disk('public')->exists($relativePath)) {
                    return [
                        'success' => false,
                        'message' => 'file_not_found',
                        'data' => null
                    ];
                }
                // $result = url($storage_path);
                $fileName = basename($storage_path);
                $result = url('/api/file/download/' . $fileName);
            }

            return [
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'download_url' => $result,
                    'expires_in' => 50 * 60
                ]
            ];
        } catch (\Exception $ex) {
            return [
                'success' => false,
                'message' => 'error',
                'data' => $ex->getMessage()
            ];
        }
    }

    public function checkFileExists(string $storage_path)
    {
        try {
            $storageType = $this->settingService->getSetting('storage_type')?->value ?? 'local';

            if ($storageType === 's3') {
                $pathCheckFileExists = $storage_path;
                $s3Bucket = $this->settingService->getSetting('s3_bucket')?->value ?? '';
                if (strpos($pathCheckFileExists, $s3Bucket) === 0) {
                    $pathCheckFileExists = substr($pathCheckFileExists, strlen($s3Bucket) + 1);
                }
                $this->configureS3FromDatabase();
                $exists = Storage::disk('s3')->exists($pathCheckFileExists);
            } else {
                $relativePath = ltrim(preg_replace('/^storage\//', '', $storage_path));
                $exists = Storage::disk('public')->exists($relativePath);
            }

            return [
                'success' => true,
                'message' => $exists ? 'file_exists' : 'file_not_found',
                'data' => $exists
            ];
        } catch (\Exception $ex) {
            return [
                'success' => false,
                'message' => 'error',
                'data' => $ex->getMessage()
            ];
        }
    }
}